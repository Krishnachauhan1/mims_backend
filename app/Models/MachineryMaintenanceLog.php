<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineryMaintenanceLog extends Model
{
    protected $fillable = [
        'machinery_id',
        'type',
        'notes',
        'performed_at',
        'status',
        'performed_by',
    ];

    protected $casts = [
        'performed_at' => 'date',
    ];

    public function machinery(): BelongsTo
    {
        return $this->belongsTo(Machinery::class);
    }
}
