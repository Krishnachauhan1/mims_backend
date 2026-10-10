<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gauge extends Model
{
    protected $fillable = [
        'name',
        'instrument_id',
        'type',
        'manufacturer',
        'location',
        'measuring_range',
        'least_count',
        'calibration_agency',
        'calibration_frequency_months',
        'status',
        'last_calibration_date',
        'calibration_trigger_date',
        'next_calibration_date',
        'certificate_number',
        'calibration_status',
    ];

    protected function casts(): array
    {
        return [
            'last_calibration_date' => 'date',
            'calibration_trigger_date' => 'date',
            'next_calibration_date' => 'date',
            'calibration_frequency_months' => 'integer',
        ];
    }

    public function calibrations(): HasMany
    {
        return $this->hasMany(GaugeCalibration::class);
    }
}
