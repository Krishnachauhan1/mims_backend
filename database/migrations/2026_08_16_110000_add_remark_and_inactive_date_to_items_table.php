<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'remark')) {
                $table->text('remark')->nullable()->after('cutting_size');
            }
            if (! Schema::hasColumn('items', 'inactive_date')) {
                $table->date('inactive_date')->nullable()->after('remark');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'inactive_date')) {
                $table->dropColumn('inactive_date');
            }
            if (Schema::hasColumn('items', 'remark')) {
                $table->dropColumn('remark');
            }
        });
    }
};
