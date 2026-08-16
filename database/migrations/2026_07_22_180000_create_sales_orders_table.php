<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('client_name')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('product_name');
            $table->string('unit')->default('pcs');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('available_at_order')->default(0);
            $table->unsignedInteger('shortfall')->default(0);
            $table->string('status')->default('open')->index();
            $table->date('order_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
