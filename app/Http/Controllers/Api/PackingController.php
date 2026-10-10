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
            if ($status === 'hold') {
                $query->where(function ($builder): void {
                    $builder
                        ->where('status', 'hold')
                        ->orWhere('hold_qty', '>', 0);
                });
            } else {
                $query->where('status', $status);
            }
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
                'hold' => $entries->filter(
                    fn (array $row) => ($row['status'] ?? '') === 'hold'
                        || (int) ($row['hold_qty'] ?? 0) > 0,
                )->count(),
                'rejected' => $entries->where('status', 'rejected')->count(),
                'partial' => $entries->where('status', 'partial')->count(),
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
            'status' => 'required|in:packed,hold,rejected,partial',
            'notes' => 'nullable|string|max:2000',
            'hold_reason' => 'nullable|string|max:500',
            'reject_reason' => 'nullable|string|max:500',
            'packed_qty' => 'nullable|integer|min:1',
            'hold_qty' => 'nullable|integer|min:1',
            'reject_qty' => 'nullable|integer|min:1',
            'rework_assigned_to' => 'nullable|string|max:255',
            'rework_assigned_to_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $status = $data['status'];
        $notes = trim((string) ($data['notes'] ?? ''));
        $actor = $request->user()?->name ?? 'User';

        if ($status === 'hold' && trim((string) ($data['hold_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'hold_reason' => ['Hold reason is required.'],
            ]);
        }

        if (in_array($status, ['rejected', 'partial'], true)
            && trim((string) ($data['reject_reason'] ?? '')) === '') {
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

        $remaining = $this->packingService->remainingQty($packing);

        $updates = [
            'status' => $status,
            'notes' => $notes,
            'action_by' => $actor,
            'action_at' => now(),
            'hold_reason' => $status === 'hold' ? trim((string) $data['hold_reason']) : null,
            'reject_reason' => in_array($status, ['rejected', 'partial'], true)
                ? trim((string) $data['reject_reason'])
                : null,
        ];

        if ($status === 'partial') {
            if ($remaining < 2) {
                throw ValidationException::withMessages([
                    'reject_qty' => ['Partial reject needs at least 2 units pending at packing.'],
                ]);
            }

            $rejectQty = (int) ($data['reject_qty'] ?? 0);
            if ($rejectQty < 1 || $rejectQty >= $remaining) {
                throw ValidationException::withMessages([
                    'reject_qty' => ["Enter a reject quantity between 1 and ".($remaining - 1).'.'],
                ]);
            }

            $assigneeName = trim((string) ($data['rework_assigned_to'] ?? ''));
            if ($assigneeName === '') {
                throw ValidationException::withMessages([
                    'rework_assigned_to' => ['Select a user to assign rejected items for rework.'],
                ]);
            }

            $packedQty = $remaining - $rejectQty;
            $updates['rejected_qty'] = (int) ($packing->rejected_qty ?? 0) + $rejectQty;
            $updates['packed_qty'] = (int) $packing->packed_qty + $packedQty;
            $updates['status'] = 'partial';
            $updates['packed_by'] = $actor;
            $updates['packed_at'] = now();

            $packing->update($updates);
            $packing = $packing->fresh(['workOrder', 'qualityInspection']);

            $this->packingService->handlePartialPackingRejectAndPack(
                $packing,
                $rejectQty,
                $packedQty,
                trim((string) $data['reject_reason']),
                $assigneeName,
                isset($data['rework_assigned_to_user_id'])
                    ? (int) $data['rework_assigned_to_user_id']
                    : null,
                $actor,
            );

            return response()->json([
                'success' => true,
                'message' => "{$rejectQty} rejected · {$packedQty} released to inventory",
                'data' => $this->format($packing->fresh(['workOrder', 'qualityInspection'])),
            ]);
        }

        if ($status === 'packed') {
            $packedQty = (int) ($data['packed_qty'] ?? $remaining);
            if ($remaining < 1) {
                throw ValidationException::withMessages([
                    'packed_qty' => ['Nothing left to pack for this batch.'],
                ]);
            }
            if ($packedQty < 1 || $packedQty > $remaining) {
                throw ValidationException::withMessages([
                    'packed_qty' => ["Enter pack quantity between 1 and {$remaining}."],
                ]);
            }
            $updates['packed_qty'] = (int) $packing->packed_qty + $packedQty;
            $updates['packed_by'] = $actor;
            $updates['packed_at'] = now();
            $remainingAfter = $remaining - $packedQty;
            $updates['status'] = $remainingAfter > 0 ? 'pending' : 'packed';
        }

        if ($status === 'hold') {
            $holdQty = (int) ($data['hold_qty'] ?? $remaining);
            if ($remaining < 1) {
                throw ValidationException::withMessages([
                    'hold_qty' => ['Nothing left to hold for this batch.'],
                ]);
            }
            if ($holdQty < 1 || $holdQty > $remaining) {
                throw ValidationException::withMessages([
                    'hold_qty' => ["Enter hold quantity between 1 and {$remaining}."],
                ]);
            }
            $updates['hold_qty'] = (int) ($packing->hold_qty ?? 0) + $holdQty;
            $remainingAfterHold = $remaining - $holdQty;
            $updates['status'] = $remainingAfterHold > 0 ? 'pending' : 'hold';
        }

        if ($status === 'rejected') {
            $updates['rejected_qty'] = (int) ($packing->rejected_qty ?? 0) + $remaining;
        }

        $packing->update($updates);
        $packing = $packing->fresh(['workOrder', 'qualityInspection']);

        if ($status === 'packed') {
            $packedThisAction = (int) ($data['packed_qty'] ?? $remaining);
            $packedThisAction = min(max(1, $packedThisAction), max(1, $remaining));
            $this->packingService->releasePackedStockToInventory(
                $packing,
                $packedThisAction,
            );
        } elseif ($status === 'rejected') {
            $this->packingService->handlePackingRejected($packing);
        }

        return response()->json([
            'success' => true,
            'message' => match ($status) {
                'packed' => isset($remainingAfter) && $remainingAfter > 0
                    ? "{$packedQty} packed · {$remainingAfter} still pending"
                    : 'Marked as packed',
                'hold' => isset($remainingAfterHold) && $remainingAfterHold > 0
                    ? "{$holdQty} on hold · {$remainingAfterHold} still pending"
                    : 'Placed on hold',
                'rejected' => 'Marked as rejected',
                'partial' => 'Partial reject — remainder released to inventory',
                default => 'Packing updated',
            },
            'data' => $this->format($packing),
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
            'rejected_qty' => (int) ($entry->rejected_qty ?? 0),
            'hold_qty' => (int) ($entry->hold_qty ?? 0),
            'remaining_qty' => $this->packingService->remainingQty($entry),
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
