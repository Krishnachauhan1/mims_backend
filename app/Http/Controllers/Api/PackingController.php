<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\PackingEntry;
use App\Services\PackingService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PackingController extends Controller
{
    public function __construct(
        private PackingService $packingService,
        private PermissionService $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->userCanAccess($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->packingService->syncFromPassedProductionInspections();

        $query = PackingEntry::query()
            ->with(['workOrder', 'qualityInspection'])
            ->latest('inspected_at')
            ->latest('id');

        $status = trim((string) $request->string('status', ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $search = trim((string) $request->string('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('batch_number', 'like', "%{$search}%")
                    ->orWhere('product_name', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%");
            });
        }

        $rows = $query->get();
        $entries = $rows->map(fn (PackingEntry $entry) => $this->format($entry));

        return response()->json([
            'success' => true,
            'data' => $entries,
            'meta' => [
                'total' => $entries->count(),
                'pending' => $entries->where('status', 'pending')->count(),
                'hold' => $entries->where('status', 'hold')->count(),
                'rejected' => $entries->where('status', 'rejected')->count(),
                'packed' => $entries->where('status', 'packed')->count(),
            ],
        ]);
    }

    public function update(Request $request, PackingEntry $packing): JsonResponse
    {
        if (! $this->userCanManage($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:packed,hold,rejected',
            'notes' => 'nullable|string|max:2000',
            'hold_reason' => 'nullable|string|max:500',
            'reject_reason' => 'nullable|string|max:500',
            'packed_qty' => 'nullable|integer|min:1',
        ]);

        $status = $data['status'];
        $notes = trim((string) ($data['notes'] ?? ''));
        $actor = $request->user()?->name ?? 'User';

        if ($status === 'hold' && trim((string) ($data['hold_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'hold_reason' => ['Hold reason is required.'],
            ]);
        }

        if ($status === 'rejected' && trim((string) ($data['reject_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'reject_reason' => ['Rejection reason is required.'],
            ]);
        }

        if ($notes === '') {
            throw ValidationException::withMessages([
                'notes' => ['Packing note is required.'],
            ]);
        }

        $attachmentCount = Document::query()
            ->where('module', 'packing')
            ->where('entity_id', (string) $packing->id)
            ->count();

        if ($attachmentCount < 1) {
            throw ValidationException::withMessages([
                'attachments' => ['Upload at least one packing report (PDF or JPEG).'],
            ]);
        }

        $updates = [
            'status' => $status,
            'notes' => $notes,
            'action_by' => $actor,
            'action_at' => now(),
            'hold_reason' => $status === 'hold' ? trim((string) $data['hold_reason']) : null,
            'reject_reason' => $status === 'rejected' ? trim((string) $data['reject_reason']) : null,
        ];

        if ($status === 'packed') {
            $packedQty = (int) ($data['packed_qty'] ?? $packing->quantity);
            $packedQty = min(max(1, $packedQty), max(1, (int) $packing->quantity));
            $updates['packed_qty'] = $packedQty;
            $updates['packed_by'] = $actor;
            $updates['packed_at'] = now();
        }

        $packing->update($updates);

        return response()->json([
            'success' => true,
            'message' => match ($status) {
                'packed' => 'Marked as packed',
                'hold' => 'Placed on hold',
                'rejected' => 'Marked as rejected',
                default => 'Packing updated',
            },
            'data' => $this->format($packing->fresh(['workOrder', 'qualityInspection'])),
        ]);
    }

    private function userCanAccess(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCanAny($user, [
            'packing.view',
            'packing.manage',
            'quality.view',
            'quality.approve',
        ]) || in_array($user->role, ['supervisor', 'production_manager', 'store_manager'], true);
    }

    private function userCanManage(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCanAny($user, [
            'packing.manage',
        ]) || in_array($user->role, ['supervisor', 'production_manager', 'store_manager'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(PackingEntry $entry): array
    {
        $attachmentCount = Document::query()
            ->where('module', 'packing')
            ->where('entity_id', (string) $entry->id)
            ->count();

        return [
            'id' => $entry->id,
            'quality_inspection_id' => $entry->quality_inspection_id,
            'production_batch_id' => $entry->production_batch_id,
            'work_order_id' => $entry->work_order_id,
            'batch_number' => $entry->batch_number,
            'product_name' => $entry->product_name,
            'item_name' => $entry->item_name,
            'item_code' => $entry->item_code,
            'client_name' => $entry->client_name,
            'quantity' => $entry->quantity,
            'packed_qty' => $entry->packed_qty,
            'remaining_qty' => max(0, (int) $entry->quantity - (int) $entry->packed_qty),
            'unit' => $entry->unit,
            'status' => $entry->status,
            'inspected_by' => $entry->inspected_by,
            'inspected_at' => $entry->inspected_at?->format('Y-m-d H:i'),
            'notes' => $entry->notes,
            'hold_reason' => $entry->hold_reason,
            'reject_reason' => $entry->reject_reason,
            'packed_by' => $entry->packed_by,
            'packed_at' => $entry->packed_at?->format('Y-m-d H:i'),
            'action_by' => $entry->action_by,
            'action_at' => $entry->action_at?->format('Y-m-d H:i'),
            'attachment_count' => $attachmentCount,
            'route_card_no' => $entry->workOrder?->route_card_no,
            'created_at' => $entry->created_at?->format('Y-m-d H:i'),
        ];
    }
}
