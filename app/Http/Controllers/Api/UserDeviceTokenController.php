<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserDeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'nullable|string|max:50',
            'device_name' => 'nullable|string|max:255',
        ]);

        $record = UserDeviceToken::query()->updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'id' => $record->id,
            'token' => $record->token,
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|max:512',
        ]);

        UserDeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['message' => 'Device token removed']);
    }
}
