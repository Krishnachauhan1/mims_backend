<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_production_entries')) {
            return;
        }

        Schema::table('daily_production_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_production_entries', 'item_id')) {
                $table->foreignId('item_id')
                    ->nullable()
                    ->after('route_card_no')
                    ->constrained('items')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('daily_production_entries', 'item_name')) {
                $table->string('item_name')->nullable()->after('item_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('daily_production_entries')) {
            return;
        }

        Schema::table('daily_production_entries', function (Blueprint $table) {
            if (Schema::hasColumn('daily_production_entries', 'item_id')) {
                $table->dropConstrainedForeignId('item_id');
            }

            if (Schema::hasColumn('daily_production_entries', 'item_name')) {
                $table->dropColumn('item_name');
            }
        });
    }
};
