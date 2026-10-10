<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_requisitions', function (Blueprint $table) {
            $table->string('destination_type', 20)->default('machine')->after('id');
        });

        Schema::table('material_requisitions', function (Blueprint $table) {
            $table->dropForeign(['machinery_id']);
        });

        Schema::table('material_requisitions', function (Blueprint $table) {
            $table->unsignedBigInteger('machinery_id')->nullable()->change();
            $table->foreign('machinery_id')
                ->references('id')
                ->on('machinery')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_requisitions', function (Blueprint $table) {
            $table->dropForeign(['machinery_id']);
        });

        Schema::table('material_requisitions', function (Blueprint $table) {
            $table->unsignedBigInteger('machinery_id')->nullable(false)->change();
            $table->foreign('machinery_id')
                ->references('id')
                ->on('machinery')
                ->cascadeOnDelete();
            $table->dropColumn('destination_type');
        });
    }
};
