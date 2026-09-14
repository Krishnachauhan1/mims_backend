<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rejections')) {
            return;
        }

        Schema::table('rejections', function (Blueprint $table) {
            if (! Schema::hasColumn('rejections', 'rework_assigned_to_user_id')) {
                $table->foreignId('rework_assigned_to_user_id')
                    ->nullable()
                    ->after('rework_assigned_to')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rejections')) {
            return;
        }

        Schema::table('rejections', function (Blueprint $table) {
            if (Schema::hasColumn('rejections', 'rework_assigned_to_user_id')) {
                $table->dropConstrainedForeignId('rework_assigned_to_user_id');
            }
        });
    }
};
