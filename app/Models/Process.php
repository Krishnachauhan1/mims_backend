<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Process extends Model
{
    protected $table = 'processes';

    protected $fillable = [
        'name',
        'description',
        'process_type',
        'rate',
        'unit',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
        ];
    }
}
