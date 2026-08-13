<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversation model refactor.
 *
 *  - Adds an explicit `mcp_servers` scope to both the chat service config
 *    and each session. Previously a conversation's MCP tools were whatever
 *    the bound role happened to grant — invisible on the conversation
 *    itself. Now the conversation names its MCP server(s) directly; the AI
 *    sees the intersection of that list and the caller's role.
 *  - Relaxes `ai_role_id` to nullable. End-user chats run under the
 *    caller's own login role; `ai_role_id` is only a fallback for role-less
 *    (server-to-server) callers, so it is no longer required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_config', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_chat_config', 'mcp_servers')) {
                $table->text('mcp_servers')->nullable()->after('default_data_services');
            }
        });

        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_chat_sessions', 'mcp_servers')) {
                $table->text('mcp_servers')->nullable()->after('data_services');
            }
        });

        Schema::table('ai_chat_config', function (Blueprint $table) {
            $table->integer('ai_role_id')->unsigned()->nullable()->change();
        });

        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            $table->integer('ai_role_id')->unsigned()->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_config', function (Blueprint $table) {
            if (Schema::hasColumn('ai_chat_config', 'mcp_servers')) {
                $table->dropColumn('mcp_servers');
            }
        });

        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('ai_chat_sessions', 'mcp_servers')) {
                $table->dropColumn('mcp_servers');
            }
        });
        // ai_role_id is intentionally left nullable on rollback — restoring
        // NOT NULL would fail against rows created without one.
    }
};
