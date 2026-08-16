<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machinery_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machinery_id')->constrained('machinery')->cascadeOnDelete();
            $table->string('type');
            $table->text('notes')->nullable();
            $table->date('performed_at');
            $table->string('status')->default('completed');
            $table->string('performed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machinery_maintenance_logs');
    }
};
