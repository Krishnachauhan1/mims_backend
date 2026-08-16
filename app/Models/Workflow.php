<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    protected $fillable = [
        'name',
        'stages',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class);
    }
}
