<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('production_batches', 'route_stages')) {
                $table->json('route_stages')->nullable()->after('total_stages');
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            if (Schema::hasColumn('production_batches', 'route_stages')) {
                $table->dropColumn('route_stages');
            }
        });
    }
};
