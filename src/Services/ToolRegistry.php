<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Services;

use DreamFactory\Core\AI\Providers\ToolDefinition;

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
}
