<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_chat_sessions ALTER COLUMN data_services TYPE json USING data_services::json');
            DB::statement('ALTER TABLE ai_chat_sessions ALTER COLUMN data_services DROP NOT NULL');
        } else {
            Schema::table('ai_chat_sessions', function (Blueprint $table) {
                $table->json('data_services')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_chat_sessions ALTER COLUMN data_services SET NOT NULL');
        } else {
            Schema::table('ai_chat_sessions', function (Blueprint $table) {
                $table->json('data_services')->nullable(false)->change();
            });
        }
    }
};
