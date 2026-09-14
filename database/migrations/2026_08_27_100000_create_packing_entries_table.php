<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_inspection_id')
                ->nullable()
                ->constrained('quality_inspections')
                ->nullOnDelete();
            $table->foreignId('production_batch_id')
                ->nullable()
                ->constrained('production_batches')
                ->nullOnDelete();
            $table->foreignId('work_order_id')
                ->nullable()
                ->constrained('work_orders')
                ->nullOnDelete();
            $table->string('batch_number');
            $table->string('product_name');
            $table->string('item_name')->nullable();
            $table->string('item_code')->nullable();
            $table->string('client_name')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('packed_qty')->default(0);
            $table->string('unit')->default('pcs');
            $table->string('status')->default('pending');
            $table->string('inspected_by')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('quality_inspection_id');
            $table->index(['status', 'inspected_at']);
            $table->index('batch_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_entries');
    }
};
