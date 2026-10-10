<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gauges', function (Blueprint $table) {
            if (! Schema::hasColumn('gauges', 'measuring_range')) {
                $table->string('measuring_range')->nullable()->after('location');
            }
            if (! Schema::hasColumn('gauges', 'least_count')) {
                $table->string('least_count')->nullable()->after('measuring_range');
            }
            if (! Schema::hasColumn('gauges', 'calibration_agency')) {
                $table->string('calibration_agency')->nullable()->after('least_count');
            }
            if (! Schema::hasColumn('gauges', 'calibration_frequency_months')) {
                $table->unsignedSmallInteger('calibration_frequency_months')->default(12)->after('calibration_agency');
            }
            if (! Schema::hasColumn('gauges', 'calibration_trigger_date')) {
                $table->date('calibration_trigger_date')->nullable()->after('last_calibration_date');
            }
            if (! Schema::hasColumn('gauges', 'certificate_number')) {
                $table->string('certificate_number')->nullable()->after('calibration_trigger_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gauges', function (Blueprint $table) {
            foreach ([
                'measuring_range',
                'least_count',
                'calibration_agency',
                'calibration_frequency_months',
                'calibration_trigger_date',
                'certificate_number',
            ] as $column) {
                if (Schema::hasColumn('gauges', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
