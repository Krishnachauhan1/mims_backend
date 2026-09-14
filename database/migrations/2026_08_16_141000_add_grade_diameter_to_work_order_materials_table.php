<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_order_materials')) {
            return;
        }

        if (! Schema::hasColumn('work_order_materials', 'grade')) {
            Schema::table('work_order_materials', function (Blueprint $table) {
                $table->string('grade', 50)->nullable()->after('inventory_item_id');
            });
        }

        if (! Schema::hasColumn('work_order_materials', 'diameter_mm')) {
            Schema::table('work_order_materials', function (Blueprint $table) {
                $table->decimal('diameter_mm', 8, 2)->nullable()->after('grade');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_order_materials')) {
            return;
        }

        if (Schema::hasColumn('work_order_materials', 'diameter_mm')) {
            Schema::table('work_order_materials', function (Blueprint $table) {
                $table->dropColumn('diameter_mm');
            });
        }

        if (Schema::hasColumn('work_order_materials', 'grade')) {
            Schema::table('work_order_materials', function (Blueprint $table) {
                $table->dropColumn('grade');
            });
        }
    }
};
