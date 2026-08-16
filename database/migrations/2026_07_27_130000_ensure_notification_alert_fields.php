<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'severity')) {
                $table->string('severity')->default('info')->after('type');
            }
            if (! Schema::hasColumn('notifications', 'route')) {
                $table->string('route')->nullable()->after('severity');
            }
            if (! Schema::hasColumn('notifications', 'reference')) {
                $table->string('reference')->nullable()->after('route');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'reference')) {
                $table->dropColumn('reference');
            }
            if (Schema::hasColumn('notifications', 'route')) {
                $table->dropColumn('route');
            }
            if (Schema::hasColumn('notifications', 'severity')) {
                $table->dropColumn('severity');
            }
        });
    }
};
