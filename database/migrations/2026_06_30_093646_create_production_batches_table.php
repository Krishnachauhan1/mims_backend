<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->unique();
            $table->string('product_name');
            $table->unsignedBigInteger('workflow_id')->nullable()->index();
            $table->string('current_stage')->nullable();
            $table->integer('current_stage_index')->default(0);
            $table->integer('quantity')->default(0);
            $table->string('status')->default('in_progress');
            $table->string('priority')->default('normal');
            $table->string('assigned_to')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expected_completion')->nullable();
            $table->json('stage_history')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batches');
    }
};
