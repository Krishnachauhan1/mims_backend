<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishedGood extends Model
{
    protected $fillable = [
        'product_name',
        'sku',
        'batch_number',
        'quantity',
        'stock',
        'dispatched',
        'status',
        'unit',
        'location',
        'warehouse',
        'quality_status',
        'production_date',
        'client_id',
        'source_batch_id',
        'flow_step',
        'pr_number',
        'workflow',
        'rack_id',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'quantity' => 'integer',
            'stock' => 'integer',
            'dispatched' => 'integer',
            'source_batch_id' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
