<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Tests\Unit\Services;

use DreamFactory\Core\AIChat\Services\ChatOrchestrator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChatOrchestrator's pure helpers — the access-control gate
 * and the tool-result truncator. These are the parts of the agentic loop
 * that don't need a database, an AI provider, or a Laravel app to verify.
 *
 * The full sendMessage() loop pulls in Eloquent (AiChatMessage / AiChatSession),
 * the Log facade, and a real provider — exercised by the existing
 * Integration/SessionCreationSmokeTest.sh.
 */
class ChatOrchestratorTest extends TestCase
{
    // ─── checkToolAccess ──────────────────────────────────────────────────

    public function testAccessAllowedWhenServiceInScopeAndNoTable(): void
    {
        // No tableName → only the service-scope check matters.
        $this->assertNull(ChatOrchestrator::checkToolAccess(
            'mysql',
            null,
            ['mysql', 'pgsql'],
            null,
        ));
    }

    public function testAccessRejectedWhenServiceNotInScope(): void
    {
        $err = ChatOrchestrator::checkToolAccess(
            'forbidden_db',
            null,
            ['mysql'],
            null,
        );
        $this->assertNotNull($err);
        $this->assertStringContainsString("'forbidden_db'", $err);
        $this->assertStringContainsString('not available in this chat session', $err);
    }

    public function testAccessRejectedWhenScopeIsEmpty(): void
    {
        // Defensive: a session with no data_services should reject everything.
        $err = ChatOrchestrator::checkToolAccess('mysql', null, [], null);
        $this->assertNotNull($err);
        $this->assertStringContainsString("'mysql'", $err);
    }

    public function testAccessAllowedWhenAllowedResourcesIsNull(): void
    {
        // null allowed_resources means "all tables permitted".
        $this->assertNull(ChatOrchestrator::checkToolAccess(
            'mysql',
            'users',
            ['mysql'],
            null,
        ));
    }

    public function testAccessAllowedWhenServiceNotInAllowedResourcesMap(): void
    {
        // allowed_resources may restrict only some services. A service NOT in
        // the map is unrestricted — we don't extend the restriction implicitly.
        $this->assertNull(ChatOrchestrator::checkToolAccess(
            'mysql',
            'users',
            ['mysql', 'pgsql'],
            ['pgsql' => ['users']], // only pgsql is restricted
        ));
    }

    public function testAccessAllowedWhenTableInAllowedList(): void
    {
        $this->assertNull(ChatOrchestrator::checkToolAccess(
            'mysql',
            'users',
            ['mysql'],
            ['mysql' => ['users', 'orders']],
        ));
    }

    public function testAccessRejectedWhenTableNotInAllowedList(): void
    {
        $err = ChatOrchestrator::checkToolAccess(
            'mysql',
            'admins',
            ['mysql'],
            ['mysql' => ['users', 'orders']],
        );
        $this->assertNotNull($err);
        $this->assertStringContainsString("'admins'", $err);
        $this->assertStringContainsString('not in the allowed resources', $err);
    }

    public function testAccessRejectedWhenAllowedListIsEmpty(): void
    {
        // An empty array for the service means "no tables permitted". The AI
        // should be able to discover this and stop trying.
        $err = ChatOrchestrator::checkToolAccess(
            'mysql',
            'users',
            ['mysql'],
            ['mysql' => []],
        );
        $this->assertNotNull($err);
    }

    public function testAccessUsesStrictTableComparison(): void
    {
        // strict in_array — 'true' (string) must not match true (bool).
        $err = ChatOrchestrator::checkToolAccess(
            'mysql',
            'true',
            ['mysql'],
            ['mysql' => [true]],
        );
        $this->assertNotNull($err, 'strict comparison must reject string-vs-bool match');
    }

    public function testAccessServiceCheckRunsBeforeTableCheck(): void
    {
        // If the service isn't in scope, we never look at allowed_resources
        // — the error message must call out the service, not the table.
        $err = ChatOrchestrator::checkToolAccess(
            'forbidden_db',
            'users',
            ['mysql'],
            ['mysql' => ['users']],
        );
        $this->assertNotNull($err);
        $this->assertStringContainsString("'forbidden_db'", $err);
        $this->assertStringContainsString('not available in this chat session', $err);
    }

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
}
