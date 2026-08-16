<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('material_name');
            $table->string('supplier')->nullable();
            $table->string('destination')->nullable();
            $table->integer('quantity');
            $table->string('unit')->default('pcs');
            $table->string('batch_number')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('completed');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
