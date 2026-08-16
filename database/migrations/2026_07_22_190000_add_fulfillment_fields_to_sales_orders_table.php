<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'fulfilled_qty')) {
                $table->unsignedInteger('fulfilled_qty')->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('sales_orders', 'unit_price')) {
                $table->decimal('unit_price', 12, 2)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('sales_orders', 'sold_at')) {
                $table->timestamp('sold_at')->nullable()->after('delivery_date');
            }
            if (! Schema::hasColumn('sales_orders', 'sold_by')) {
                $table->string('sold_by')->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            foreach (['fulfilled_qty', 'unit_price', 'sold_at', 'sold_by'] as $column) {
                if (Schema::hasColumn('sales_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
