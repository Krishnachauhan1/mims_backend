<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('machinery')
            ->latest()
            ->get()
            ->map(fn (User $user) => $this->permissions->userListPayload($user));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $roles = implode(',', $this->permissions->roleKeys());
        $designations = implode(',', $this->permissions->jobDesignationKeys());

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|string|max:100|in:'.$roles,
            'department' => 'nullable|string|max:255',
            'designation' => 'nullable|string|max:100'.($designations !== '' ? '|in:'.$designations : ''),
            'machinery_id' => 'nullable|integer|exists:machinery,id',
            'is_active' => 'nullable|boolean',
            'permissions' => 'nullable|array',
        ]);

        if (
            $this->permissions->designationRequiresMachinery($data['designation'] ?? null)
            && empty($data['machinery_id'])
        ) {
            return response()->json([
                'message' => 'Machine is required for machine operators',
            ], 422);
        }

        if (! empty($data['machinery_id']) && ! $this->permissions->designationRequiresMachinery($data['designation'] ?? null)) {
            $data['machinery_id'] = null;
        }

        $permissions = $data['permissions'] ?? null;
        unset($data['permissions']);

        $user = User::create([
            ...$data,
            'password' => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
            'permissions' => $permissions,
        ]);

        return response()->json($this->permissions->userListPayload($user->load('machinery')), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $roles = implode(',', $this->permissions->roleKeys());
        $designations = implode(',', $this->permissions->jobDesignationKeys());

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'role' => 'sometimes|string|max:100|in:'.$roles,
            'department' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'designation' => 'nullable|string|max:100'.($designations !== '' ? '|in:'.$designations : ''),
            'machinery_id' => 'nullable|integer|exists:machinery,id',
            'password' => 'nullable|string|min:6',
        ]);

        if (
            array_key_exists('designation', $data)
            && $this->permissions->designationRequiresMachinery($data['designation'] ?? null)
            && empty($data['machinery_id'])
        ) {
            return response()->json([
                'message' => 'Machine is required for machine operators',
            ], 422);
        }

        if (
            array_key_exists('machinery_id', $data)
            && ! $this->permissions->designationRequiresMachinery($data['designation'] ?? $user->designation)
        ) {
            $data['machinery_id'] = null;
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json($this->permissions->userListPayload($user->fresh()->load('machinery')));
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $request->validate(['is_active' => 'required|boolean']);
        $user->update(['is_active' => $request->boolean('is_active')]);

        return response()->json($this->permissions->userListPayload($user->fresh()));
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        $request->validate(['permissions' => 'required|array']);
        $user->update(['permissions' => $request->permissions]);

        return response()->json($this->permissions->userListPayload($user->fresh()));
    }

    public function activity(User $user): JsonResponse
    {
        $logs = AuditLog::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'module' => $log->module,
                'datetime' => $log->created_at?->format('Y-m-d H:i') ?? '—',
                'ip' => $log->ip_address ?? '—',
                'details' => $log->description ?? '—',
            ]);

        return response()->json($logs);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ((int) $request->user()?->id === (int) $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account',
            ], 422);
        }

        if ($user->role === 'admin') {
            $otherActiveAdmins = User::query()
                ->where('role', 'admin')
                ->where('id', '!=', $user->id)
                ->where('is_active', true)
                ->exists();

            if (! $otherActiveAdmins) {
                return response()->json([
                    'message' => 'Cannot delete the last active administrator',
                ], 422);
            }
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }
}
