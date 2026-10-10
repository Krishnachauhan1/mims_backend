<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('material_requisitions');

        Schema::create('material_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machinery_id')
                ->constrained('machinery')
                ->cascadeOnDelete();
            $table->string('machine_name');
            $table->string('machine_code')->nullable();
            $table->string('source', 40)->default('operator_request');
            $table->foreignId('inventory_item_id')
                ->nullable()
                ->constrained('inventory_items')
                ->nullOnDelete();
            $table->string('item_name');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 50)->default('pcs');
            $table->string('status', 40)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('recipient_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->foreignId('issued_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('issued_by_name')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['machinery_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requisitions');
    }
};
