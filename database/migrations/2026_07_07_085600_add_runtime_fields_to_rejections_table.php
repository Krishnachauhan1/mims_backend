<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            $table->double('unit_cost')->nullable()->after('quantity');
            $table->double('scrap_cost')->nullable()->after('unit_cost');
            $table->date('rejection_date')->nullable()->after('scrap_cost');
            $table->unsignedBigInteger('user_id')->nullable()->after('rejection_date');
            $table->string('rework_assigned_to')->nullable()->after('reported_by');
            $table->integer('total_produced')->default(0)->after('rework_assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            $table->dropColumn([
                'unit_cost',
                'scrap_cost',
                'rejection_date',
                'user_id',
                'rework_assigned_to',
                'total_produced',
            ]);
        });
    }
};
