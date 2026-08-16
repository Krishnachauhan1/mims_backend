<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('severity')->default('info')->after('type');
            $table->string('route')->nullable()->after('severity');
            $table->string('reference')->nullable()->after('route');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['severity', 'route', 'reference']);
        });
    }
};
