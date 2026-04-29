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

        $limit = (int) $this->request->getParameter('message_limit', 50);
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

        // Resolve AI service configuration. The two REQUIRED knobs:
        //   ai_service_id  — which AI Connection (Anthropic / OpenAI / etc.)
        //   ai_role_id     — the DreamFactory role the AI operates under
        //                    (the only access bottleneck for tool calls)
        $aiServiceId = (int) ($payload['ai_service_id']
            ?? $serviceConfig['ai_service_id']
            ?? 0);
        $aiRoleId = (int) ($payload['ai_role_id']
            ?? $serviceConfig['ai_role_id']
            ?? 0);

        if ($aiServiceId === 0) {
            throw new BadRequestException('AI service is not configured. Set ai_service_id in the service config or request payload.');
        }
        if ($aiRoleId === 0) {
            throw new BadRequestException('AI role is not configured. Set ai_role_id in the service config or request payload.');
        }

        // ── Security validation ──
        // The AI role must be in the AI Connection's allowed_roles list.
        // No more parallel data_services / allowed_resources allow-lists —
        // the role's own service_access grants are what gate tool calls,
        // enforced at the wire by DreamFactory's standard RBAC pipeline.
        $this->validateAiRoleAllowed($aiServiceId, $aiRoleId);

        // Optional override surface — admins or session-creating SDKs can
        // STILL pass narrowed `data_services` / `allowed_resources` if they
        // want to scope a single session tighter than the role allows.
        // Empty/null means "use everything the role can read" (the default).
        $dataServices = $payload['data_services'] ?? null;
        if (!is_array($dataServices) || empty($dataServices)) {
            $dataServices = null; // sentinel: "no override, derive from role"
        }
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
        if (!in_array($aiRoleId, $allowedRoles, false)) {
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
                'required' => ['data_services'],
                'properties' => [
                    'data_services' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'DreamFactory service names the AI can access.',
                        'example'     => ['dellstore_db', 'hr_db'],
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
