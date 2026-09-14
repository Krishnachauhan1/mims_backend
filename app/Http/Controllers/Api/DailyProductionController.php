<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyProductionEntry;
use App\Models\Item;
use App\Models\Process;
use App\Models\WorkOrder;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyProductionController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->userCanAccess($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $perPage = min(500, max(5, (int) $request->integer('per_page', 20)));
        $page = max(1, (int) $request->integer('page', 1));
        $search = trim((string) $request->string('search', ''));
        $date = trim((string) $request->string('date', ''));
        $dateFrom = trim((string) $request->string('date_from', ''));
        $dateTo = trim((string) $request->string('date_to', ''));
        $shift = trim((string) $request->string('shift', ''));
        $machineId = $request->integer('machine_id');

        $query = DailyProductionEntry::query()
            ->with(['machine', 'operator', 'workOrder', 'item', 'process', 'creator'])
            ->latest('production_date')
            ->latest('id');

        if ($dateFrom !== '' && $dateTo !== '') {
            $query->whereDate('production_date', '>=', $dateFrom)
                ->whereDate('production_date', '<=', $dateTo);
        } elseif ($date !== '') {
            $query->whereDate('production_date', $date);
        }

        if (in_array($shift, ['day', 'night'], true)) {
            $query->where('shift', $shift);
        }

        if ($machineId > 0) {
            $query->where('machine_id', $machineId);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('route_card_no', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('process_name', 'like', "%{$search}%")
                    ->orWhereHas('operator', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('machine', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('machine_code', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (DailyProductionEntry $entry) => $this->format($entry))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->userCanManage($request)) {
            return response()->json(['message' => 'Forbidden — daily production permission required'], 403);
        }

        try {
            $data = $this->validatedPayload($request);
            $data['created_by'] = $user?->id;
            $data['updated_by'] = $user?->id;

            $entry = DailyProductionEntry::create($data);

            return response()->json(
                $this->format($entry->load(['machine', 'operator', 'workOrder', 'item', 'process', 'creator'])),
                201,
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to save daily production entry',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function update(Request $request, DailyProductionEntry $dailyProduction): JsonResponse
    {
        $user = $request->user();
        if (! $this->userCanManage($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $this->validatedPayload($request, $dailyProduction);
        $data['updated_by'] = $user?->id;

        $dailyProduction->update($data);

        return response()->json(
            $this->format($dailyProduction->fresh(['machine', 'operator', 'workOrder', 'item', 'process', 'creator'])),
        );
    }

    public function destroy(Request $request, DailyProductionEntry $dailyProduction): JsonResponse
    {
        $user = $request->user();
        if (! $this->userCanManage($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $dailyProduction->delete();

        return response()->json(['message' => 'Daily production entry deleted']);
    }

    private function userCanAccess(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCanAny($user, [
            'daily_production.view',
            'daily_production.manage',
            'production.view',
            'production.update',
        ]) || in_array($user->role, ['supervisor', 'production_manager'], true);
    }

    private function userCanManage(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCanAny($user, [
            'daily_production.manage',
            'production.update',
        ]) || in_array($user->role, ['supervisor', 'production_manager'], true);
    }

    private function validatedPayload(Request $request, ?DailyProductionEntry $entry = null): array
    {
        $data = $request->validate([
            'production_date' => 'nullable|date',
            'machine_id' => 'required|integer|exists:machinery,id',
            'operator_id' => 'required|integer|exists:users,id',
            'shift' => 'required|string|in:day,night',
            'start_time' => 'nullable|date_format:H:i',
            'stop_time' => 'nullable|date_format:H:i',
            'cycle_time' => 'nullable|numeric|min:0',
            'actual_quantity' => 'required|integer|min:0',
            'reject_quantity' => 'nullable|integer|min:0',
            'work_order_id' => 'nullable|integer|exists:work_orders,id',
            'route_card_no' => 'nullable|string|max:255',
            'item_id' => 'nullable|integer|exists:items,id',
            'item_name' => 'nullable|string|max:255',
            'process_id' => 'nullable|integer|exists:processes,id',
            'process_name' => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
        ]);

        $data['production_date'] = $data['production_date'] ?? now()->toDateString();
        $data['reject_quantity'] = $data['reject_quantity'] ?? 0;

        if (! empty($data['work_order_id'])) {
            $workOrder = WorkOrder::query()->find($data['work_order_id']);
            if ($workOrder) {
                $data['route_card_no'] = $workOrder->route_card_no ?: $data['route_card_no'] ?? null;
                if (empty($data['item_name']) && filled($workOrder->product)) {
                    $data['item_name'] = $workOrder->product;
                }
                if (empty($data['item_id']) && $workOrder->item_id) {
                    $data['item_id'] = $workOrder->item_id;
                }
            }
        }

        if (! empty($data['item_id'])) {
            $item = Item::query()->find($data['item_id']);
            if ($item) {
                $data['item_name'] = $item->name;
            }
        }

        if (! empty($data['process_id'])) {
            $process = Process::query()->find($data['process_id']);
            if ($process) {
                $data['process_name'] = $process->name;
            }
        }

        if (
            empty($data['cycle_time'])
            && ! empty($data['start_time'])
            && ! empty($data['stop_time'])
            && ($data['actual_quantity'] ?? 0) > 0
        ) {
            $data['cycle_time'] = $this->calculateCycleTimeMinutes(
                $data['start_time'],
                $data['stop_time'],
                (int) $data['actual_quantity'],
            );
        }

        return $data;
    }

    private function calculateCycleTimeMinutes(string $start, string $stop, int $quantity): ?float
    {
        try {
            $startAt = Carbon::createFromFormat('H:i', $this->normalizeTime($start));
            $stopAt = Carbon::createFromFormat('H:i', $this->normalizeTime($stop));

            if ($stopAt->lessThanOrEqualTo($startAt)) {
                $stopAt->addDay();
            }

            $minutes = $startAt->diffInSeconds($stopAt) / 60;

            return round($minutes / max(1, $quantity), 2);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeTime(string $value): string
    {
        $parts = explode(':', $value);

        return sprintf('%02d:%02d', (int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
    }

    private function formatTime(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = explode(':', $value);

        return sprintf('%02d:%02d', (int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
    }

    private function format(DailyProductionEntry $entry): array
    {
        $machine = $entry->machine;
        $operator = $entry->operator;

        return [
            'id' => $entry->id,
            'production_date' => $entry->production_date?->toDateString(),
            'machine_id' => $entry->machine_id,
            'machine_code' => $machine?->machine_code,
            'machine_name' => $machine?->name,
            'operator_id' => $entry->operator_id,
            'operator_name' => $operator?->name,
            'shift' => $entry->shift,
            'start_time' => $this->formatTime(
                $entry->start_time instanceof \DateTimeInterface
                    ? $entry->start_time->format('H:i')
                    : (is_string($entry->start_time) ? substr($entry->start_time, 0, 5) : null),
            ),
            'stop_time' => $this->formatTime(
                $entry->stop_time instanceof \DateTimeInterface
                    ? $entry->stop_time->format('H:i')
                    : (is_string($entry->stop_time) ? substr($entry->stop_time, 0, 5) : null),
            ),
            'cycle_time' => $entry->cycle_time,
            'actual_quantity' => $entry->actual_quantity,
            'reject_quantity' => $entry->reject_quantity,
            'work_order_id' => $entry->work_order_id,
            'route_card_no' => $entry->route_card_no,
            'item_id' => $entry->item_id,
            'item_name' => $entry->item_name ?: $entry->item?->name,
            'process_id' => $entry->process_id,
            'process_name' => $entry->process_name,
            'remarks' => $entry->remarks,
            'created_by' => $entry->created_by,
            'created_by_name' => $entry->creator?->name,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }
}
