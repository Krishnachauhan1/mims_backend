<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
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
        Schema::table('inventory_transactions', function (Blueprint $table) {
            foreach (['flow_source', 'work_order_id', 'job_work_id', 'production_batch_id'] as $column) {
                if (Schema::hasColumn('inventory_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
