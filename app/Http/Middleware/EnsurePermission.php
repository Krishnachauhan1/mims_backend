<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user || ! $this->permissions->userCan($user, $permission)) {
            abort(403, 'You do not have permission to access this section.');
        }

        return $next($request);
    }
}
