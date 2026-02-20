<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-service configuration (one row per ai_chat service instance).
        Schema::create('ai_chat_config', function (Blueprint $table) {
            $table->integer('service_id')->unsigned()->primary();
            $table->integer('ai_service_id')->unsigned();
            $table->integer('ai_role_id')->unsigned();
            $table->text('default_data_services')->nullable();
            $table->text('system_prompt')->nullable();
            $table->integer('max_tool_calls')->unsigned()->default(25);
            $table->integer('max_messages')->unsigned()->default(200);
            $table->timestamps();

            $table->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
        });

        // Chat sessions.
        Schema::create('ai_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->integer('service_id')->unsigned();
            $table->integer('ai_service_id')->unsigned();
            $table->integer('user_id')->unsigned();
            $table->integer('user_role_id')->unsigned()->nullable();
            $table->integer('ai_role_id')->unsigned();
            $table->text('data_services');
            $table->text('allowed_resources')->nullable();
            $table->string('title', 255)->nullable();
            $table->text('system_prompt')->nullable();
            $table->string('status', 20)->default('active');
            $table->integer('total_input_tokens')->unsigned()->default(0);
            $table->integer('total_output_tokens')->unsigned()->default(0);
            $table->integer('tool_call_count')->unsigned()->default(0);
            $table->timestamps();

            $table->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
            $table->index(['user_id', 'status']);
        });

        // Chat messages.
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('role', 20);
            $table->text('content')->nullable();
            $table->text('tool_calls')->nullable();
            $table->string('tool_call_id', 100)->nullable();
            $table->string('tool_name', 100)->nullable();
            $table->boolean('is_error')->default(false);
            $table->integer('input_tokens')->unsigned()->default(0);
            $table->integer('output_tokens')->unsigned()->default(0);
            $table->integer('latency_ms')->unsigned()->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('session_id')->references('id')->on('ai_chat_sessions')->onDelete('cascade');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_sessions');
        Schema::dropIfExists('ai_chat_config');
    }
};
