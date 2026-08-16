<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machinery', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('machine_code')->unique();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('location')->nullable();
            $table->string('capacity')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('status')->default('active');
            $table->string('default_stage')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machinery');
    }
};
