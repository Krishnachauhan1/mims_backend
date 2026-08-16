<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_works', function (Blueprint $table) {
            $table->unsignedBigInteger('work_order_id')->nullable()->after('id');
            $table->unsignedBigInteger('batch_id')->nullable()->after('work_order_id');
            $table->unsignedBigInteger('vendor_id')->nullable()->after('vendor_name');
            $table->unsignedBigInteger('client_id')->nullable()->after('client_name');
            $table->string('process_name')->nullable()->after('product_name');
            $table->boolean('from_workflow')->default(false)->after('status');
            $table->string('workflow_stage')->nullable()->after('from_workflow');
            $table->string('workflow_name')->nullable()->after('workflow_stage');
            $table->string('pr_number')->nullable()->after('workflow_name');
        });
    }

    public function down(): void
    {
        Schema::table('job_works', function (Blueprint $table) {
            $table->dropColumn([
                'work_order_id',
                'batch_id',
                'vendor_id',
                'client_id',
                'process_name',
                'from_workflow',
                'workflow_stage',
                'workflow_name',
                'pr_number',
            ]);
        });
    }
};
