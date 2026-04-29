<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Services;

use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Utility\JWTUtilities;
use Illuminate\Support\Str;

/**
 * Resolves (and lazily provisions) the credentials a chat session uses to
 * call DreamFactory APIs on behalf of an AI role.
 *
 * Captain's directive: **the role is the only access bottleneck**. There
 * is no parallel allow-list of services in the chat config — whatever the
 * role can read, the AI can read. Whatever the role cannot, the AI cannot.
 *
 * For that to work at the wire, every tool call from the orchestrator
 * needs:
 *   1. A **session token** (JWT) authenticating as a user that DF will
 *      run RBAC against.
 *   2. An **API key** matching an app whose `role_id` is the AI role.
 *      DF's auth pipeline requires the API key on service endpoints; the
 *      app/role binding tells DF "this request runs under role N",
 *      independent of whatever role the actual logged-in admin holds.
 *
 * Both are derivable from one input: the AI role id. We auto-provision
 * the user, app, and link rows on first use so admins never have to
 * fiddle with users-and-apps to make a chat work — pick a role, that's
 * it. Idempotent across reruns: subsequent sessions for the same role
 * find the existing user/app rather than creating duplicates.
 *
 * The provisioning naming scheme is deliberate and stable so an admin
 * grepping the user / app tables can immediately see "this is an AI
 * agent for role N":
 *   user.email   = ai-agent-role-N@dreamfactory.local
 *   user.name    = "AI Agent (role N)"
 *   app.name     = ai-agent-app-role-N
 *   app.role_id  = N
 */
class AiAgentCredentials
{
    /**
     * Resolve a (token, api_key) pair for the given AI role. Auto-provisions
     * the agent user + app on first call.
     *
     * @return array{token: string, api_key: string, user_id: int, app_id: int}
     */
    public static function resolve(int $aiRoleId): array
    {
        if ($aiRoleId <= 0) {
            throw new \InvalidArgumentException('AI role id is required to resolve credentials.');
        }

        $user = self::ensureAgentUser($aiRoleId);
        $app  = self::ensureAgentApp($aiRoleId);
        self::ensureUserAppRoleLink($user->id, $app->id, $aiRoleId);

        // JWT identifies the user to DF's auth pipeline. The `app.api_key`
        // identifies which app (and therefore which role binding) the
        // request runs under. DF requires both on service endpoints.
        $token = JWTUtilities::makeJWTByUser($user->id, $user->email);

        return [
            'token'   => $token,
            'api_key' => (string) $app->api_key,
            'user_id' => (int) $user->id,
            'app_id'  => (int) $app->id,
        ];
    }

    /**
     * Find or lazily create the synthetic agent user dedicated to this role.
     */
    private static function ensureAgentUser(int $aiRoleId): User
    {
        $email = "ai-agent-role-{$aiRoleId}@dreamfactory.local";
        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'email'        => $email,
                'username'     => "ai-agent-role-{$aiRoleId}",
                'name'         => "AI Agent (role {$aiRoleId})",
                'first_name'   => 'AI',
                'last_name'    => "Agent {$aiRoleId}",
                // Random unguessable password — the user never logs in
                // interactively. Tokens are minted directly via JWT.
                'password'     => bcrypt(bin2hex(random_bytes(32))),
                'is_active'    => true,
                'is_sys_admin' => false,
            ]);
        }

        return $user;
    }

    /**
     * Find or lazily create the app dedicated to this role.
     *
     * The app's `role_id` is what makes this work: DF's AccessCheck
     * middleware reads the app from `X-DreamFactory-API-Key`, looks up
     * its `role_id`, and runs RBAC against THAT — independent of which
     * user is calling. So when the AI agent calls a DF service endpoint
     * with this app's key + the agent user's JWT, RBAC bottlenecks at
     * exactly the configured AI role.
     */
    private static function ensureAgentApp(int $aiRoleId): App
    {
        $name = "ai-agent-app-role-{$aiRoleId}";
        $app = App::where('name', $name)->first();

        if (!$app) {
            // App.api_key is set by App::boot() saving hook on insert if
            // empty, but be explicit so a subsequent fresh() roundtrip
            // doesn't need to occur. 40-hex is DF's standard key shape.
            $app = App::create([
                'name'           => $name,
                'description'    => "Auto-provisioned AI agent app for role {$aiRoleId}. "
                                  . "Used by chat sessions configured with ai_role_id={$aiRoleId} "
                                  . "to call DreamFactory APIs under that role's permissions.",
                'type'           => 0, // 0 = NoStorageRequired
                'role_id'        => $aiRoleId,
                'is_active'      => true,
                'allow_fullscreen_toggle' => false,
                'toggle_location' => 'top',
                'requires_fullscreen' => false,
                // DF's App model validation requires api_key to be at least
                // 64 characters. 32 random bytes hex-encoded gives 64 chars.
                'api_key'        => bin2hex(random_bytes(32)),
            ]);
        }

        return $app;
    }

    /**
     * Ensure the user_to_app_to_role link row exists. Without it, JWT
     * minting and downstream `Session::getRoleId()` lookups can fail to
     * resolve the user to its AI role.
     */
    private static function ensureUserAppRoleLink(int $userId, int $appId, int $aiRoleId): void
    {
        $existing = UserAppRole::where('user_id', $userId)
            ->where('role_id', $aiRoleId)
            ->where('app_id', $appId)
            ->first();

        if (!$existing) {
            UserAppRole::create([
                'user_id' => $userId,
                'role_id' => $aiRoleId,
                'app_id'  => $appId,
            ]);
        }
    }
}
