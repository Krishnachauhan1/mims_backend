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
        'status',
        'last_calibration_date',
        'next_calibration_date',
        'calibration_status',
    ];

    protected function casts(): array
    {
        return [
            'last_calibration_date' => 'date',
            'next_calibration_date' => 'date',
        ];
    }

    public function calibrations(): HasMany
    {
        return $this->hasMany(GaugeCalibration::class);
    }
}
