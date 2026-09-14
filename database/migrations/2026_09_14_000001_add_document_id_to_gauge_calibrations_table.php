<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gauge_calibrations') || ! Schema::hasTable('documents')) {
            return;
        }

        if (Schema::hasColumn('gauge_calibrations', 'document_id')) {
            return;
        }

        Schema::table('gauge_calibrations', function (Blueprint $table) {
            $table->foreignId('document_id')
                ->nullable()
                ->after('certificate_ref')
                ->constrained('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('gauge_calibrations', 'document_id')) {
            return;
        }

        Schema::table('gauge_calibrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
