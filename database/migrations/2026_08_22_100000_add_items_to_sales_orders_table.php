<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->json('items')->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('items');
        });
    }
};
