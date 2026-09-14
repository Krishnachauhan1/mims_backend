<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            if (! Schema::hasColumn('rejections', 'stage_order')) {
                $table->unsignedInteger('stage_order')->nullable()->after('stage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            if (Schema::hasColumn('rejections', 'stage_order')) {
                $table->dropColumn('stage_order');
            }
        });
    }
};
