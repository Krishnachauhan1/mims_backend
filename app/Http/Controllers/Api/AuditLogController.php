<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('module')) {
            $query->where('module', $request->string('module'));
        }

        return response()->json(
            $query
                ->limit(200)
                ->get()
                ->map(fn (AuditLog $log) => $this->format($log)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|max:255',
            'module' => 'required|string|max:100',
            'description' => 'nullable|string',
            'type' => 'nullable|string|max:100',
            'user_name' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ]);

        $log = AuditLog::query()->create([
            ...$validated,
            'type' => $validated['type'] ?? AuditService::inferType($validated['action'], $validated['module']),
            'user_id' => $request->user()?->id,
            'user_name' => $validated['user_name'] ?? $request->user()?->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json($this->format($log), 201);
    }

    private function format(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'type' => $log->type ?? AuditService::inferType($log->action, $log->module),
            'action' => $log->action,
            'user' => $log->user_name ?? 'System',
            'module' => $log->module,
            'details' => $log->description ?? '—',
            'ip_address' => $log->ip_address ?? '—',
            'created_at' => $log->created_at?->format('Y-m-d H:i') ?? '—',
        ];
    }
}
