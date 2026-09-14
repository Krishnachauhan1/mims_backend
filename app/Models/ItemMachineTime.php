<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemMachineTime extends Model
{
    protected $fillable = [
        'item_id',
        'machine_id',
        'grade',
        'diameter_mm',
        'per_piece_time',
        'average_time',
    ];

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'machine_id' => 'integer',
            'diameter_mm' => 'float',
            'per_piece_time' => 'float',
            'average_time' => 'float',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machinery::class, 'machine_id');
    }
}
