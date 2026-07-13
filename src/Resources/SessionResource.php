<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Resources;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Providers\AiProviderFactory;
use DreamFactory\Core\AIChat\Exceptions\ChatException;
use DreamFactory\Core\AIChat\Models\AiChatConfig;
use DreamFactory\Core\AIChat\Models\AiChatMessage;
use DreamFactory\Core\AIChat\Models\AiChatSession;
use DreamFactory\Core\AIChat\Services\AiAgentCredentials;
use DreamFactory\Core\AIChat\Services\ChatOrchestrator;
use DreamFactory\Core\AIChat\Services\DataToolClient;
use DreamFactory\Core\AIChat\Services\McpToolClient;
use DreamFactory\Core\AIChat\Services\ToolRegistry;
use DreamFactory\Core\Enums\ServiceRequestorTypes;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SessionResource extends BaseRestResource
{
    const RESOURCE_NAME = 'session';

    // ────────────────────────────────────────────────────────
    // GET — List sessions or get a single session
    // ────────────────────────────────────────────────────────

    protected function handleGET()
    {
        $userId = Session::getCurrentUserId();

        if ($this->resource) {
            // GET /session/{id} — single session with messages.
            return $this->getSession((int) $this->resource, $userId);
        }

        // GET /session — list user's sessions.
        return $this->listSessions($userId);
    }

    private function listSessions(?int $userId): array
    {
        $query = AiChatSession::where('service_id', $this->getServiceId());

        // Non-admins see only their own sessions.
        if (!Session::isSysAdmin()) {
            $query->where('user_id', $userId);
        }

        $status = $this->request->getParameter('status', 'active');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $sessions = $query->orderBy('updated_at', 'desc')->get();

        return ['resource' => $sessions->toArray()];
    }

    private function getSession(int $sessionId, ?int $userId): array
    {
        $session = $this->findSession($sessionId, $userId);

        // Clamp upper bound — caller-supplied values were previously cast
        // to int but never bounded, so `?message_limit=10000000` would
        // materialize ten million rows. The hard upper bound is sourced
        // from config so deployments with legitimate large-history needs
        // can raise it without patching code.
        $hardMax = (int) config('ai-chat.message_limit_max', 500);
        $limit = max(1, min($hardMax, (int) $this->request->getParameter('message_limit', 50)));
        $messages = AiChatMessage::where('session_id', $session->id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $result = $session->toArray();
        $result['messages'] = $messages->toArray();

        return $result;
    }

    // ────────────────────────────────────────────────────────
    // POST — Create a session or send a message
    // ────────────────────────────────────────────────────────

    protected function handlePOST()
    {
        $userId = Session::getCurrentUserId();

        if ($this->resource) {
            // POST /session/{id} — send a message.
            return $this->sendMessage((int) $this->resource, $userId);
        }

        // POST /session — create a new session.
        return $this->createSession($userId);
    }

    private function createSession(?int $userId): array
    {
        $payload = $this->getPayloadData();

        /** @var \DreamFactory\Core\AIChat\Services\AiChat $service */
        $service = $this->getService();
        $serviceConfig = $service->getConfig();

        // ── Which model ──
        // ai_service_id picks the AI Connection (provider + model). Required.
        $aiServiceId = (int) ($payload['ai_service_id']
            ?? $serviceConfig['ai_service_id']
            ?? 0);
        if ($aiServiceId === 0) {
            throw new BadRequestException('AI service is not configured. Set ai_service_id in the service config or request payload.');
        }

        // ── Which role (the security boundary) ──
        // A conversation runs under the CALLER's own role — so the AI can
        // never see more than the person talking to it. End users cannot
        // widen or override it. Only a role-less caller (a sysadmin, or a
        // server-to-server API key) falls back to the conversation's
        // configured fallback role (ai_role_id).
        $callerRoleId = (int) Session::getRoleId();
        if (!Session::isSysAdmin()) {
            // End users are locked to their own login role — they can never
            // widen it, and a request-supplied ai_role_id is ignored.
            if ($callerRoleId <= 0) {
                throw new ForbiddenException(
                    'Your account has no role assigned, so an AI chat cannot be started. Contact an administrator.'
                );
            }
            $aiRoleId = $callerRoleId;
        } else {
            // Admins may "act as" any role — down-scoping is never an
            // escalation. Precedence: the explicit act-as role on the request,
            // then the admin's own role (if any), then the service's headless
            // fallback role.
            $aiRoleId = (int) ($payload['ai_role_id']
                ?? ($callerRoleId > 0 ? $callerRoleId : null)
                ?? $serviceConfig['ai_role_id']
                ?? 0);
        }
        if ($aiRoleId === 0) {
            throw new BadRequestException(
                'No role is available for this chat. Sign in as a user with a role, or set a fallback role on the AI Chat service.'
            );
        }

        // ── Security validation ──
        // The AI role must be in the AI Connection's allowed_roles list.
        // No more parallel data_services / allowed_resources allow-lists —
        // the role's own service_access grants are what gate tool calls,
        // enforced at the wire by DreamFactory's standard RBAC pipeline.
        $this->validateAiRoleAllowed($aiServiceId, $aiRoleId);

        // Capability scope for this conversation. Both are optional narrowing
        // lists — resolved from the request, falling back to the service
        // config. Null/empty means "everything the role grants" for that
        // dimension. The AI always sees the INTERSECTION of these lists and
        // the caller's role: they can only narrow, never widen.
        $dataServices = self::normalizeScopeList(
            $payload['data_services'] ?? $serviceConfig['default_data_services'] ?? null
        );
        $mcpServers = self::normalizeScopeList(
            $payload['mcp_servers'] ?? $serviceConfig['mcp_servers'] ?? null
        );
        $allowedResources = $payload['allowed_resources'] ?? null;

        // Create the session.
        $session = AiChatSession::create([
            'service_id'        => $this->getServiceId(),
            'ai_service_id'     => $aiServiceId,
            'user_id'           => $userId,
            'user_role_id'      => Session::getRoleId(),
            'ai_role_id'        => $aiRoleId,
            // data_services on the session is now an OVERRIDE only. When
            // null/empty, sendMessage() derives the tool surface from the
            // role's service_access. We persist the override so a session
            // started with a narrowed scope keeps that scope on follow-up
            // messages, even if the role's access changes mid-session.
            'data_services'     => $dataServices,
            'mcp_servers'       => $mcpServers,
            'allowed_resources' => $allowedResources,
            'title'             => $payload['title'] ?? null,
            'system_prompt'     => $payload['system_prompt'] ?? $serviceConfig['system_prompt'] ?? null,
            'status'            => 'active',
            'total_input_tokens'  => 0,
            'total_output_tokens' => 0,
            'tool_call_count'     => 0,
        ]);

        return $session->toArray();
    }

    private function sendMessage(int $sessionId, ?int $userId): array
    {
        $payload = $this->getPayloadData();
        $message = $payload['message'] ?? null;

        if (!is_string($message) || trim($message) === '') {
            throw new BadRequestException('"message" must be a non-empty string.');
        }

        $session = $this->findSession($sessionId, $userId);

        if ($session->status !== 'active') {
            throw new BadRequestException('This session is no longer active.');
        }

        // ── Role lifecycle re-check (the security boundary can drift) ──
        // ai_role_id is frozen at session creation. For a non-sysadmin
        // caller the conversation MUST keep running under the caller's
        // CURRENT login role — if their role was changed or revoked since
        // the session started, the frozen role may now grant access the
        // caller no longer holds. Re-assert equality on every message and
        // refuse if it drifted. Sysadmins are exempt: they legitimately
        // "act as" any role (down-scoping is never an escalation).
        if (!Session::isSysAdmin()) {
            $currentRoleId = (int) Session::getRoleId();
            if ($currentRoleId <= 0 || (int) $session->ai_role_id !== $currentRoleId) {
                throw new ForbiddenException(
                    'Your role has changed since this chat session was created, '
                    . 'so it can no longer be used. Please start a new chat session.'
                );
            }
        }

        // Check message count limit.
        $maxMessages = $session->chatConfig?->max_messages
            ?? config('ai-chat.max_messages_per_session', 200);
        $currentCount = AiChatMessage::where('session_id', $session->id)->count();
        if ($currentCount >= (int) $maxMessages) {
            throw new ChatException("Session message limit ({$maxMessages}) reached.");
        }

        // Build the orchestrator. The AI agent's credentials (JWT + the
        // role-bound app's API key) are auto-provisioned on first use,
        // then the same row is reused for the lifetime of the role.
        $provider = AiProviderFactory::fromServiceId($session->ai_service_id);
        $creds = AiAgentCredentials::resolve($session->ai_role_id);
        $toolClient = new DataToolClient($creds['token'], $creds['api_key']);
        $mcpClient  = new McpToolClient($creds['token'], $creds['api_key']);

        // Tool surface is driven by the role's own service_access grants —
        // every data + MCP service the role can read becomes a tool the
        // AI sees. If a session was created with a narrower override
        // (`data_services` set), respect it — the override is enforced
        // server-side by RBAC anyway, this just keeps the AI from
        // wasting tool-call iterations on resources it can't reach.
        $tools = ToolRegistry::buildFromRole($session->ai_role_id, $mcpClient);
        if (is_array($session->data_services) && !empty($session->data_services)) {
            $tools = self::filterToolsByOverride($tools, $session->data_services);
        }
        // Explicit MCP scope: when the conversation names its MCP server(s),
        // drop MCP tools from any server not on the list. Data tools are
        // untouched. Empty/null means "every MCP server the role grants".
        if (is_array($session->mcp_servers) && !empty($session->mcp_servers)) {
            $tools = self::filterMcpToolsByScope($tools, $session->mcp_servers);
        }

        $orchestrator = new ChatOrchestrator($provider, $toolClient, $tools, $session, $mcpClient);

        try {
            $result = $orchestrator->sendMessage(trim($message));
        } catch (ChatException $e) {
            return [
                'content'         => null,
                'error'           => $e->getMessage(),
                'input_tokens'    => 0,
                'output_tokens'   => 0,
                'tool_calls_made' => 0,
            ];
        }

        // Auto-title on first message if no title set.
        if ($session->title === null) {
            $session->update([
                'title' => mb_substr(trim($message), 0, 100),
            ]);
        }

        return $result;
    }

    // ────────────────────────────────────────────────────────
    // DELETE — Delete / archive a session
    // ────────────────────────────────────────────────────────

    protected function handleDELETE()
    {
        $userId = Session::getCurrentUserId();

        if (!$this->resource) {
            throw new BadRequestException('Session ID is required.');
        }

        $session = $this->findSession((int) $this->resource, $userId);

        // Delete messages first, then session.
        AiChatMessage::where('session_id', $session->id)->delete();
        $session->delete();

        return ['success' => true];
    }

    // ────────────────────────────────────────────────────────
    // Security validation
    // ────────────────────────────────────────────────────────

    /**
     * Validate that the AI role is allowed to operate under the given
     * AI Connection — i.e. the role appears in the AI Connection's
     * `allowed_roles` list. This is the ONLY pre-creation check; the
     * actual tool-call RBAC happens at the wire on every dispatch.
     *
     * @throws ForbiddenException
     */
    private function validateAiRoleAllowed(int $aiServiceId, int $aiRoleId): void
    {
        $aiConfig = AiConnectionConfig::whereServiceId($aiServiceId)->first();
        if (!$aiConfig) {
            return; // No config row yet — nothing to validate against.
        }

        $allowedRoles = $aiConfig->allowed_roles;
        if (is_string($allowedRoles)) {
            $allowedRoles = json_decode($allowedRoles, true);
        }
        if (empty($allowedRoles) || !is_array($allowedRoles)) {
            throw new ForbiddenException(
                'No roles are configured for this AI Connection. An admin must add roles to its "Allowed Roles" list before chats can use it.'
            );
        }
        // Normalize to ints so admin configs that stored role IDs as JSON
        // strings still match the int $aiRoleId under strict comparison.
        $allowedRoles = array_map('intval', $allowedRoles);
        if (!in_array($aiRoleId, $allowedRoles, true)) {
            throw new ForbiddenException(
                "The AI role (ID: {$aiRoleId}) is not in this AI Connection's allowed roles. "
                . 'Add it via the AI Connection edit page → "Allowed Roles".'
            );
        }
    }

    /**
     * Apply a per-session `data_services` override on top of role-derived
     * tools. Keeps only tools whose service portion is in the override
     * list. The override is also enforced at the wire by RBAC (the role
     * is the bottleneck), so this is a UX optimization — stops the AI
     * from wasting tool-call iterations on services the session was
     * deliberately scoped away from.
     *
     * Note: MCP tools use `mcp_<svc>` as their service prefix, so to keep
     * MCP services in scope when overriding data services, the override
     * list must include the full `mcp_<svc>` form. By default (no
     * override) MCP tools are always surfaced when the role grants access.
     *
     * @param \DreamFactory\Core\AI\Providers\ToolDefinition[] $tools
     * @param string[] $allowed
     * @return \DreamFactory\Core\AI\Providers\ToolDefinition[]
     */
    private static function filterToolsByOverride(array $tools, array $allowed): array
    {
        return array_values(array_filter(
            $tools,
            function ($tool) use ($allowed) {
                [$svc] = ToolRegistry::parseToolName($tool->name);
                return in_array($svc, $allowed, true);
            },
        ));
    }

    /**
     * Keep only MCP tools whose underlying MCP service is in the
     * conversation's `mcp_servers` scope. Non-MCP (data) tools pass through
     * untouched. Like the data override, the caller's role is still the
     * hard bottleneck at the wire — this just stops the AI from being
     * offered MCP tools the conversation was deliberately scoped away from.
     *
     * @param \DreamFactory\Core\AI\Providers\ToolDefinition[] $tools
     * @param string[] $allowedMcp  MCP service names (unprefixed)
     * @return \DreamFactory\Core\AI\Providers\ToolDefinition[]
     */
    private static function filterMcpToolsByScope(array $tools, array $allowedMcp): array
    {
        return array_values(array_filter(
            $tools,
            function ($tool) use ($allowedMcp) {
                if (!ToolRegistry::isMcpTool($tool->name)) {
                    return true; // data tools are unaffected by MCP scope
                }
                [$svc] = ToolRegistry::parseToolName($tool->name);
                $mcpService = ToolRegistry::unwrapMcpServiceName($svc);
                return in_array($mcpService, $allowedMcp, true);
            },
        ));
    }

    /**
     * Normalize a scope list (data services or MCP servers) coming from a
     * request payload or a service-config field. Accepts a real array or a
     * JSON-encoded string. Returns a clean list of non-empty string names,
     * or null when there is no usable scope (the "no narrowing" sentinel).
     *
     * @param mixed $value
     * @return string[]|null
     */
    private static function normalizeScopeList($value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($value) || empty($value)) {
            return null;
        }
        $clean = array_values(array_filter(
            array_map(fn ($v) => is_string($v) ? trim($v) : '', $value),
            fn ($v) => $v !== '',
        ));
        return empty($clean) ? null : $clean;
    }

    // ────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────

    /**
     * Find a session, ensuring ownership unless admin.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    private function findSession(int $sessionId, ?int $userId): AiChatSession
    {
        $session = AiChatSession::where('id', $sessionId)
            ->where('service_id', $this->getServiceId())
            ->first();

        if (!$session) {
            throw new NotFoundException("Session {$sessionId} not found.");
        }

        // Non-admins can only access their own sessions.
        if (!Session::isSysAdmin() && $session->user_id !== $userId) {
            throw new ForbiddenException('You do not have access to this session.');
        }

        return $session;
    }

    // ────────────────────────────────────────────────────────
    // API Documentation
    // ────────────────────────────────────────────────────────

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);

        return [
            '/session' => [
                'get' => [
                    'summary'     => 'List chat sessions.',
                    'description' => 'Returns all active chat sessions for the current user.',
                    'operationId' => 'get' . $capitalized . 'Sessions',
                    'parameters'  => [
                        [
                            'name'        => 'status',
                            'in'          => 'query',
                            'schema'      => ['type' => 'string', 'default' => 'active'],
                            'description' => 'Filter by status (active, archived, all).',
                        ],
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/SessionListResponse'],
                    ],
                ],
                'post' => [
                    'summary'     => 'Create a new chat session.',
                    'operationId' => 'create' . $capitalized . 'Session',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/CreateSessionRequest',
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/SessionResponse'],
                    ],
                ],
            ],
            '/session/{id}' => [
                'get' => [
                    'summary'     => 'Get session detail with messages.',
                    'operationId' => 'get' . $capitalized . 'Session',
                    'parameters'  => [
                        [
                            'name'     => 'id',
                            'in'       => 'path',
                            'required' => true,
                            'schema'   => ['type' => 'integer'],
                        ],
                        [
                            'name'        => 'message_limit',
                            'in'          => 'query',
                            'schema'      => ['type' => 'integer', 'default' => 50],
                            'description' => 'Max messages to return.',
                        ],
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/SessionDetailResponse'],
                    ],
                ],
                'post' => [
                    'summary'     => 'Send a message to the chat session.',
                    'description' => 'Sends a user message, triggering the AI agentic loop. Returns the AI response.',
                    'operationId' => 'send' . $capitalized . 'Message',
                    'parameters'  => [
                        [
                            'name'     => 'id',
                            'in'       => 'path',
                            'required' => true,
                            'schema'   => ['type' => 'integer'],
                        ],
                    ],
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SendMessageRequest',
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/MessageResponse'],
                    ],
                ],
                'delete' => [
                    'summary'     => 'Delete a chat session.',
                    'operationId' => 'delete' . $capitalized . 'Session',
                    'parameters'  => [
                        [
                            'name'     => 'id',
                            'in'       => 'path',
                            'required' => true,
                            'schema'   => ['type' => 'integer'],
                        ],
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/Success'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocRequests()
    {
        return [
            'CreateSessionRequest' => [
                'description' => 'Create a chat session',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/CreateSessionRequest'],
                    ],
                ],
            ],
            'SendMessageRequest' => [
                'description' => 'Send a message',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SendMessageRequest'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'SessionListResponse' => [
                'description' => 'List of sessions',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'resource' => [
                                    'type'  => 'array',
                                    'items' => ['$ref' => '#/components/schemas/Session'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'SessionResponse' => [
                'description' => 'Session created',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/Session'],
                    ],
                ],
            ],
            'SessionDetailResponse' => [
                'description' => 'Session with messages',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SessionDetail'],
                    ],
                ],
            ],
            'MessageResponse' => [
                'description' => 'AI response',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/MessageResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        return [
            'CreateSessionRequest' => [
                'type'     => 'object',
                'properties' => [
                    'data_services' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Optional. Data service names this conversation may query; intersected with the caller\'s role. Omit to use every data service the role grants.',
                        'example'     => ['dellstore_db', 'hr_db'],
                    ],
                    'mcp_servers' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Optional. MCP service names this conversation may call as tools; intersected with the caller\'s role. Omit to use every MCP server the role grants.',
                        'example'     => ['sysco_mcp'],
                    ],
                    'allowed_resources' => [
                        'type'        => 'object',
                        'description' => 'Optional per-service table restrictions.',
                        'example'     => ['dellstore_db' => ['customers', 'orders']],
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Optional session title.',
                    ],
                    'system_prompt' => [
                        'type'        => 'string',
                        'description' => 'Optional system prompt override.',
                    ],
                ],
            ],
            'SendMessageRequest' => [
                'type'     => 'object',
                'required' => ['message'],
                'properties' => [
                    'message' => [
                        'type'        => 'string',
                        'description' => 'User message to send.',
                        'example'     => 'What tables are available?',
                    ],
                ],
            ],
            'Session' => [
                'type' => 'object',
                'properties' => [
                    'id'                  => ['type' => 'integer'],
                    'service_id'          => ['type' => 'integer'],
                    'user_id'             => ['type' => 'integer'],
                    'data_services'       => ['type' => 'array', 'items' => ['type' => 'string']],
                    'mcp_servers'         => ['type' => 'array', 'items' => ['type' => 'string']],
                    'allowed_resources'   => ['type' => 'object'],
                    'title'               => ['type' => 'string'],
                    'status'              => ['type' => 'string'],
                    'total_input_tokens'  => ['type' => 'integer'],
                    'total_output_tokens' => ['type' => 'integer'],
                    'tool_call_count'     => ['type' => 'integer'],
                    'created_at'          => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at'          => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'SessionDetail' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/Session'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'messages' => [
                                'type'  => 'array',
                                'items' => ['$ref' => '#/components/schemas/ChatMessage'],
                            ],
                        ],
                    ],
                ],
            ],
            'ChatMessage' => [
                'type' => 'object',
                'properties' => [
                    'id'            => ['type' => 'integer'],
                    'role'          => ['type' => 'string', 'enum' => ['user', 'assistant', 'tool', 'system']],
                    'content'       => ['type' => 'string'],
                    'tool_calls'    => ['type' => 'array'],
                    'tool_name'     => ['type' => 'string'],
                    'is_error'      => ['type' => 'boolean'],
                    'input_tokens'  => ['type' => 'integer'],
                    'output_tokens' => ['type' => 'integer'],
                    'latency_ms'    => ['type' => 'integer'],
                    'created_at'    => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'MessageResponse' => [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type'        => 'string',
                        'description' => 'The AI-generated response text.',
                    ],
                    'input_tokens' => [
                        'type'        => 'integer',
                        'description' => 'Total input tokens used.',
                    ],
                    'output_tokens' => [
                        'type'        => 'integer',
                        'description' => 'Total output tokens generated.',
                    ],
                    'tool_calls_made' => [
                        'type'        => 'integer',
                        'description' => 'Number of tool calls made.',
                    ],
                    'finish_reason' => [
                        'type'        => 'string',
                        'description' => 'Reason the AI stopped generating.',
                    ],
                ],
            ],
        ];
    }
}
