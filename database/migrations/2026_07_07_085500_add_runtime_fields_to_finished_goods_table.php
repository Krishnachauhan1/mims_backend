<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finished_goods', function (Blueprint $table) {
            $table->string('warehouse')->nullable()->after('location');
            $table->integer('stock')->default(0)->after('quantity');
            $table->integer('dispatched')->default(0)->after('stock');
            $table->string('status')->default('In Stock')->after('dispatched');
            $table->unsignedBigInteger('source_batch_id')->nullable()->after('client_id');
            $table->string('flow_step')->nullable()->after('source_batch_id');
            $table->string('pr_number')->nullable()->after('flow_step');
            $table->string('workflow')->nullable()->after('pr_number');
            $table->string('rack_id')->nullable()->after('workflow');
        });
    }

    public function down(): void
    {
        Schema::table('finished_goods', function (Blueprint $table) {
            $table->dropColumn([
                'warehouse',
                'stock',
                'dispatched',
                'status',
                'source_batch_id',
                'flow_step',
                'pr_number',
                'workflow',
                'rack_id',
            ]);
        });
    }
};
