<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('machinery');

        return response()->json([
            'success' => true,
            'data' => $this->formatProfile($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:30',
            'department' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $user->update($data);

        AuditService::log(
            action: 'Profile updated',
            module: 'Profile',
            description: 'Updated personal profile details',
            type: 'update',
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $this->formatProfile($user->fresh()->loadMissing('machinery')),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'module' => $log->module,
                'datetime' => $log->created_at?->format('Y-m-d H:i') ?? '—',
                'ip' => $log->ip_address ?? '—',
                'details' => $log->description ?? '—',
            ]);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    private function formatProfile($user): array
    {
        return [
            ...$this->permissions->userPayload($user),
            'is_active' => $user->is_active,
            'last_login' => $user->last_login_at?->format('Y-m-d H:i') ?? '—',
            'created_at' => $user->created_at?->format('Y-m-d') ?? '—',
            'role_label' => $this->permissions->roleLabel($user->role),
        ];
    }
}
