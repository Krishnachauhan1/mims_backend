<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clients') && ! Schema::hasColumn('clients', 'gst_number')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('gst_number', 15)->nullable()->after('phone');
            });
        }

        if (Schema::hasTable('vendors') && ! Schema::hasColumn('vendors', 'gst_number')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->string('gst_number', 15)->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'gst_number')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('gst_number');
            });
        }

        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'gst_number')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('gst_number');
            });
        }
    }
};
