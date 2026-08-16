<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDeviceToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    public function sendToUser(
        User $user,
        string $title,
        string $body,
        array $data = [],
    ): void {
        $tokens = $user->deviceTokens()->pluck('token')->all();
        if ($tokens === []) {
            Log::info('FCM push skipped: no device tokens for user', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        }

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    public function sendToAll(
        string $title,
        string $body,
        array $data = [],
    ): void {
        $tokens = UserDeviceToken::query()->pluck('token')->all();
        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  array<int, string>  $excludeDesignations
     */
    public function sendToAllExceptDesignations(
        string $title,
        string $body,
        array $data = [],
        array $excludeDesignations = ['machine_operator'],
    ): void {
        $tokens = UserDeviceToken::query()
            ->whereHas('user', function ($query) use ($excludeDesignations): void {
                $query->where('is_active', true)
                    ->where(function ($builder) use ($excludeDesignations): void {
                        $builder->whereNull('designation')
                            ->orWhereNotIn('designation', $excludeDesignations);
                    });
            })
            ->pluck('token')
            ->all();

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    public function sendToAllExceptOperators(
        string $title,
        string $body,
        array $data = [],
    ): void {
        $tokens = UserDeviceToken::query()
            ->whereHas('user', function ($query): void {
                $query->where('is_active', true)
                    ->where(function ($builder): void {
                        $builder->where('role', '!=', 'operator')
                            ->where(function ($nested): void {
                                $nested->whereNull('designation')
                                    ->orWhereNotIn('designation', [
                                        'machine_operator',
                                        'production_operator',
                                    ]);
                            });
                    });
            })
            ->pluck('token')
            ->all();

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    public function sendToUsersWithPermissions(
        array $permissionKeys,
        string $title,
        string $body,
        array $data = [],
    ): void {
        $permissionService = app(PermissionService::class);
        $users = User::query()->where('is_active', true)->get();

        foreach ($users as $user) {
            $permissions = $permissionService->permissionsForUser($user);
            if (array_intersect($permissionKeys, $permissions) === []) {
                continue;
            }

            $this->sendToUser($user, $title, $body, $data);
        }
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $data
     */
    private function sendToTokens(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
    ): void {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if ($tokens === [] || ! $this->isConfigured()) {
            if (! $this->isConfigured()) {
                Log::warning('FCM push skipped: service not configured');
            }

            return;
        }

        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            Log::warning('FCM push skipped: access token error', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($accessToken === null) {
            Log::warning('FCM push skipped: could not obtain access token');

            return;
        }

        $projectId = (string) config('fcm.project_id');
        foreach ($tokens as $token) {
            try {
                $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => array_map(
                        static fn ($value) => (string) $value,
                        $data,
                    ),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => 'mims_alerts',
                        ],
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

            if ($response->failed()) {
                Log::warning('FCM push failed', [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);

                if ($response->status() === 404) {
                    UserDeviceToken::query()->where('token', $token)->delete();
                }
            }
            } catch (\Throwable $e) {
                Log::warning('FCM push skipped for token', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function isConfigured(): bool
    {
        return filled(config('fcm.project_id'))
            && filled(config('fcm.service_account_json'))
            && is_file($this->serviceAccountPath());
    }

    private function serviceAccountPath(): string
    {
        $jsonPath = (string) config('fcm.service_account_json');

        if ($jsonPath === '') {
            return '';
        }

        if (! str_starts_with($jsonPath, '/') && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $jsonPath)) {
            $jsonPath = base_path($jsonPath);
        }

        return $jsonPath;
    }

    private function accessToken(): ?string
    {
        $jsonPath = $this->serviceAccountPath();
        if ($jsonPath === '' || ! is_file($jsonPath)) {
            return null;
        }

        $credentials = new ServiceAccountCredentials(
            ['https://www.googleapis.com/auth/firebase.messaging'],
            $jsonPath,
        );

        $auth = $credentials->fetchAuthToken();

        return $auth['access_token'] ?? null;
    }
}
