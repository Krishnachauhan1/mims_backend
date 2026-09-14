<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_machine_times')) {
            return;
        }

        Schema::create('item_machine_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('machine_id')->constrained('machinery')->cascadeOnDelete();
            $table->string('grade', 50)->default('');
            $table->decimal('diameter_mm', 8, 2)->nullable();
            $table->decimal('per_piece_time', 10, 2)->nullable();
            $table->decimal('average_time', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(
                ['machine_id', 'grade', 'diameter_mm'],
                'item_machine_times_machine_grade_dia_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_machine_times');
    }
};
