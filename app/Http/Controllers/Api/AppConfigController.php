<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function show(): JsonResponse
    {
        $config = $this->permissions->config();

        return response()->json([
            'version' => $config['version'] ?? 1,
            'description' => $config['description'] ?? null,
            'access_control' => $config['access_control'] ?? [],
            'permission_flow' => $config['permission_flow'] ?? [],
            'job_designations' => $config['job_designations'] ?? [],
        ]);
    }
}
