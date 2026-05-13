<?php

namespace DreamFactory\Core\AiChat\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: AI role allowlist must use strict comparison.
 *
 * The April 2026 audit (df-ai-chat FINDING-02) found loose comparison in
 * `SessionResource::validateAiRoleAllowed()`:
 *
 *     if (!in_array($aiRoleId, $allowedRoles, false)) {
 *
 * PHP loose comparison still treats `true == 1` as a match, so a request that
 * coerces aiRoleId into a truthy value bypasses the allowlist when allowedRoles
 * contains the integer 1.
 *
 * The robust fix is twofold:
 *  1. Normalize allowedRoles to integers after json_decode (strings/numerics
 *     stored as JSON values still need to compare equal to int aiRoleId).
 *  2. Use strict comparison (`in_array(..., true)`).
 *
 * This test asserts the source uses strict comparison and that the resource
 * normalizes JSON-decoded role IDs to ints before comparison.
 */
class AiRoleStrictComparisonTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Resources/SessionResource.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testRoleAllowlistUsesStrictComparison(): void
    {
        // The validator must not call in_array with the third argument false.
        $this->assertDoesNotMatchRegularExpression(
            '/in_array\s*\(\s*\$aiRoleId\s*,[^)]*,\s*false\s*\)/',
            $this->contents,
            'AI role allowlist check must use strict comparison (in_array third arg true)'
        );

        // And it must explicitly use strict mode.
        $this->assertMatchesRegularExpression(
            '/in_array\s*\(\s*\$aiRoleId\s*,[^)]*,\s*true\s*\)/',
            $this->contents,
            'AI role allowlist check must explicitly pass strict=true to in_array'
        );
    }

    public function testRoleAllowlistNormalizesToInt(): void
    {
        // Defense-in-depth: after json_decode($allowedRoles, true), the array
        // should be coerced to ints so admin configs that stored role IDs as
        // strings still match integer aiRoleId under strict comparison.
        // We accept either array_map('intval', ...) or array_map(fn => (int)$x).
        $hasIntvalNormalization =
            preg_match('/array_map\s*\(\s*[\'"]intval[\'"]/', $this->contents) === 1;
        $hasArrowIntCast =
            preg_match('/array_map\s*\(\s*(fn|function)[^,]*\(int\)/', $this->contents) === 1;

        $this->assertTrue(
            $hasIntvalNormalization || $hasArrowIntCast,
            'allowedRoles array must be normalized to ints (array_map intval or equivalent) before strict comparison'
        );
    }

    /**
     * Behavioral assertion: demonstrate that the loose-mode comparison the old
     * code used would have accepted bypass payloads, while strict mode rejects
     * them. This is the exploit pattern we are foreclosing.
     */
    public function testStrictComparisonRejectsBooleanBypass(): void
    {
        $allowed = [1, 2, 3];

        // The old vulnerable behavior: boolean true matches integer 1 under
        // loose comparison.
        $this->assertTrue(
            in_array(true, $allowed, false),
            'Loose comparison incorrectly accepts boolean true (sanity check on PHP semantics)'
        );

        // The fix: strict comparison rejects the same payload.
        $this->assertFalse(
            in_array(true, $allowed, true),
            'Strict comparison must reject boolean true against integer allowlist'
        );
    }
}
