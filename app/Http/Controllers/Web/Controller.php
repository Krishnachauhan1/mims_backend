<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller as BaseController;
use App\Services\PermissionService;

abstract class Controller extends BaseController
{
    protected function authorizePermission(string $permission): void
    {
        $user = auth()->user();
        if (! $user || ! app(PermissionService::class)->userCan($user, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}
