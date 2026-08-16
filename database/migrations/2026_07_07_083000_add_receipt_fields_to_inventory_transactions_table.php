<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('po_number')->nullable()->after('batch_number');
            $table->string('pr_number')->nullable()->after('po_number');
            $table->string('receipt_phase')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn(['po_number', 'pr_number', 'receipt_phase']);
        });
    }
};
