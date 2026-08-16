<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->string('pr_number')->nullable();
            $table->unsignedBigInteger('pr_id')->nullable();
            $table->string('vendor_name');
            $table->string('contact_person')->nullable();
            $table->string('contact_mobile')->nullable();
            $table->string('status')->default('pending');
            $table->json('items')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
