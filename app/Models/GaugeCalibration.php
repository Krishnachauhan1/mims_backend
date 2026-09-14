<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaugeCalibration extends Model
{
    protected $fillable = [
        'gauge_id',
        'calibration_date',
        'next_due_date',
        'performed_by',
        'result',
        'certificate_ref',
        'document_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'calibration_date' => 'date',
            'next_due_date' => 'date',
        ];
    }

    public function gauge(): BelongsTo
    {
        return $this->belongsTo(Gauge::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
