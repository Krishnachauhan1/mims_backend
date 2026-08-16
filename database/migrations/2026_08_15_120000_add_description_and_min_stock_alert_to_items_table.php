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
            if (! Schema::hasColumn('items', 'description')) {
                $table->string('description')->nullable()->after('name');
            }
            if (! Schema::hasColumn('items', 'min_stock_alert')) {
                $table->unsignedInteger('min_stock_alert')->default(0)->after('unit');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('items', 'min_stock_alert')) {
                $table->dropColumn('min_stock_alert');
            }
        });
    }
};
