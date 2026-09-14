<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_device_tokens', function (Blueprint $table) {
            $table->string('token', 512)->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_device_tokens', function (Blueprint $table) {
            $table->string('token', 255)->change();
        });
    }
};
