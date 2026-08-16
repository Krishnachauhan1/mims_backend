<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_name');
            $table->string('assigned_to');
            $table->string('module');
            $table->string('status')->default('pending');
            $table->text('activity')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_tasks');
    }
};
