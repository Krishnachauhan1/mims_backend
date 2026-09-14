<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_transactions', 'mrn_number')) {
                $table->string('mrn_number', 50)->nullable()->unique()->after('pr_number');
            }
        });

        $year = (int) now()->year;
        $prefix = "MRN-{$year}-";
        $seq = 0;

        DB::table('inventory_transactions')
            ->where('type', 'checkin')
            ->whereNotNull('po_number')
            ->whereNull('mrn_number')
            ->orderBy('id')
            ->each(function (object $row) use (&$seq, $prefix): void {
                $seq++;
                DB::table('inventory_transactions')
                    ->where('id', $row->id)
                    ->update([
                        'mrn_number' => $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_transactions', 'mrn_number')) {
                $table->dropUnique(['mrn_number']);
                $table->dropColumn('mrn_number');
            }
        });
    }
};
