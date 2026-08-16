<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machinery extends Model
{
    protected $table = 'machinery';

    protected $fillable = [
        'sequence',
        'machine_code',
        'name',
        'type',
        'location',
        'capacity',
        'manufacturer',
        'status',
        'default_stage',
        'notes',
    ];

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MachineryMaintenanceLog::class);
    }
}
