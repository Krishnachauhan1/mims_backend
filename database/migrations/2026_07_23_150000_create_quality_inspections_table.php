<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_number');
            $table->string('product_name');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit')->default('pcs');
            $table->string('status')->default('pending');
            $table->string('workflow_name')->nullable();
            $table->string('pr_number')->nullable();
            $table->string('inspected_by')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('batch_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_inspections');
    }
};
