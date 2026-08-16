<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DashboardTask extends Model
{
    protected $fillable = [
        'task_name',
        'assigned_to',
        'assigned_to_user_id',
        'module',
        'status',
        'activity',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function materials(): HasMany
    {
        return $this->hasMany(DashboardTaskMaterial::class);
    }
}
