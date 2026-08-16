<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_transactions', 'po_number')) {
                $table->string('po_number')->nullable()->after('batch_number');
            }
            if (! Schema::hasColumn('inventory_transactions', 'pr_number')) {
                $table->string('pr_number')->nullable()->after('po_number');
            }
            if (! Schema::hasColumn('inventory_transactions', 'receipt_phase')) {
                $table->string('receipt_phase')->nullable()->after('status');
            }
            if (! Schema::hasColumn('inventory_transactions', 'items')) {
                $table->json('items')->nullable()->after('pr_number');
            }
            if (! Schema::hasColumn('inventory_transactions', 'production_batch_id')) {
                $table->unsignedBigInteger('production_batch_id')->nullable()->index()->after('user_id');
            }
            if (! Schema::hasColumn('inventory_transactions', 'job_work_id')) {
                $table->unsignedBigInteger('job_work_id')->nullable()->index()->after('production_batch_id');
            }
            if (! Schema::hasColumn('inventory_transactions', 'work_order_id')) {
                $table->unsignedBigInteger('work_order_id')->nullable()->index()->after('job_work_id');
            }
            if (! Schema::hasColumn('inventory_transactions', 'flow_source')) {
                $table->string('flow_source')->nullable()->index()->after('work_order_id');
            }
        });
    }

    public function down(): void
    {
        // Repair migration — no rollback.
    }
};
