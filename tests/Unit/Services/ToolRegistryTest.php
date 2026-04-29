<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Tests\Unit\Services;

use DreamFactory\Core\AI\Providers\ToolDefinition;
use DreamFactory\Core\AIChat\Services\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ToolRegistry — the static helper that translates a session's
 * data-service scope into the tool-definition list the AI sees, and parses
 * the resulting prefixed tool names back into (service, tool) pairs.
 *
 * The wire contract here matters: a typo in the prefix delimiter would
 * silently fail every tool call in production, so the format is pinned down
 * with explicit assertions rather than fuzzy "starts with" checks.
 */
class ToolRegistryTest extends TestCase
{
    public function testParseToolNameSplitsOnDoubleUnderscore(): void
    {
        $this->assertSame(
            ['mysql', 'get_tables'],
            ToolRegistry::parseToolName('mysql__get_tables')
        );
        $this->assertSame(
            ['analytics_db', 'get_table_data'],
            ToolRegistry::parseToolName('analytics_db__get_table_data')
        );
    }

    public function testParseToolNameSplitsOnFirstDelimiterOnly(): void
    {
        // A service named "weird__name" with a tool "get_tables" produces
        // "weird__name__get_tables". parseToolName must split on the FIRST
        // "__" so it doesn't reassemble the tool name with the service tail.
        $this->assertSame(
            ['weird', 'name__get_tables'],
            ToolRegistry::parseToolName('weird__name__get_tables')
        );
    }

    public function testParseToolNameWithNoDelimiterReturnsEmptyServicePrefix(): void
    {
        // Defensive: a malformed tool name (no "__") shouldn't crash; return
        // an empty service prefix so the security check downstream rejects it.
        $this->assertSame(['', 'broken-tool-name'], ToolRegistry::parseToolName('broken-tool-name'));
        $this->assertSame(['', ''], ToolRegistry::parseToolName(''));
    }

    public function testParseToolNameHandlesLeadingDelimiter(): void
    {
        // "__get_tables" — empty service, get_tables tool.
        $this->assertSame(['', 'get_tables'], ToolRegistry::parseToolName('__get_tables'));
    }

    public function testParseToolNameHandlesTrailingDelimiter(): void
    {
        $this->assertSame(['svc', ''], ToolRegistry::parseToolName('svc__'));
    }

    public function testBuildEmittedFiveToolsPerService(): void
    {
        // The current contract: each service gets get_tables, get_table_schema,
        // get_table_data, get_table_fields, get_table_relationships.
        $tools = ToolRegistry::build(['mysql']);
        $this->assertCount(5, $tools);

        // Two services → ten tools.
        $tools = ToolRegistry::build(['mysql', 'pgsql']);
        $this->assertCount(10, $tools);

        // Empty list → no tools.
        $this->assertSame([], ToolRegistry::build([]));
    }

    public function testBuildAllToolsHaveServicePrefix(): void
    {
        $tools = ToolRegistry::build(['analytics']);
        $names = array_map(fn(ToolDefinition $t) => $t->name, $tools);

        // Every emitted tool's name must round-trip through parseToolName
        // back to the same service. Pins the wire format.
        foreach ($names as $name) {
            [$svc, $tool] = ToolRegistry::parseToolName($name);
            $this->assertSame('analytics', $svc, "tool '{$name}' should be prefixed with the service");
            $this->assertNotSame('', $tool, "tool '{$name}' should have a non-empty tool suffix");
        }

        // The five canonical suffixes must be present (in some order).
        $suffixes = array_map(
            fn(string $n) => ToolRegistry::parseToolName($n)[1],
            $names
        );
        sort($suffixes);
        $this->assertSame(
            ['get_table_data', 'get_table_fields', 'get_table_relationships', 'get_table_schema', 'get_tables'],
            $suffixes
        );
    }

    public function testBuildEmitsToolDefinitionInstances(): void
    {
        $tools = ToolRegistry::build(['mysql']);
        foreach ($tools as $tool) {
            $this->assertInstanceOf(ToolDefinition::class, $tool);
            $this->assertNotEmpty($tool->description, 'every tool needs a description for the AI to choose it');
        }
    }

    public function testBuildIncludesAllowedTableHintInDescription(): void
    {
        $tools = ToolRegistry::build(['mysql'], ['mysql' => ['users', 'orders']]);
        $byName = self::indexByName($tools);

        // The schema/data tools should mention the allow-list so the AI
        // doesn't waste tool calls trying tables it can't reach.
        $this->assertStringContainsString(
            'Allowed tables: users, orders',
            $byName['mysql__get_table_schema']->description
        );
        $this->assertStringContainsString(
            'Allowed tables: users, orders',
            $byName['mysql__get_table_data']->description
        );
        $this->assertStringContainsString(
            'Allowed tables: users, orders',
            $byName['mysql__get_table_fields']->description
        );
        $this->assertStringContainsString(
            'Allowed tables: users, orders',
            $byName['mysql__get_table_relationships']->description
        );

        // get_tables doesn't take a tableName arg, so the hint is unnecessary.
        $this->assertStringNotContainsString(
            'Allowed tables',
            $byName['mysql__get_tables']->description
        );
    }

    public function testBuildOmitsAllowedTableHintWhenAllPermitted(): void
    {
        // No allowed_resources entry for the service → all tables permitted →
        // no "Allowed tables: ..." sentence in the descriptions.
        $tools = ToolRegistry::build(['mysql']);
        foreach ($tools as $tool) {
            $this->assertStringNotContainsString(
                'Allowed tables',
                $tool->description,
                "tool '{$tool->name}' must not advertise an allow-list when none is set"
            );
        }
    }

    public function testBuildPerServiceAllowListsApplyIndependently(): void
    {
        // Service A gets a restricted list, service B is open.
        $tools = ToolRegistry::build(
            ['mysql', 'pgsql'],
            ['mysql' => ['users']]
        );
        $byName = self::indexByName($tools);

        $this->assertStringContainsString(
            'Allowed tables: users',
            $byName['mysql__get_table_schema']->description
        );
        $this->assertStringNotContainsString(
            'Allowed tables',
            $byName['pgsql__get_table_schema']->description
        );
    }

    public function testBuildToolParametersDeclareTableNameRequired(): void
    {
        // The schema-targeted tools must declare tableName as a required
        // parameter — without this the AI may call them without arguments
        // and the security check downstream gets nothing to validate.
        $tools = ToolRegistry::build(['mysql']);
        $byName = self::indexByName($tools);

        foreach (['mysql__get_table_schema', 'mysql__get_table_data', 'mysql__get_table_fields', 'mysql__get_table_relationships'] as $name) {
            $params = $byName[$name]->parameters;
            $this->assertSame(['tableName'], $params['required'] ?? null, "{$name} must require tableName");
        }
    }

    /**
     * @param ToolDefinition[] $tools
     * @return array<string, ToolDefinition>
     */
    private static function indexByName(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $out[$t->name] = $t;
        }
        return $out;
    }
}
