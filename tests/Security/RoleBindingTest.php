<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Tests\Security;

use DreamFactory\Core\Exceptions\ForbiddenException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the server-side AI-role binding in SessionResource::createSession.
 *
 * The hole (pre-fix): session creation read `ai_role_id` from the request
 * body and only checked it was in the AI Connection's allowed_roles list —
 * never that it was the CALLER's own role. Any non-admin could therefore pass
 * the role ID of a more-privileged (or lateral) role in that list and inherit
 * its full tool surface: row filters, table grants and all. In the Sysco demo
 * that means Kevin (DM region 4) forging Nic's region-5 role and reading a
 * district he must never see.
 *
 * The fix binds a non-admin's AI role to their own login role and ignores the
 * request body's opinion entirely. Admins keep the ai_role_id knob (legit
 * least-privilege: scope the AI narrower than the human). These tests mirror
 * that resolution rule in isolation, the same way RoleValidationTest mirrors
 * the strict in_array fix — no full Laravel/DreamFactory boot required.
 */
class RoleBindingTest extends TestCase
{
    /**
     * Mirrors the binding logic in SessionResource::createSession:
     *   - sysadmin: the requested ai_role_id stands (least-privilege knob).
     *   - non-admin with a role: bound to their own login role; body ignored.
     *   - non-admin with no role: forbidden — no role to derive from.
     */
    private function resolveAiRoleId(bool $isSysAdmin, int $callerRoleId, int $requestedRoleId): int
    {
        if (!$isSysAdmin) {
            if ($callerRoleId <= 0) {
                throw new ForbiddenException(
                    'Your account has no role assigned, so an AI chat role cannot be derived. Contact an administrator.'
                );
            }
            return $callerRoleId;
        }

        return $requestedRoleId;
    }

    // -----------------------------------------------------------------------
    // The load-bearing security property: no foreign role for a non-admin
    // -----------------------------------------------------------------------

    /** Kevin (role 11) forging Nic's role (12) still gets bound to 11. */
    public function testNonAdminCannotObtainForeignRole(): void
    {
        $kevinRole = 11;
        $nicRole   = 12;

        $resolved = $this->resolveAiRoleId(false, $kevinRole, $nicRole);

        $this->assertSame(
            $kevinRole,
            $resolved,
            'A non-admin forging a foreign ai_role_id must be bound to their own login role.',
        );
        $this->assertNotSame($nicRole, $resolved, 'The forged role must never win.');
    }

    /** The request body is inert for a non-admin no matter what it carries. */
    public function testNonAdminRequestBodyRoleIsIgnored(): void
    {
        $ownRole = 7;
        foreach ([0, 1, 999, $ownRole + 1] as $forged) {
            $this->assertSame(
                $ownRole,
                $this->resolveAiRoleId(false, $ownRole, $forged),
                "Requested role {$forged} must be ignored for a non-admin.",
            );
        }
    }

    /** A non-admin with no assigned role cannot conjure one from the body. */
    public function testNonAdminWithoutRoleIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->resolveAiRoleId(false, 0, 42);
    }

    // -----------------------------------------------------------------------
    // The admin knob survives: least-privilege scoping still works
    // -----------------------------------------------------------------------

    /** An admin may still scope the AI to any role (narrower than their own). */
    public function testSysAdminKeepsExplicitRoleChoice(): void
    {
        $this->assertSame(
            5,
            $this->resolveAiRoleId(true, 1, 5),
            'A sysadmin must retain the ai_role_id knob for least-privilege scoping.',
        );
    }
}
