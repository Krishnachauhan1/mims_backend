<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_inspections', function (Blueprint $table) {
            $table->unsignedInteger('passed_qty')->default(0)->after('quantity');
            $table->unsignedInteger('rejected_qty')->default(0)->after('passed_qty');
        });

        Schema::table('rejections', function (Blueprint $table) {
            $table->foreignId('quality_inspection_id')
                ->nullable()
                ->after('work_order_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quality_inspection_id');
        });

        Schema::table('quality_inspections', function (Blueprint $table) {
            $table->dropColumn(['passed_qty', 'rejected_qty']);
        });
    }
};
