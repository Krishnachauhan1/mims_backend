<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()->latest();

        if ($request->user()) {
            $query->where(function ($builder) use ($request) {
                $builder
                    ->whereNull('user_id')
                    ->orWhere('user_id', $request->user()->id);
            });
        }

        $statsQuery = clone $query;

        $type = $request->string('type')->toString();
        if ($type !== '' && $type !== 'all') {
            $query->where('type', $type);
        }

        $severity = $request->string('severity')->toString();
        if ($severity !== '' && $severity !== 'all') {
            $query->where('severity', $severity);
        }

        $perPage = min(50, max(5, (int) $request->integer('per_page', 15)));
        $page = max(1, (int) $request->integer('page', 1));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Notification $notification) => $this->format($notification))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'unread_count' => (int) (clone $statsQuery)->where('is_read', false)->count(),
                'type_counts' => (clone $statsQuery)
                    ->selectRaw('type, COUNT(*) as aggregate')
                    ->groupBy('type')
                    ->pluck('aggregate', 'type'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'nullable|string|max:100',
            'severity' => 'nullable|string|max:50',
            'module' => 'nullable|string|max:100',
            'route' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $notification = Notification::query()->create([
            ...$validated,
            'type' => $validated['type'] ?? 'info',
            'severity' => $validated['severity'] ?? 'info',
            'user_id' => $validated['user_id'] ?? $request->user()?->id,
        ]);

        return response()->json($this->format($notification), 201);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $notification->update(['is_read' => true]);

        return response()->json($this->format($notification->fresh()));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $query = Notification::query()->where('is_read', false);

        if ($request->user()) {
            $query->where(function ($builder) use ($request) {
                $builder
                    ->whereNull('user_id')
                    ->orWhere('user_id', $request->user()->id);
            });
        }

        $query->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read']);
    }

    public function destroy(Notification $notification): JsonResponse
    {
        $notification->delete();

        return response()->json(['message' => 'Notification dismissed']);
    }

    private function format(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'type' => $notification->type,
            'severity' => $notification->severity ?? 'info',
            'module' => $notification->module ?? '—',
            'reference' => $notification->reference ?? '—',
            'route' => $notification->route,
            'is_read' => (bool) $notification->is_read,
            'time' => $notification->created_at?->format('Y-m-d H:i') ?? '—',
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
