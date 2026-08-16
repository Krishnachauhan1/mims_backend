<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gauge_calibrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gauge_id')->constrained()->cascadeOnDelete();
            $table->date('calibration_date');
            $table->date('next_due_date')->nullable();
            $table->string('performed_by')->nullable();
            $table->string('result')->default('pass');
            $table->string('certificate_ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gauge_calibrations');
    }
};
