<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            $table->foreignId('production_batch_id')
                ->nullable()
                ->after('id')
                ->constrained('production_batches')
                ->nullOnDelete();
            $table->foreignId('work_order_id')
                ->nullable()
                ->after('production_batch_id')
                ->constrained('work_orders')
                ->nullOnDelete();
            $table->string('item_code')->nullable()->after('product_name');
            $table->boolean('rework_possible')->default(true)->after('rework_status');
        });
    }

    public function down(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_batch_id');
            $table->dropConstrainedForeignId('work_order_id');
            $table->dropColumn(['item_code', 'rework_possible']);
        });
    }
};
