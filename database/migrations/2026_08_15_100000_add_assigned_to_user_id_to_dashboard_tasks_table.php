<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dashboard_tasks')) {
            return;
        }

        Schema::table('dashboard_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('dashboard_tasks', 'assigned_to_user_id')) {
                $table->foreignId('assigned_to_user_id')
                    ->nullable()
                    ->after('assigned_to')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashboard_tasks')
            || ! Schema::hasColumn('dashboard_tasks', 'assigned_to_user_id')) {
            return;
        }

        Schema::table('dashboard_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_user_id');
        });
    }
};
