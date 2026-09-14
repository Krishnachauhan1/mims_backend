<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'cutting_weight')) {
                $table->decimal('cutting_weight', 12, 3)->nullable()->after('min_stock_alert');
            }
            if (! Schema::hasColumn('items', 'final_weight')) {
                $table->decimal('final_weight', 12, 3)->nullable()->after('cutting_weight');
            }
            if (! Schema::hasColumn('items', 'cutting_size')) {
                $table->string('cutting_size')->nullable()->after('final_weight');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            foreach (['cutting_weight', 'final_weight', 'cutting_size'] as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
