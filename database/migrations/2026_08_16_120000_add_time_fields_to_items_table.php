<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('per_piece_time', 10, 2)->nullable()->after('min_stock_alert');
            $table->decimal('average_time', 10, 2)->nullable()->after('per_piece_time');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['per_piece_time', 'average_time']);
        });
    }
};
