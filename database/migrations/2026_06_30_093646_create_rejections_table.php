<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rejections', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->nullable();
            $table->string('product_name');
            $table->string('stage')->nullable();
            $table->integer('quantity');
            $table->string('reason');
            $table->string('severity')->default('medium');
            $table->string('rework_status')->default('pending');
            $table->string('reported_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejections');
    }
};
