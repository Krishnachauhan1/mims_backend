<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => $this->permissions->roles(),
            'modules' => $this->permissions->modules(),
            'permissions' => $this->permissions->allPermissionKeys(),
        ]);
    }
}
