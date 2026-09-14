<?php

namespace App\Support;

use App\Models\User;
use App\Services\PermissionService;

class WebNavigation
{
    public static function sections(User $user): array
    {
        $permissions = app(PermissionService::class);
        $modules = collect($permissions->modules())
            ->filter(fn (array $module) => ! empty($module['route']))
            ->filter(function (array $module) use ($permissions, $user) {
                $entry = $module['entry_permission'] ?? null;
                if (! $entry) {
                    return false;
                }

                return $permissions->userCan($user, $entry);
            })
            ->sortBy(fn (array $module) => (int) ($module['order'] ?? 99))
            ->values();

        $labels = [
            'main' => 'Main',
            'operations' => 'Operations',
            'insights' => 'Insights',
            'admin' => 'Administration',
        ];

        $grouped = [];
        foreach ($modules as $module) {
            $section = $module['section'] ?? 'main';
            $grouped[$section][] = [
                'id' => $module['id'],
                'name' => $module['name'],
                'route' => self::webRoute($module['route']),
                'icon' => $module['icon'] ?? 'folder_outlined',
            ];
        }

        $result = [];
        foreach ($labels as $key => $label) {
            if (empty($grouped[$key])) {
                continue;
            }
            $result[] = [
                'key' => $key,
                'label' => $label,
                'items' => $grouped[$key],
            ];
        }

        return $result;
    }

    public static function webRoute(string $flutterRoute): string
    {
        $path = ltrim($flutterRoute, '/');

        return $path === '' ? 'dashboard' : $path;
    }

    public static function routeIsActive(string $currentPath, string $itemRoute): bool
    {
        return trim($currentPath, '/') === trim($itemRoute, '/');
    }
}
