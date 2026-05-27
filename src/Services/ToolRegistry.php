<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Services;

use DreamFactory\Core\AI\Providers\ToolDefinition;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Models\Service;

/**
 * Builds AI-provider tool definitions from the session's data scope.
 *
 * Each data service in the session gets a prefixed set of tools so the AI
 * can query multiple databases within a single conversation.
 */
class ToolRegistry
{
    /**
     * Build tool definitions for all data services in a session.
     *
     * @param string[]    $dataServices      Service names
     * @param array|null  $allowedResources   Optional per-service table restrictions
     * @return ToolDefinition[]
     */
    public static function build(array $dataServices, ?array $allowedResources = null): array
    {
        $tools = [];
        foreach ($dataServices as $serviceName) {
            $allowed = $allowedResources[$serviceName] ?? null;
            $tools = array_merge($tools, self::buildServiceTools($serviceName, $allowed));
        }
        return $tools;
    }

    /**
     * @param string       $svc      Service name (used as tool prefix)
     * @param string[]|null $tables  Allowed table names, null = all
     * @return ToolDefinition[]
     */
    private static function buildServiceTools(string $svc, ?array $tables): array
    {
        $tableNote = $tables !== null
            ? ' Allowed tables: ' . implode(', ', $tables) . '.'
            : '';

        return [
            new ToolDefinition(
                name: "{$svc}__get_tables",
                description: "List all tables available in the '{$svc}' database service.",
                parameters: ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ),
            new ToolDefinition(
                name: "{$svc}__get_table_schema",
                description: "Get the full schema (columns, types, keys) for a table in '{$svc}'.{$tableNote}",
                parameters: [
                    'type'       => 'object',
                    'properties' => [
                        'tableName' => ['type' => 'string', 'description' => 'Table name'],
                    ],
                    'required' => ['tableName'],
                ],
            ),
            new ToolDefinition(
                name: "{$svc}__get_table_data",
                description: "Query data from a table in '{$svc}'. Supports filtering, sorting, pagination, and field selection.{$tableNote}",
                parameters: [
                    'type'       => 'object',
                    'properties' => [
                        'tableName' => ['type' => 'string', 'description' => 'Table name'],
                        'fields'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Columns to return'],
                        'filter'    => ['type' => 'string', 'description' => 'SQL-style filter (e.g. "age > 30 AND city = \'NYC\')'],
                        'order'     => ['type' => 'string', 'description' => 'Sort order (e.g. "created_at DESC")'],
                        'limit'     => ['type' => 'integer', 'description' => 'Max rows to return (default 100)'],
                        'offset'    => ['type' => 'integer', 'description' => 'Rows to skip (for pagination)'],
                        'related'   => ['type' => 'string', 'description' => 'Comma-separated related tables to include'],
                        'include_count' => ['type' => 'boolean', 'description' => 'Include total row count in response'],
                        'count_only'    => ['type' => 'boolean', 'description' => 'Return only the count, no data'],
                    ],
                    'required' => ['tableName'],
                ],
            ),
            new ToolDefinition(
                name: "{$svc}__get_table_fields",
                description: "Get field definitions (column names, types, constraints) for a table in '{$svc}'.{$tableNote}",
                parameters: [
                    'type'       => 'object',
                    'properties' => [
                        'tableName' => ['type' => 'string', 'description' => 'Table name'],
                    ],
                    'required' => ['tableName'],
                ],
            ),
            new ToolDefinition(
                name: "{$svc}__get_table_relationships",
                description: "Get relationship definitions (foreign keys, related tables) for a table in '{$svc}'.{$tableNote}",
                parameters: [
                    'type'       => 'object',
                    'properties' => [
                        'tableName' => ['type' => 'string', 'description' => 'Table name'],
                    ],
                    'required' => ['tableName'],
                ],
            ),
        ];
    }

    /**
     * Build tool definitions from a role's service access grants.
     *
     * **The "role is the only bottleneck" entry point.** Captain's
     * directive: instead of admins maintaining a parallel `data_services`
     * allow-list on the chat service config, we derive the AI's tool
     * surface from whatever the role can actually read. Adding a service
     * to the role's access list makes it available to the AI; removing
     * it removes it. RBAC is RBAC.
     *
     * Service-type → tool-shape mapping:
     *   sql_db, mongodb, snowflake, sqlsrv, etc. (data services)
     *     → standard data tools (get_tables, get_table_schema, get_table_data,
     *       get_table_fields, get_table_relationships)
     *   mcp
     *     → discovered via `tools/list` JSON-RPC at runtime; emitted with
     *       the prefix shape `mcp_{service}__{tool}` so the orchestrator
     *       routes by prefix to McpToolClient
     *   ai_connection, ai_chat, system, swagger, api_docs, user
     *     → excluded (AI shouldn't query itself or admin surfaces)
     *
     * MCP discovery requires a live McpToolClient instance because the
     * tool list is dynamic. Pass null to skip MCP discovery — the data
     * portion is still emitted, useful for admin "what would this role
     * see?" previews where credentials aren't available.
     *
     * @param int $roleId          DreamFactory role id
     * @param McpToolClient|null $mcpClient discover MCP tools when present
     * @return ToolDefinition[]
     */
    public static function buildFromRole(int $roleId, ?McpToolClient $mcpClient = null): array
    {
        if ($roleId <= 0) {
            return [];
        }

        $services = self::resolveAccessibleServices($roleId);
        if (empty($services)) {
            return [];
        }

        $tools = [];
        foreach ($services as $svc) {
            $tools = array_merge(
                $tools,
                self::toolsForService($svc, $mcpClient),
            );
        }
        return $tools;
    }

    /**
     * Resolve the set of services this role can access. Wildcard rows
     * (service_id NULL/0 = "all services") expand to every data + MCP
     * service in the catalog. Explicit per-service rows return just
     * those. AI / admin / docs services are always excluded.
     *
     * @return Service[]
     */
    private static function resolveAccessibleServices(int $roleId): array
    {
        $rows = RoleServiceAccess::where('role_id', $roleId)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $explicitIds = $rows
            ->filter(fn ($r) => $r->service_id !== null && $r->service_id > 0)
            ->pluck('service_id')
            ->unique()
            ->values()
            ->all();

        $hasWildcard = $rows->contains(
            fn ($r) => $r->service_id === null || $r->service_id === 0
        );

        $excludedTypes = self::excludedServiceTypes();

        if ($hasWildcard) {
            return Service::whereNotIn('type', $excludedTypes)
                ->where('is_active', true)
                ->get()
                ->all();
        }

        if (empty($explicitIds)) {
            return [];
        }

        return Service::whereIn('id', $explicitIds)
            ->whereNotIn('type', $excludedTypes)
            ->where('is_active', true)
            ->get()
            ->all();
    }

    /**
     * Service types the AI must never query directly. Includes other AI
     * services (recursion / leakage), the admin surface (system, user),
     * and docs (swagger, api_docs).
     *
     * @return string[]
     */
    private static function excludedServiceTypes(): array
    {
        return [
            'ai_connection', 'ai_chat',
            'system', 'user',
            'swagger', 'api_docs',
        ];
    }

    /**
     * Emit ToolDefinitions for a single service, dispatching by service type.
     *
     * @return ToolDefinition[]
     */
    private static function toolsForService(Service $service, ?McpToolClient $mcpClient): array
    {
        if ($service->type === 'mcp') {
            return $mcpClient !== null
                ? self::buildMcpServiceTools($service->name, $mcpClient)
                : [];
        }

        // Default: assume it's a data service. Data tools work for any
        // service that exposes the standard /_table and /_schema verbs
        // (SQL, NoSQL, custom database services).
        return self::buildServiceTools($service->name, null);
    }

    /**
     * Discover MCP tools for a service via JSON-RPC `tools/list` and
     * emit ToolDefinitions for each. Tool names get the `mcp_` family
     * prefix so the orchestrator routes them to McpToolClient on
     * invocation: "mcp_{svc}__{tool}".
     *
     * @return ToolDefinition[]
     */
    public static function buildMcpServiceTools(string $svc, McpToolClient $mcpClient): array
    {
        try {
            $rawTools = $mcpClient->listTools($svc);
        } catch (\Throwable $e) {
            // MCP discovery failures must not kill chat session creation
            // — degrade gracefully to "no MCP tools available". An admin
            // looking at a chat that doesn't see expected MCP tools can
            // check the daemon health separately.
            return [];
        }

        $tools = [];
        foreach ($rawTools as $raw) {
            if (!is_array($raw) || empty($raw['name'])) {
                continue;
            }
            $tools[] = new ToolDefinition(
                name: "mcp_{$svc}__" . $raw['name'],
                description: (string) ($raw['description'] ?? "MCP tool '{$raw['name']}' on service '{$svc}'."),
                parameters: is_array($raw['inputSchema'] ?? null)
                    ? $raw['inputSchema']
                    : ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            );
        }
        return $tools;
    }

    /**
     * Parse a prefixed tool name into [serviceName, toolName].
     *
     * Tool names use double-underscore as separator: "{service}__{tool}"
     *
     * @return array{0: string, 1: string}
     */
    public static function parseToolName(string $prefixedName): array
    {
        $pos = strpos($prefixedName, '__');
        if ($pos === false) {
            return ['', $prefixedName];
        }
        return [substr($prefixedName, 0, $pos), substr($prefixedName, $pos + 2)];
    }

    /**
     * True if a (prefixed) tool name targets an MCP service. Tool prefix
     * `mcp_` is reserved — data service names cannot start with `mcp_`
     * by DF naming convention.
     */
    public static function isMcpTool(string $prefixedName): bool
    {
        [$svc] = self::parseToolName($prefixedName);
        return str_starts_with($svc, 'mcp_');
    }

    /**
     * Strip the "mcp_" family prefix from a service portion, returning
     * the underlying MCP service name DF knows about. For non-MCP
     * tools, returns the input unchanged.
     */
    public static function unwrapMcpServiceName(string $servicePortion): string
    {
        return str_starts_with($servicePortion, 'mcp_')
            ? substr($servicePortion, 4)
            : $servicePortion;
    }
}
