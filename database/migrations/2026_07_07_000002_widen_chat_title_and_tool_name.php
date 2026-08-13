<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen columns that can receive text longer than their varchar limit.
 *
 *  - `ai_chat_sessions.title` is free-form: the auto-title path truncates,
 *    but a client-supplied title on session create is stored as-is and a
 *    value over 255 chars 500s on strict MySQL. Not indexed, so `text` is
 *    safe.
 *  - `ai_chat_messages.tool_name` stores the composite identifier
 *    `mcp_<service>__<tool>`; DF service names run to 64 chars and remote
 *    MCP tool names are unbounded by spec, so 100 chars is too tight — and
 *    the failure fires after the tool already executed. Still an
 *    identifier, so 255 rather than `text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            $table->text('title')->nullable()->change();
        });

        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->string('tool_name', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally left as-is on rollback — narrowing back to
        // varchar(255)/varchar(100) would truncate or reject existing rows.
    }
};
