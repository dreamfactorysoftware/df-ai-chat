<?php

namespace DreamFactory\Core\AiChat\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: SessionResource::getSession() must clamp `message_limit`.
 *
 * The April 2026 audit (df-ai-chat FINDING-04) found:
 *
 *     $limit = (int) $this->request->getParameter('message_limit', 50);
 *     ... ->limit($limit) ->get()
 *
 * The caller-supplied value is cast to int but never clamped — passing
 * `?message_limit=10000000` issues a SELECT for ten million rows.
 * Combined with the chat history's reverse() materialization, a small
 * number of requests can exhaust memory or wedge the DB.
 *
 * After the fix, `message_limit` is clamped to a defined maximum before
 * being passed to the query builder.
 */
class MessageLimitClampTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Resources/SessionResource.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testMessageLimitIsClamped(): void
    {
        // The fix should apply min()/max() (or equivalent) to the integer
        // pulled from the request before it reaches the query builder.
        // We require min(...) since clamping the upper bound is what
        // matters for resource exhaustion.
        $hasMinClamp = preg_match(
            '/min\s*\(\s*(?:[^,)]*\$?\w*[Mm]essage[Ll]imit\b|\$?\w*[Mm]essage[Ll]imit\b[^,)]*)\s*,/',
            $this->contents
        ) === 1;
        $hasGenericMinClampOnLimit = preg_match(
            '/\$\w*[Ll]imit\s*=\s*(?:max\s*\([^,]+,\s*)?min\s*\(/',
            $this->contents
        ) === 1;

        $this->assertTrue(
            $hasMinClamp || $hasGenericMinClampOnLimit,
            'message_limit must be clamped via min() before reaching the query builder'
        );
    }

    public function testMessageLimitMaximumIsSane(): void
    {
        // The fix should declare an upper bound. We don't insist on a
        // specific value — anything <= 1000 is reasonable for a chat
        // history view; anything >= 100000 would be a non-fix.
        $matches = [];
        $found = preg_match(
            '/\$\w*[Ll]imit\s*=\s*min\s*\([^,]+,\s*(\d+)\s*\)/',
            $this->contents,
            $matches
        );
        if ($found !== 1) {
            // Try the other order: min(MAX, $candidate)
            $found = preg_match(
                '/min\s*\(\s*(\d+)\s*,\s*[^)]+\)/',
                $this->contents,
                $matches
            );
        }

        $this->assertSame(1, $found,
            'A numeric upper bound on message_limit must be present in source'
        );
        $bound = (int) $matches[1];
        $this->assertGreaterThan(0, $bound);
        $this->assertLessThanOrEqual(
            1000,
            $bound,
            'message_limit upper bound should be <= 1000 (chat history view)'
        );
    }
}
