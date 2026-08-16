<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('production_batches', 'awaiting_final_completion_at')) {
                $table->dateTime('awaiting_final_completion_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('production_batches', 'last_completion_reminder_at')) {
                $table->dateTime('last_completion_reminder_at')->nullable()->after('awaiting_final_completion_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            foreach (['last_completion_reminder_at', 'awaiting_final_completion_at'] as $column) {
                if (Schema::hasColumn('production_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
