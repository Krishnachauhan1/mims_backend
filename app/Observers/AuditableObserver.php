<?php

namespace App\Observers;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function created(Model $model): void
    {
        AuditService::logModelEvent('created', $model);
    }

    public function updated(Model $model): void
    {
        AuditService::logModelEvent('updated', $model);
    }

    public function deleted(Model $model): void
    {
        AuditService::logModelEvent('deleted', $model);
    }
}
