<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dashboard_tasks')) {
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

            return;
        }

        Schema::table('dashboard_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('dashboard_tasks', 'task_name')) {
                $table->string('task_name')->after('id');
            }
            if (! Schema::hasColumn('dashboard_tasks', 'assigned_to')) {
                $table->string('assigned_to')->after('task_name');
            }
            if (! Schema::hasColumn('dashboard_tasks', 'module')) {
                $table->string('module')->after('assigned_to');
            }
            if (! Schema::hasColumn('dashboard_tasks', 'status')) {
                $table->string('status')->default('pending')->after('module');
            }
            if (! Schema::hasColumn('dashboard_tasks', 'activity')) {
                $table->text('activity')->nullable()->after('status');
            }
            if (! Schema::hasColumn('dashboard_tasks', 'due_date')) {
                $table->date('due_date')->nullable()->after('activity');
            }
        });
    }

    public function down(): void
    {
        // Repair migration — no rollback.
    }
};
