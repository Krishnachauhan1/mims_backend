<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->integer('stage_order')->default(1)->after('current_stage_index');
            $table->integer('total_stages')->default(1)->after('stage_order');
            $table->dateTime('started_at')->nullable()->after('expected_completion');
            $table->dateTime('stage_started_at')->nullable()->after('started_at');
            $table->dateTime('completed_at')->nullable()->after('stage_started_at');
            $table->string('hold_reason')->nullable()->after('completed_at');
            $table->json('activity_log')->nullable()->after('stage_history');
            $table->unsignedBigInteger('work_order_id')->nullable()->after('activity_log');
            $table->unsignedBigInteger('checkin_id')->nullable()->after('work_order_id');
            $table->string('pr_number')->nullable()->after('checkin_id');
            $table->string('supplier')->nullable()->after('pr_number');
            $table->string('vendor_name')->nullable()->after('supplier');
            $table->string('client_name')->nullable()->after('vendor_name');
            $table->string('unit')->nullable()->after('client_name');
            $table->string('source')->nullable()->after('unit');
            $table->string('performed_by')->nullable()->after('source');
            $table->string('user_name')->nullable()->after('performed_by');
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropColumn([
                'stage_order',
                'total_stages',
                'started_at',
                'stage_started_at',
                'completed_at',
                'hold_reason',
                'activity_log',
                'work_order_id',
                'checkin_id',
                'pr_number',
                'supplier',
                'vendor_name',
                'client_name',
                'unit',
                'source',
                'performed_by',
                'user_name',
            ]);
        });
    }
};
