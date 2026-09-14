<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('packing_entries', 'hold_reason')) {
                $table->text('hold_reason')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('packing_entries', 'reject_reason')) {
                $table->text('reject_reason')->nullable()->after('hold_reason');
            }
            if (! Schema::hasColumn('packing_entries', 'packed_by')) {
                $table->string('packed_by')->nullable()->after('reject_reason');
            }
            if (! Schema::hasColumn('packing_entries', 'packed_at')) {
                $table->timestamp('packed_at')->nullable()->after('packed_by');
            }
            if (! Schema::hasColumn('packing_entries', 'action_by')) {
                $table->string('action_by')->nullable()->after('packed_at');
            }
            if (! Schema::hasColumn('packing_entries', 'action_at')) {
                $table->timestamp('action_at')->nullable()->after('action_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packing_entries', function (Blueprint $table) {
            foreach ([
                'action_at',
                'action_by',
                'packed_at',
                'packed_by',
                'reject_reason',
                'hold_reason',
            ] as $column) {
                if (Schema::hasColumn('packing_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
