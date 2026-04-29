<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ai_chat_sessions.data_services` was originally a required JSON list
 * the admin had to populate per session. With the role-as-sole-bottleneck
 * refactor (see SessionResource::createSession comments), the field is
 * now an OPTIONAL override:
 *   - null  → derive the AI's tool surface from the role's service_access
 *   - array → narrow the surface to just these services for this session
 *
 * Allowing null is required for the new "no override" path; existing rows
 * keep their explicit lists, so this is a non-breaking change.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            // Cross-DB-portable ALTER. MySQL needs CHANGE; the Laravel
            // schema builder emits the right thing per driver.
            $table->json('data_services')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revert: data_services becomes required again. Existing rows
        // with null would block this; admins running rollback need to
        // populate or delete those rows first.
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            $table->json('data_services')->nullable(false)->change();
        });
    }
};
