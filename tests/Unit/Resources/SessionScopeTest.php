<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Tests\Unit\Resources;

use DreamFactory\Core\AI\Providers\ToolDefinition;
use DreamFactory\Core\AIChat\Resources\SessionResource;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the conversation scope helpers on SessionResource.
 *
 * A conversation declares an optional capability scope (data services + MCP
 * servers); the AI only ever sees the intersection of that scope and the
 * caller's role. These two static helpers are the scope-normalization and
 * the MCP-filtering half of that contract. Both are private, so they're
 * exercised through reflection — the behavior is the public contract, the
 * visibility is an implementation detail.
 */
class SessionScopeTest extends TestCase
{
    private function invoke(string $method, ...$args)
    {
        $m = new ReflectionMethod(SessionResource::class, $method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    private function tool(string $name): ToolDefinition
    {
        return new ToolDefinition(
            name: $name,
            description: 'test tool',
            parameters: ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        );
    }

    // ── normalizeScopeList ─────────────────────────────────────

    public function testNormalizeAcceptsJsonEncodedString(): void
    {
        $this->assertSame(['a', 'b'], $this->invoke('normalizeScopeList', '["a","b"]'));
    }

    public function testNormalizeAcceptsPlainArray(): void
    {
        $this->assertSame(['x', 'y'], $this->invoke('normalizeScopeList', ['x', 'y']));
    }

    public function testNormalizeReturnsNullForEmptyOrBlank(): void
    {
        // null / empty / blank all collapse to the "no narrowing" sentinel,
        // so a conversation with a blank scope field means "everything the
        // role grants" rather than "nothing".
        $this->assertNull($this->invoke('normalizeScopeList', null));
        $this->assertNull($this->invoke('normalizeScopeList', []));
        $this->assertNull($this->invoke('normalizeScopeList', '   '));
        $this->assertNull($this->invoke('normalizeScopeList', '["", "  "]'));
    }

    public function testNormalizeTrimsAndDropsEmptyEntries(): void
    {
        $this->assertSame(['a', 'b'], $this->invoke('normalizeScopeList', [' a ', '', 'b']));
    }

    // ── filterMcpToolsByScope ──────────────────────────────────

    public function testMcpScopeKeepsDataToolsUntouched(): void
    {
        $tools = [$this->tool('bigquery__get_table_data')];
        $out = $this->invoke('filterMcpToolsByScope', $tools, ['svcA']);
        $this->assertCount(1, $out);
        $this->assertSame('bigquery__get_table_data', $out[0]->name);
    }

    public function testMcpScopeKeepsAllowedAndDropsOthers(): void
    {
        $tools = [
            $this->tool('bigquery__get_table_data'),
            $this->tool('mcp_svcA__run'),
            $this->tool('mcp_svcB__run'),
        ];
        $names = array_map(fn ($t) => $t->name, $this->invoke('filterMcpToolsByScope', $tools, ['svcA']));

        $this->assertContains('bigquery__get_table_data', $names); // data untouched
        $this->assertContains('mcp_svcA__run', $names);            // in scope
        $this->assertNotContains('mcp_svcB__run', $names);         // out of scope
        $this->assertCount(2, $names);
    }
}
