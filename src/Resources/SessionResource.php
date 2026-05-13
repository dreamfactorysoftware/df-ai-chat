<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Resources;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Providers\AiProviderFactory;
use DreamFactory\Core\AIChat\Exceptions\ChatException;
use DreamFactory\Core\AIChat\Models\AiChatConfig;
use DreamFactory\Core\AIChat\Models\AiChatMessage;
use DreamFactory\Core\AIChat\Models\AiChatSession;
use DreamFactory\Core\AIChat\Services\ChatOrchestrator;
use DreamFactory\Core\AIChat\Services\DataToolClient;
use DreamFactory\Core\AIChat\Services\ToolRegistry;
use DreamFactory\Core\Enums\ServiceRequestorTypes;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\Log;

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

        // Resolve AI service configuration.
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

        // Resolve data services.
        $dataServices = $payload['data_services'] ?? null;
        if ($dataServices === null) {
            $defaults = $serviceConfig['default_data_services'] ?? null;
            if (is_string($defaults)) {
                $dataServices = json_decode($defaults, true);
            } elseif (is_array($defaults)) {
                $dataServices = $defaults;
            }
        }

        if (empty($dataServices) || !is_array($dataServices)) {
            throw new BadRequestException('data_services must be a non-empty array of DreamFactory service names.');
        }

        $allowedResources = $payload['allowed_resources'] ?? null;

        // ── Security validation ──
        $this->validateSessionCreation($aiServiceId, $aiRoleId, $dataServices, $allowedResources);

        // Create the session.
        $session = AiChatSession::create([
            'service_id'        => $this->getServiceId(),
            'ai_service_id'     => $aiServiceId,
            'user_id'           => $userId,
            'user_role_id'      => Session::getRoleId(),
            'ai_role_id'        => $aiRoleId,
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

        // Build the orchestrator.
        $provider = AiProviderFactory::fromServiceId($session->ai_service_id);
        $aiToken = $this->generateAiToken($session->ai_role_id);
        $toolClient = new DataToolClient($aiToken);
        $tools = ToolRegistry::build($session->data_services, $session->allowed_resources);

        $orchestrator = new ChatOrchestrator($provider, $toolClient, $tools, $session);

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
     * Validate that the current user is allowed to create a session
     * with the given AI service and data services.
     *
     * @throws ForbiddenException
     * @throws BadRequestException
     */
    private function validateSessionCreation(
        int $aiServiceId,
        int $aiRoleId,
        array $dataServices,
        ?array $allowedResources,
    ): void {
        // 1. Check the AI role is in the AI service's allowed_roles.
        //    allowed_roles restricts which DreamFactory roles the AI can operate under.
        //    Policy: empty allowed_roles means NO roles are permitted (restrictive default).
        $aiConfig = AiConnectionConfig::whereServiceId($aiServiceId)->first();
        if ($aiConfig) {
            $allowedRoles = $aiConfig->allowed_roles;
            if (is_string($allowedRoles)) {
                $allowedRoles = json_decode($allowedRoles, true);
            }
            if (empty($allowedRoles) || !is_array($allowedRoles)) {
                throw new ForbiddenException(
                    'No roles are configured for this AI service. An admin must assign allowed roles before the AI can access data.'
                );
            }
            // Normalize to ints so admin configs that stored role IDs as JSON
            // strings still match the int $aiRoleId under strict comparison.
            $allowedRoles = array_map('intval', $allowedRoles);
            if (!in_array($aiRoleId, $allowedRoles, true)) {
                throw new ForbiddenException(
                    "The AI role (ID: {$aiRoleId}) is not in this AI service's allowed roles."
                );
            }
        }

        // 2. Validate user has read access to each requested data service.
        $requestorType = ServiceRequestorTypes::API;
        foreach ($dataServices as $svcName) {
            if (!is_string($svcName) || trim($svcName) === '') {
                throw new BadRequestException('Each data_services entry must be a non-empty string.');
            }
            Session::checkServicePermission('GET', $svcName, null, $requestorType);
        }

        // 3. Validate allowed_resources tables are within user's access.
        if (!empty($allowedResources) && is_array($allowedResources)) {
            foreach ($allowedResources as $svcName => $tables) {
                if (!in_array($svcName, $dataServices, true)) {
                    throw new BadRequestException(
                        "allowed_resources references service '{$svcName}' which is not in data_services."
                    );
                }
                if (!is_array($tables)) {
                    throw new BadRequestException(
                        "allowed_resources['{$svcName}'] must be an array of table names."
                    );
                }
                foreach ($tables as $table) {
                    Session::checkServicePermission(
                        'GET',
                        $svcName,
                        "_table/{$table}",
                        $requestorType,
                    );
                }
            }
        }
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

    /**
     * Generate a JWT token for the AI role.
     *
     * Finds a user assigned to the AI role and creates a session token
     * that carries that role's restricted permissions.
     *
     * @throws ChatException
     */
    private function generateAiToken(int $aiRoleId): string
    {
        // Find a user assigned to this role.
        $userAppRole = UserAppRole::where('role_id', $aiRoleId)->first();

        if (!$userAppRole) {
            throw new ChatException(
                "No user is assigned to AI role ID {$aiRoleId}. "
                . 'Create a dedicated user and assign it the AI role.'
            );
        }

        $user = User::find($userAppRole->user_id);
        if (!$user) {
            throw new ChatException(
                "User ID {$userAppRole->user_id} for AI role not found."
            );
        }

        return JWTUtilities::makeJWTByUser($user->id, $user->email);
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
