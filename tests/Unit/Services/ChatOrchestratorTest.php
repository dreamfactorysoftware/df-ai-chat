<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Tests\Unit\Services;

use DreamFactory\Core\AIChat\Services\ChatOrchestrator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChatOrchestrator's pure helpers.
 *
 * The orchestrator's tool-execution path was refactored when access
 * control moved from a parallel `data_services` allow-list (enforced
 * client-side) to "the role is the only bottleneck" (enforced
 * server-side by DreamFactory's standard RBAC). The previous
 * `checkToolAccess` tests are gone with that refactor — they validated
 * an invariant the architecture no longer holds. Tool-call routing
 * by prefix is covered separately in {@see ToolRegistryTest}.
 *
 * What's left here is `truncateToolResult` — the context-window safety
 * cap, still pure, still used on every tool-call result.
 *
 * The full sendMessage() loop pulls in Eloquent + facades + a real
 * provider — exercised by the existing
 * Integration/SessionCreationSmokeTest.sh.
 */
class ChatOrchestratorTest extends TestCase
{
    // ─── truncateToolResult ───────────────────────────────────────────────

    public function testTruncateLeavesShortContentUnchanged(): void
    {
        $this->assertSame('hello', ChatOrchestrator::truncateToolResult('hello', 100));

        // Exact boundary: at-or-below the limit, content is returned verbatim.
        $exact = str_repeat('x', 100);
        $this->assertSame($exact, ChatOrchestrator::truncateToolResult($exact, 100));
    }

    public function testTruncateClipsAndAppendsMarker(): void
    {
        $content = str_repeat('x', 200);
        $out = ChatOrchestrator::truncateToolResult($content, 50);

        $this->assertStringStartsWith(str_repeat('x', 50), $out);
        $this->assertStringContainsString('TRUNCATED at 50 chars', $out);
        $this->assertStringContainsString('Use filter/limit', $out);
    }

    public function testTruncateMarkerAdvertisesActualLimit(): void
    {
        // The marker bakes the configured cap into the message so the AI can
        // reason about how aggressively to narrow the next query.
        $out = ChatOrchestrator::truncateToolResult(str_repeat('y', 1000), 250);
        $this->assertStringContainsString('TRUNCATED at 250 chars', $out);

        $out = ChatOrchestrator::truncateToolResult(str_repeat('z', 1000), 750);
        $this->assertStringContainsString('TRUNCATED at 750 chars', $out);
    }

    public function testTruncateWithZeroOrNegativeLimitIsNoOp(): void
    {
        // Defensive: a misconfigured cap (0 or negative) shouldn't return an
        // empty string + marker that the AI then can't use. Treat as "no cap".
        $content = str_repeat('x', 200);
        $this->assertSame($content, ChatOrchestrator::truncateToolResult($content, 0));
        $this->assertSame($content, ChatOrchestrator::truncateToolResult($content, -1));
    }

    public function testTruncateHandlesEmptyContent(): void
    {
        $this->assertSame('', ChatOrchestrator::truncateToolResult('', 100));
        $this->assertSame('', ChatOrchestrator::truncateToolResult('', 0));
    }

    public function testUsageResourceConstantIsStable(): void
    {
        // The dashboard's by_resource breakdown keys on this string. Pinning
        // it here means a refactor that renames the constant fails CI loudly
        // rather than silently breaking dashboard charts the day after merge.
        $this->assertSame('chat-session', ChatOrchestrator::USAGE_RESOURCE);
    }
}
