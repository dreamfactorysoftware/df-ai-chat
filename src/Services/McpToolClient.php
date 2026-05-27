<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Services;

use DreamFactory\Core\AIChat\Exceptions\ChatException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Routes tool calls to MCP services on this DreamFactory instance.
 *
 * MCP services in DF expose a JSON-RPC endpoint at
 * /api/v2/{mcp_service}/rpc that speaks the standard MCP protocol
 * (`tools/list`, `tools/call`, `prompts/list`, etc.). When a chat's
 * configured role has access to an MCP service, the orchestrator can
 * surface that service's tools to the AI; this client is what dispatches
 * those calls.
 *
 * Auth shape mirrors {@see DataToolClient}: every request carries the
 * AI agent's JWT (X-DreamFactory-Session-Token) AND the role-bound app's
 * API key (X-DreamFactory-API-Key) so DF's RBAC pipeline runs under the
 * configured ai_role_id.
 *
 * Only one MCP method matters for the chat tool-call loop today:
 * `tools/call` — given a tool name + arguments, invoke and return the
 * tool's result. `tools/list` is used at session-build time by
 * {@see ToolRegistry::buildFromRole()} to discover what to surface to
 * the AI.
 */
class McpToolClient
{
    private Client $client;
    private string $baseApiUrl;

    public function __construct(
        private readonly string $sessionToken,
        private readonly string $apiKey,
    ) {
        // Reuse the DataToolClient's resolution rule so both clients agree
        // on the loopback URL (and whichever override admins set applies
        // to both transparently).
        $this->baseApiUrl = rtrim(DataToolClient::resolveInternalApiUrl(), '/');

        $this->client = new Client([
            'timeout' => 60,
            'headers' => [
                'Accept'                       => 'application/json',
                'Content-Type'                 => 'application/json',
                'X-DreamFactory-Session-Token' => $this->sessionToken,
                'X-DreamFactory-API-Key'       => $this->apiKey,
            ],
        ]);
    }

    /**
     * List the tools an MCP service exposes. Used at session-build time
     * to populate the AI's tool surface.
     *
     * @param string $mcpServiceName MCP service name as registered in DF
     * @return array<int, array{name: string, description?: string, inputSchema?: array}>
     */
    public function listTools(string $mcpServiceName): array
    {
        $response = $this->rpc($mcpServiceName, 'tools/list', []);
        $tools = $response['tools'] ?? [];
        return is_array($tools) ? $tools : [];
    }

    /**
     * Invoke a tool on an MCP service.
     *
     * @param string $mcpServiceName MCP service name as registered in DF
     * @param string $toolName       MCP-side tool name (without prefix)
     * @param array  $arguments      Tool arguments
     * @return array Result payload — shape varies per tool
     */
    public function callTool(string $mcpServiceName, string $toolName, array $arguments): array
    {
        return $this->rpc($mcpServiceName, 'tools/call', [
            'name'      => $toolName,
            'arguments' => (object) $arguments,
        ]);
    }

    /**
     * Send a JSON-RPC request to an MCP service's /rpc endpoint and
     * return the `result` portion. Errors are normalized into
     * ChatException so the orchestrator's tool-loop catches them.
     *
     * @throws ChatException
     */
    private function rpc(string $mcpServiceName, string $method, array $params): array
    {
        $body = [
            'jsonrpc' => '2.0',
            'id'      => uniqid('mcp_', true),
            'method'  => $method,
            'params'  => (object) $params,
        ];

        try {
            $response = $this->client->request(
                'POST',
                "{$this->baseApiUrl}/{$mcpServiceName}/rpc",
                ['json' => $body],
            );
            $decoded = json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new ChatException(
                "MCP RPC '{$method}' to '{$mcpServiceName}' failed: " . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new ChatException("MCP RPC '{$method}' to '{$mcpServiceName}' returned non-JSON.");
        }

        if (isset($decoded['error'])) {
            $msg = is_array($decoded['error'])
                ? ($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string) $decoded['error'];
            throw new ChatException("MCP RPC '{$method}' to '{$mcpServiceName}' error: {$msg}");
        }

        $result = $decoded['result'] ?? [];
        return is_array($result) ? $result : [];
    }
}
