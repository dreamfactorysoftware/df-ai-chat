<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Services;

use DreamFactory\Core\AI\Providers\AiProviderInterface;
use DreamFactory\Core\AI\Providers\ToolDefinition;
use DreamFactory\Core\AIChat\Exceptions\ChatException;
use DreamFactory\Core\AIChat\Models\AiChatMessage;
use DreamFactory\Core\AIChat\Models\AiChatSession;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the agentic tool-calling loop.
 *
 * Flow:
 *  1. Load session history + tool definitions
 *  2. Send messages + tools to AI provider
 *  3. If AI returns tool_calls → execute each → feed results back → loop
 *  4. If AI returns text → save and return
 */
class ChatOrchestrator
{
    private AiProviderInterface $provider;
    private DataToolClient $toolClient;

    /** @var ToolDefinition[] */
    private array $tools;

    private AiChatSession $session;
    private int $maxIterations;
    private int $maxResultLength;

    public function __construct(
        AiProviderInterface $provider,
        DataToolClient $toolClient,
        array $tools,
        AiChatSession $session,
    ) {
        $this->provider = $provider;
        $this->toolClient = $toolClient;
        $this->tools = $tools;
        $this->session = $session;
        $this->maxIterations = (int) ($session->chatConfig?->max_tool_calls
            ?? config('ai-chat.max_tool_calls_per_message', 25));
        $this->maxResultLength = (int) config('ai-chat.tool_result_max_length', 50000);
    }

    /**
     * Send a user message and run the agentic loop until the AI produces
     * a final text response or the tool-call limit is reached.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, tool_calls_made: int}
     */
    public function sendMessage(string $userMessage): array
    {
        // Load existing history.
        $history = AiChatMessage::where('session_id', $this->session->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Build the messages array for the AI.
        $messages = $this->buildMessages($history, $userMessage);

        // Save the user message.
        $this->saveMessage('user', $userMessage);

        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $toolCallsMade = 0;

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $start = hrtime(true);

            $result = $this->provider->chatWithTools($messages, $this->tools);

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $totalInputTokens += $result['input_tokens'] ?? 0;
            $totalOutputTokens += $result['output_tokens'] ?? 0;

            // No tool calls — AI produced a final text response.
            if (empty($result['tool_calls'])) {
                $content = $result['content'] ?? '';

                $this->saveMessage(
                    'assistant',
                    $content,
                    null,
                    null,
                    null,
                    false,
                    $result['input_tokens'] ?? 0,
                    $result['output_tokens'] ?? 0,
                    $latencyMs,
                );

                $this->updateSessionCounters($totalInputTokens, $totalOutputTokens, $toolCallsMade);

                return [
                    'content'          => $content,
                    'input_tokens'     => $totalInputTokens,
                    'output_tokens'    => $totalOutputTokens,
                    'tool_calls_made'  => $toolCallsMade,
                    'finish_reason'    => $result['finish_reason'] ?? 'stop',
                ];
            }

            // AI wants to call tools.
            $this->saveMessage(
                'assistant',
                $result['content'],
                $result['tool_calls'],
                null,
                null,
                false,
                $result['input_tokens'] ?? 0,
                $result['output_tokens'] ?? 0,
                $latencyMs,
            );

            // Add assistant message (with tool calls) to the conversation.
            $messages[] = $this->formatAssistantToolMessage($result);

            // Execute each tool call.
            foreach ($result['tool_calls'] as $toolCall) {
                $toolCallsMade++;
                $toolResult = $this->executeTool($toolCall);

                // Add tool result to the conversation for the AI.
                $messages[] = $this->formatToolResultMessage($toolCall, $toolResult);

                $this->saveMessage(
                    'tool',
                    $toolResult['content'],
                    null,
                    $toolCall['id'],
                    $toolCall['name'],
                    $toolResult['is_error'],
                    0,
                    0,
                    $toolResult['latency_ms'],
                );
            }
        }

        // Max iterations reached — save a note and return what we have.
        $this->updateSessionCounters($totalInputTokens, $totalOutputTokens, $toolCallsMade);

        throw new ChatException(
            "Maximum tool call limit ({$this->maxIterations}) reached. "
            . 'The AI made too many data queries without producing a final answer.'
        );
    }

    // ────────────────────────────────────────────────────────
    // Tool execution with security validation
    // ────────────────────────────────────────────────────────

    /**
     * Execute a single tool call with security validation.
     *
     * @return array{content: string, is_error: bool, latency_ms: int}
     */
    private function executeTool(array $toolCall): array
    {
        [$serviceName, $toolName] = ToolRegistry::parseToolName($toolCall['name']);

        // Security check: service must be in session scope.
        if (!in_array($serviceName, $this->session->data_services ?? [], true)) {
            return [
                'content'    => "Error: Service '{$serviceName}' is not available in this chat session.",
                'is_error'   => true,
                'latency_ms' => 0,
            ];
        }

        // Security check: table must be in allowed_resources (if set).
        $tableName = $toolCall['arguments']['tableName'] ?? null;
        $allowedResources = $this->session->allowed_resources;
        if ($tableName !== null && $allowedResources !== null && isset($allowedResources[$serviceName])) {
            if (!in_array($tableName, $allowedResources[$serviceName], true)) {
                return [
                    'content'    => "Error: Table '{$tableName}' is not in the allowed resources for this session.",
                    'is_error'   => true,
                    'latency_ms' => 0,
                ];
            }
        }

        $start = hrtime(true);

        try {
            $data = $this->toolClient->executeTool($serviceName, $toolName, $toolCall['arguments']);
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            // Truncate large results to prevent context-window overflow.
            if (strlen($content) > $this->maxResultLength) {
                $content = substr($content, 0, $this->maxResultLength)
                    . "\n...[TRUNCATED at {$this->maxResultLength} chars. Use filter/limit to narrow results.]";
            }

            return [
                'content'    => $content,
                'is_error'   => false,
                'latency_ms' => $latencyMs,
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

            Log::warning('AI Chat tool execution failed', [
                'session_id' => $this->session->id,
                'tool'       => $toolCall['name'],
                'error'      => $e->getMessage(),
            ]);

            return [
                'content'    => 'Tool execution error: ' . $e->getMessage(),
                'is_error'   => true,
                'latency_ms' => $latencyMs,
            ];
        }
    }

    // ────────────────────────────────────────────────────────
    // Message building
    // ────────────────────────────────────────────────────────

    /**
     * Build the full messages array for the AI provider.
     */
    private function buildMessages($history, string $newUserMessage): array
    {
        $messages = [];

        // System prompt.
        $systemPrompt = $this->buildSystemPrompt();
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Replay history.
        foreach ($history as $msg) {
            $messages[] = $this->historyMessageToProviderFormat($msg);
        }

        // New user message.
        $messages[] = ['role' => 'user', 'content' => $newUserMessage];

        return $messages;
    }

    private function buildSystemPrompt(): string
    {
        $prompt = $this->session->system_prompt
            ?? config('ai-chat.default_system_prompt', '');

        $services = implode(', ', $this->session->data_services ?? []);
        $prompt .= "\n\nAvailable data services: {$services}";

        $allowed = $this->session->allowed_resources;
        if (!empty($allowed)) {
            $lines = [];
            foreach ($allowed as $svc => $tables) {
                $lines[] = "{$svc}: " . implode(', ', $tables);
            }
            $prompt .= "\nYou may ONLY access these tables:\n" . implode("\n", $lines);
        }

        $prompt .= "\n\nIMPORTANT: Never access services or tables not listed above. "
            . "Always use LIMIT (default 100) to avoid returning excessively large result sets. "
            . "Use get_tables and get_table_schema to understand the data structure before querying.";

        return trim($prompt);
    }

    /**
     * Convert a stored AiChatMessage to the provider's message format.
     *
     * The exact format depends on the provider (Anthropic vs OpenAI), but
     * the provider's chatWithTools() handles translation internally.
     * We use a normalized format here.
     */
    private function historyMessageToProviderFormat(AiChatMessage $msg): array
    {
        // Tool result messages.
        if ($msg->role === 'tool') {
            return [
                'role'         => 'tool',
                'content'      => $msg->content ?? '',
                'tool_call_id' => $msg->tool_call_id ?? '',
            ];
        }

        // Assistant messages with tool calls.
        if ($msg->role === 'assistant' && !empty($msg->tool_calls)) {
            return [
                'role'       => 'assistant',
                'content'    => $msg->content,
                'tool_calls' => $msg->tool_calls,
            ];
        }

        // Regular text messages (user, assistant, system).
        return [
            'role'    => $msg->role,
            'content' => $msg->content ?? '',
        ];
    }

    /**
     * Format the assistant's tool-call response for the conversation array.
     */
    private function formatAssistantToolMessage(array $result): array
    {
        return [
            'role'       => 'assistant',
            'content'    => $result['content'],
            'tool_calls' => $result['tool_calls'],
        ];
    }

    /**
     * Format a tool result for the conversation array.
     */
    private function formatToolResultMessage(array $toolCall, array $toolResult): array
    {
        return [
            'role'         => 'tool',
            'content'      => $toolResult['content'],
            'tool_call_id' => $toolCall['id'],
        ];
    }

    // ────────────────────────────────────────────────────────
    // Persistence helpers
    // ────────────────────────────────────────────────────────

    private function saveMessage(
        string $role,
        ?string $content,
        ?array $toolCalls = null,
        ?string $toolCallId = null,
        ?string $toolName = null,
        bool $isError = false,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $latencyMs = 0,
    ): AiChatMessage {
        return AiChatMessage::create([
            'session_id'    => $this->session->id,
            'role'          => $role,
            'content'       => $content,
            'tool_calls'    => $toolCalls,
            'tool_call_id'  => $toolCallId,
            'tool_name'     => $toolName,
            'is_error'      => $isError,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms'    => $latencyMs,
            'created_at'    => now(),
        ]);
    }

    private function updateSessionCounters(int $inputTokens, int $outputTokens, int $toolCalls): void
    {
        $this->session->increment('total_input_tokens', $inputTokens);
        $this->session->increment('total_output_tokens', $outputTokens);
        $this->session->increment('tool_call_count', $toolCalls);
    }
}
