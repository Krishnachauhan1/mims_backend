<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('packing_entries', 'hold_qty')) {
                $table->unsignedInteger('hold_qty')->default(0)->after('rejected_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packing_entries', function (Blueprint $table) {
            if (Schema::hasColumn('packing_entries', 'hold_qty')) {
                $table->dropColumn('hold_qty');
            }
        });
    }
};
