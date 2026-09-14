<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;

class PermissionService
{
    private ?array $config = null;

    public function config(): array
    {
        if ($this->config === null) {
            $path = config_path('app_config.json');
            $this->config = File::exists($path)
                ? json_decode(File::get($path), true)
                : [];
        }

        return $this->config;
    }

    public function allPermissionKeys(): array
    {
        $keys = [];
        $modules = $this->config()['access_control']['modules'] ?? [];

        foreach ($modules as $module) {
            foreach ($module['permissions'] ?? [] as $permission) {
                $keys[] = $permission['key'];
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    public function permissionsForRole(string $role): array
    {
        $roles = $this->config()['access_control']['roles'] ?? [];

        foreach ($roles as $roleConfig) {
            if (($roleConfig['key'] ?? '') !== $role) {
                continue;
            }

            $permissions = $roleConfig['permissions'] ?? [];

            if (in_array('*', $permissions, true)) {
                return $this->allPermissionKeys();
            }

            return array_values($permissions);
        }

        return [];
    }

    public function modules(): array
    {
        return array_values($this->config()['access_control']['modules'] ?? []);
    }

    public function roles(): array
    {
        return array_values($this->config()['access_control']['roles'] ?? []);
    }

    public function roleKeys(): array
    {
        return array_values(array_map(
            fn (array $role) => $role['key'] ?? null,
            array_filter($this->roles(), fn (array $role) => ! empty($role['key'] ?? null)),
        ));
    }

    public function roleLabel(?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }

        foreach ($this->roles() as $role) {
            if (($role['key'] ?? '') === $key) {
                return $role['label'] ?? $key;
            }
        }

        return str_replace('_', ' ', ucwords($key, '_'));
    }

    public function jobDesignations(): array
    {
        return array_values($this->config()['job_designations'] ?? []);
    }

    public function jobDesignationKeys(): array
    {
        return array_values(array_filter(array_map(
            fn (array $designation) => $designation['key'] ?? null,
            $this->jobDesignations(),
        )));
    }

    public function designationLabel(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach ($this->jobDesignations() as $designation) {
            if (($designation['key'] ?? '') === $key) {
                return $designation['label'] ?? $key;
            }
        }

        return str_replace('_', ' ', ucwords($key, '_'));
    }

    public function designationRequiresMachinery(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        foreach ($this->jobDesignations() as $designation) {
            if (($designation['key'] ?? '') === $key) {
                return (bool) ($designation['requires_machinery'] ?? false);
            }
        }

        return false;
    }

    public function permissionsForUser(User $user): array
    {
        if ($user->permissions !== null) {
            return array_values($user->permissions);
        }

        return $this->permissionsForRole($user->role);
    }

    public function roleHasWildcard(string $role): bool
    {
        foreach ($this->roles() as $roleConfig) {
            if (($roleConfig['key'] ?? '') !== $role) {
                continue;
            }

            return in_array('*', $roleConfig['permissions'] ?? [], true);
        }

        return false;
    }

    public function userCan(User $user, string $permission): bool
    {
        if ($this->roleHasWildcard($user->role)) {
            return true;
        }

        $permissions = $this->permissionsForUser($user);

        return in_array($permission, $permissions, true);
    }

    public function userCanAny(User $user, array $permissions): bool
    {
        if ($this->roleHasWildcard($user->role)) {
            return true;
        }

        $granted = $this->permissionsForUser($user);

        foreach ($permissions as $permission) {
            if (in_array($permission, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    public function userPayload(User $user): array
    {
        $user->loadMissing('machinery');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'department' => $user->department,
            'designation' => $user->designation,
            'designation_label' => $this->designationLabel($user->designation),
            'machinery_id' => $user->machinery_id,
            'machinery_name' => $user->machinery?->name,
            'permissions' => $this->permissionsForUser($user),
        ];
    }

    public function userListPayload(User $user): array
    {
        $user->loadMissing('machinery');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'department' => $user->department ?? '—',
            'phone' => $user->phone,
            'designation' => $user->designation,
            'designation_label' => $this->designationLabel($user->designation),
            'machinery_id' => $user->machinery_id,
            'machinery_name' => $user->machinery?->name,
            'is_active' => $user->is_active,
            'permissions' => $this->permissionsForUser($user),
            'last_login' => $user->last_login_at?->format('Y-m-d H:i') ?? '—',
            'created_at' => $user->created_at?->format('Y-m-d') ?? '—',
        ];
    }
}
