<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_production_entries')) {
            return;
        }

        Schema::create('daily_production_entries', function (Blueprint $table) {
            $table->id();
            $table->date('production_date');
            $table->foreignId('machine_id')->constrained('machinery')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->string('shift', 20);
            $table->time('start_time')->nullable();
            $table->time('stop_time')->nullable();
            $table->decimal('cycle_time', 10, 2)->nullable()->comment('Minutes per piece');
            $table->unsignedInteger('actual_quantity')->default(0);
            $table->unsignedInteger('reject_quantity')->default(0);
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->string('route_card_no')->nullable();
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->string('process_name')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['production_date', 'machine_id']);
            $table->index(['production_date', 'operator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_production_entries');
    }
};
