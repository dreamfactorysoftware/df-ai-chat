<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the strict role-ID comparison fix in SessionResource.
 *
 * SessionResource::validateAiAccess() calls:
 *   in_array($aiRoleId, $allowedRoles, true)   // FIXED — was false
 *
 * With strict=false, PHP type-juggling caused:
 *   - integer 0 to match ANY non-numeric string (0 == "admin" is true in PHP)
 *   - integer 0 to match boolean false
 *   - the string "0" to match integer 0 when the role list came from JSON
 *
 * These tests validate the corrected strict behaviour directly, independent
 * of the full Laravel/DreamFactory stack.
 */
class RoleValidationTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers — mirrors the fixed logic in SessionResource
    // -----------------------------------------------------------------------

    /**
     * Returns true when $roleId is present in $allowedRoles using strict
     * type+value comparison (the fixed behaviour).
     */
    private function isRoleAllowed(mixed $roleId, array $allowedRoles): bool
    {
        return in_array($roleId, $allowedRoles, true);
    }

    // -----------------------------------------------------------------------
    // Happy-path: legitimate role IDs that should pass
    // -----------------------------------------------------------------------

    public function testIntegerRoleIdMatchesIntegerInList(): void
    {
        $this->assertTrue(
            $this->isRoleAllowed(5, [1, 3, 5, 7]),
            'A valid integer role ID must be found when it exists in the list.',
        );
    }

    public function testStringRoleIdMatchesStringInList(): void
    {
        // Some callers may pass the role as a string; confirm that works when
        // the allowed list also contains strings.
        $this->assertTrue(
            $this->isRoleAllowed('5', ['1', '3', '5', '7']),
            'A valid string role ID must be found when the list contains strings.',
        );
    }

    public function testSingleElementListAllowsExactMatch(): void
    {
        $this->assertTrue($this->isRoleAllowed(42, [42]));
    }

    // -----------------------------------------------------------------------
    // Security: type-juggling attacks must all fail with strict=true
    // -----------------------------------------------------------------------

    /**
     * PHP non-strict: (0 == "admin") is TRUE because PHP coerces the string to 0.
     * With strict: (0 === "admin") is FALSE. A role ID of 0 must never grant
     * access when the allowed list only contains role names/strings.
     */
    public function testRoleIdZeroDoesNotMatchNonNumericString(): void
    {
        $allowedRoles = ['admin', 'editor', 'viewer'];
        $this->assertFalse(
            $this->isRoleAllowed(0, $allowedRoles),
            'Integer 0 must not match a non-numeric string via type juggling.',
        );
    }

    /**
     * In PHP, (0 == "") is TRUE without strict mode.
     * With strict, 0 must not match the empty string.
     */
    public function testRoleIdZeroDoesNotMatchEmptyString(): void
    {
        $this->assertFalse(
            $this->isRoleAllowed(0, ['']),
            'Integer 0 must not match an empty string.',
        );
    }

    /**
     * The integer 0 and the string "0" are different types.
     * Without strict: in_array(0, ["0"]) returns true (string coerced to int).
     * With strict: they must not match.
     */
    public function testIntegerZeroDoesNotMatchStringZero(): void
    {
        $this->assertFalse(
            $this->isRoleAllowed(0, ['0']),
            'Integer 0 must not match string "0" with strict comparison.',
        );
    }

    /**
     * Inverse of above: string "0" must not match integer 0 in the allowed list.
     */
    public function testStringZeroDoesNotMatchIntegerZero(): void
    {
        $this->assertFalse(
            $this->isRoleAllowed('0', [0]),
            'String "0" must not match integer 0 in the allowed list.',
        );
    }

    /**
     * (false == 0) is TRUE in PHP without strict.
     * Defensive: even if a buggy code path produced boolean false as a role ID,
     * it must not match integer 0.
     */
    public function testBooleanFalseDoesNotMatchIntegerZero(): void
    {
        $this->assertFalse(
            $this->isRoleAllowed(false, [0]),
            'Boolean false must not match integer 0.',
        );
    }

    /**
     * (null == false == 0) without strict. Null must not grant access.
     */
    public function testNullDoesNotMatchIntegerZero(): void
    {
        $this->assertFalse(
            $this->isRoleAllowed(null, [0]),
            'Null must not match integer 0.',
        );
    }

    // -----------------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------------

    public function testRoleNotInListReturnsFalse(): void
    {
        $this->assertFalse(
            $this->isRoleAllowed(99, [1, 2, 3]),
            'A role ID not present in the list must return false.',
        );
    }

    public function testEmptyAllowedListReturnsFalse(): void
    {
        $this->assertFalse(
            $this->isRoleAllowed(1, []),
            'An empty allowed list must never grant access.',
        );
    }

    public function testLargeRoleIdMatch(): void
    {
        $this->assertTrue($this->isRoleAllowed(1_000_000, [999_999, 1_000_000, 1_000_001]));
    }
}
