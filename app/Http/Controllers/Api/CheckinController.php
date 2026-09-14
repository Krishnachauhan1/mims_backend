<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\ProductionBatch;
use App\Services\JobWorkFlowService;
use App\Services\MrnNumberService;
use App\Services\PoCheckinItemService;
use App\Services\PurchaseOrderCheckinService;
use App\Services\QualityInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CheckinController extends Controller
{
    public function __construct(
        private PurchaseOrderCheckinService $purchaseOrderCheckin,
        private PoCheckinItemService $poItems,
        private MrnNumberService $mrnNumbers,
        private QualityInspectionService $qualityService,
        private JobWorkFlowService $jobWorkFlow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $type = $request->string('type', 'checkin')->toString();

        if ($type === 'checkin') {
            $this->purchaseOrderCheckin->syncApprovedOrders();
        }

        $rows = InventoryTransaction::query()
            ->where('type', $type)
            ->latest()
            ->get()
            ->map(fn (InventoryTransaction $transaction) => $this->format($transaction));

        return response()->json($rows);
    }

    public function checkin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'po_number' => 'required|string|max:255',
            'pr_number' => 'nullable|string|max:255',
            'supplier' => 'nullable|string|max:255',
            'batch_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.ordered_quantity' => 'nullable|integer|min:1',
            'items.*.unit' => 'nullable|string|max:50',
        ]);

        $items = $this->poItems->normalizeTrackedItems($data['items']);
        $totalReceived = $this->poItems->totalReceivedQuantity($items);
        $phase = $this->poItems->derivePoPhase($items);

        $transaction = DB::transaction(function () use ($data, $items, $totalReceived, $phase, $request) {
            $existing = InventoryTransaction::query()
                ->where('type', 'checkin')
                ->where('po_number', $data['po_number'])
                ->where(function ($query): void {
                    $query->whereNull('flow_source')
                        ->orWhere('flow_source', 'purchase_order');
                })
                ->first();

            if ($existing !== null) {
                $mergedItems = $this->poItems->normalizeTrackedItems($items, $existing->items);
                $phase = $this->poItems->derivePoPhase($mergedItems);
                $updates = [
                    'material_name' => $data['po_number'],
                    'supplier' => $data['supplier'] ?? $existing->supplier,
                    'quantity' => $this->poItems->totalReceivedQuantity($mergedItems),
                    'unit' => 'mixed',
                    'batch_number' => $data['batch_number'] ?? $existing->batch_number,
                    'pr_number' => $data['pr_number'] ?? $existing->pr_number,
                    'items' => $mergedItems,
                    'notes' => $data['notes'] ?? $existing->notes,
                    'status' => $phase,
                    'receipt_phase' => $phase,
                    'flow_source' => 'purchase_order',
                    'user_id' => $request->user()?->id ?? $existing->user_id,
                ];

                if (! filled($existing->mrn_number)) {
                    $updates['mrn_number'] = $this->mrnNumbers->nextMrnNumber();
                }

                $existing->update($updates);

                return $existing->fresh();
            }

            return InventoryTransaction::create([
                'type' => 'checkin',
                'po_number' => $data['po_number'],
                'flow_source' => 'purchase_order',
                'material_name' => $data['po_number'],
                'supplier' => $data['supplier'] ?? null,
                'quantity' => $totalReceived,
                'unit' => 'mixed',
                'batch_number' => $data['batch_number'] ?? $data['po_number'],
                'pr_number' => $data['pr_number'] ?? null,
                'mrn_number' => $this->mrnNumbers->nextMrnNumber(),
                'items' => $items,
                'notes' => $data['notes'] ?? null,
                'status' => $phase,
                'receipt_phase' => $phase,
                'user_id' => $request->user()?->id,
            ]);
        });

        return response()->json($this->format($transaction));
    }

    public function receiveItems(Request $request, InventoryTransaction $transaction): JsonResponse
    {
        if ($transaction->type !== 'checkin') {
            return response()->json(['message' => 'Only check-in records accept item receipts'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:1000',
        ]);

        $isJobWork = $transaction->flow_source === JobWorkFlowService::FLOW_SOURCE;

        $transaction = DB::transaction(function () use ($transaction, $data, $request, $isJobWork) {
            $this->assertCheckinAttachments($transaction);

            $items = $this->trackedItems($transaction);
            $indexed = [];
            foreach ($items as $item) {
                $indexed[$item['item_name']] = $item;
            }

            $poNotes = trim((string) ($transaction->notes ?? ''));
            $receivedNow = 0;

            foreach ($data['items'] as $incoming) {
                $name = trim($incoming['item_name']);
                $qty = (int) $incoming['quantity'];
                $note = trim((string) ($incoming['notes'] ?? ''));
                $receivedNow += $qty;

                if (! isset($indexed[$name])) {
                    throw ValidationException::withMessages([
                        'items' => ["Item '$name' is not part of this check-in record"],
                    ]);
                }

                $item = $indexed[$name];
                $pending = (int) ($item['pending_quantity'] ?? 0);

                if ($qty > $pending) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "'$name' exceeds pending quantity ($pending {$item['unit']} remaining)",
                        ],
                    ]);
                }

                $this->qualityService->sendCheckinToQuality(
                    $transaction,
                    $name,
                    $qty,
                    $item['unit'],
                );

                $newReceived = (int) $item['received_quantity'] + $qty;
                $history = is_array($item['receipt_history'] ?? null)
                    ? $item['receipt_history']
                    : [];
                $history[] = [
                    'quantity' => $qty,
                    'notes' => $note,
                    'received_at' => now()->format('Y-m-d H:i'),
                    'qc_status' => 'pending',
                ];

                $lineStatus = 'quality_check';

                $indexed[$name] = $this->poItems->buildItem(
                    $name,
                    (int) $item['ordered_quantity'],
                    $newReceived,
                    $item['unit'],
                    $lineStatus,
                    $history,
                    (int) ($item['qc_passed_quantity'] ?? 0),
                );

                if ($note !== '') {
                    $line = "$name (+$qty {$item['unit']}): $note";
                    $poNotes = $poNotes === '' ? $line : "$poNotes\n$line";
                }
            }

            $updatedItems = array_values($indexed);
            $phase = $isJobWork
                ? $this->poItems->deriveJobWorkPhase($updatedItems)
                : $this->poItems->derivePoPhase($updatedItems);

            $transaction->update([
                'items' => $updatedItems,
                'quantity' => $this->poItems->totalReceivedQuantity($updatedItems),
                'receipt_phase' => $phase,
                'status' => $phase,
                'notes' => $poNotes !== '' ? $poNotes : $transaction->notes,
                'user_id' => $request->user()?->id ?? $transaction->user_id,
            ]);

            return $transaction->fresh();
        });

        return response()->json($this->format($transaction));
    }

    public function markItemsReceived(Request $request, InventoryTransaction $transaction): JsonResponse
    {
        if ($transaction->type !== 'checkin') {
            return response()->json(['message' => 'Only check-in records can be marked received'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($transaction->flow_source === JobWorkFlowService::FLOW_SOURCE) {
            throw ValidationException::withMessages([
                'item_names' => ['Job work returns are completed when all quantity is received — use Receive Item.'],
            ]);
        }

        $data = $request->validate([
            'item_names' => 'nullable|array|min:1',
            'item_names.*' => 'string|max:255',
        ]);

        $transaction = DB::transaction(function () use ($transaction, $data, $request) {
            $items = $this->trackedItems($transaction);
            $targets = $data['item_names'] ?? null;

            $updatedItems = [];
            foreach ($items as $item) {
                $name = $item['item_name'];
                $shouldMark = $targets === null || in_array($name, $targets, true);

                if ($shouldMark && $item['status'] === 'checked_in') {
                    if ((int) $item['received_quantity'] < (int) $item['ordered_quantity']) {
                        throw ValidationException::withMessages([
                            'item_names' => [
                                "'$name' still has pending quantity — receive remaining stock first",
                            ],
                        ]);
                    }

                    $item['status'] = 'order_received';
                }

                $updatedItems[] = $item;
            }

            $phase = $this->poItems->derivePoPhase($updatedItems);

            $transaction->update([
                'items' => $updatedItems,
                'receipt_phase' => $phase,
                'status' => $phase,
                'user_id' => $request->user()?->id ?? $transaction->user_id,
            ]);

            return $transaction->fresh();
        });

        return response()->json($this->format($transaction));
    }

    public function dispatchItems(Request $request, InventoryTransaction $transaction): JsonResponse
    {
        if ($transaction->type !== 'checkout') {
            return response()->json(
                ['message' => 'Only check-out records accept item dispatch'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.inventory_id' => 'nullable|integer|exists:inventory_items,id',
            'items.*.notes' => 'nullable|string|max:1000',
        ]);

        $transaction = DB::transaction(function () use ($transaction, $data, $request) {
            $items = $this->checkoutTrackedItems($transaction);
            $indexed = [];
            foreach ($items as $item) {
                $indexed[$item['item_name']] = $item;
            }

            $notes = trim((string) ($transaction->notes ?? ''));

            foreach ($data['items'] as $incoming) {
                $name = trim((string) $incoming['item_name']);
                $qty = (int) $incoming['quantity'];
                $note = trim((string) ($incoming['notes'] ?? ''));

                if (! isset($indexed[$name])) {
                    throw ValidationException::withMessages([
                        'items' => ["Item '$name' is not part of this checkout record"],
                    ]);
                }

                $item = $indexed[$name];
                $available = (int) ($item['available_quantity'] ?? 0);
                $dispatched = (int) ($item['dispatched_quantity'] ?? 0);
                $pending = max(0, $available - $dispatched);

                if ($qty > $pending) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "'$name' exceeds pending dispatch quantity ($pending {$item['unit']} remaining)",
                        ],
                    ]);
                }

                $this->dispatchFromInventory($incoming, $item);

                $newDispatched = $dispatched + $qty;
                $history = is_array($item['dispatch_history'] ?? null)
                    ? $item['dispatch_history']
                    : [];
                $history[] = [
                    'quantity' => $qty,
                    'notes' => $note,
                    'dispatched_at' => now()->format('Y-m-d H:i'),
                ];

                $indexed[$name] = [
                    ...$item,
                    'dispatched_quantity' => $newDispatched,
                    'pending_quantity' => max(0, $available - $newDispatched),
                    'dispatch_history' => array_values($history),
                    'status' => $newDispatched >= $available
                        ? 'dispatched'
                        : ($newDispatched > 0 ? 'partial' : 'pending'),
                ];

                if ($note !== '') {
                    $line = "$name (-$qty {$item['unit']}): $note";
                    $notes = $notes === '' ? $line : "$notes\n$line";
                }
            }

            $updatedItems = array_values($indexed);

            $transaction->update([
                'items' => $updatedItems,
                'quantity' => $this->poItems->totalDispatchedQuantity($updatedItems),
                'status' => $this->deriveCheckoutDispatchStatus($updatedItems),
                'notes' => $notes !== '' ? $notes : $transaction->notes,
                'user_id' => $request->user()?->id ?? $transaction->user_id,
            ]);

            return $transaction->fresh();
        });

        return response()->json($this->format($transaction));
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'po_number' => 'nullable|string|max:255',
            'material_name' => 'nullable|string|max:255',
            'quantity' => 'nullable|integer|min:1',
            'unit' => 'nullable|string|max:50',
            'batch_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'destination' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:100',
            'chalan_number' => 'nullable|string|max:255',
            'vehicle_number' => 'nullable|string|max:255',
            'driver_name' => 'nullable|string|max:255',
            'items' => 'nullable|array|min:1',
            'items.*.item_name' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit' => 'nullable|string|max:50',
        ]);

        $items = ! empty($data['items'])
            ? $this->poItems->normalizeCheckoutItems($data['items'])
            : [];
        if (! empty($data['items']) && $items === []) {
            return response()->json([
                'message' => 'Each checkout item needs a valid name and quantity',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $poNumber = filled($data['po_number'] ?? null) ? trim((string) $data['po_number']) : null;
        $isPoLevel = $poNumber !== null && $items !== [];
        $isGroupedItems = ! $isPoLevel && $items !== [];

        if (! $isPoLevel && ! $isGroupedItems && empty($data['material_name'])) {
            return response()->json([
                'message' => 'Material name is required, or send items for grouped checkout',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $notes = $this->appendCheckoutMetaNotes($data['notes'] ?? null, $data);

        if ($isPoLevel || $isGroupedItems) {
            $totalQty = $this->poItems->totalLineQuantity($items);
            if ($totalQty < 1) {
                return response()->json([
                    'message' => 'Checkout quantity must be at least 1',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $materialName = $isPoLevel
                ? $poNumber
                : $this->groupedCheckoutLabel($items, $data);

            try {
                $transaction = InventoryTransaction::create([
                    'type' => 'checkout',
                    'po_number' => $poNumber,
                    'material_name' => $materialName,
                    'items' => $items,
                    'quantity' => $totalQty,
                    'unit' => 'mixed',
                    'batch_number' => $data['batch_number']
                        ?? $data['chalan_number']
                        ?? $poNumber
                        ?? $materialName,
                    'notes' => $notes,
                    'destination' => $data['destination'] ?? null,
                    'status' => $data['status'] ?? 'completed',
                    'flow_source' => $isPoLevel ? 'purchase_order' : 'grouped_challan',
                    'user_id' => $request->user()?->id,
                ]);
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'message' => 'Checkout failed — run migrations and upload latest CheckinController',
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else {
            try {
                $transaction = InventoryTransaction::create([
                    'type' => 'checkout',
                    'material_name' => trim((string) $data['material_name']),
                    'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
                    'unit' => $data['unit'] ?? 'pcs',
                    'batch_number' => $data['batch_number'] ?? null,
                    'notes' => $notes,
                    'destination' => $data['destination'] ?? null,
                    'status' => $data['status'] ?? 'completed',
                    'user_id' => $request->user()?->id,
                ]);
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'message' => 'Checkout failed — run migrations and upload latest CheckinController',
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return response()->json($this->format($transaction->fresh()), 201);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $data
     */
    private function groupedCheckoutLabel(array $items, array $data): string
    {
        if (count($items) === 1) {
            return (string) ($items[0]['item_name'] ?? 'Checkout');
        }

        $chalan = trim((string) ($data['chalan_number'] ?? ''));
        if ($chalan !== '') {
            return "Challan $chalan";
        }

        $batch = trim((string) ($data['batch_number'] ?? ''));
        if ($batch !== '') {
            return "Challan $batch";
        }

        return 'Grouped checkout ('.count($items).' items)';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function appendCheckoutMetaNotes(?string $notes, array $data): ?string
    {
        $lines = array_values(array_filter([
            filled($data['chalan_number'] ?? null)
                ? 'Challan: '.trim((string) $data['chalan_number'])
                : null,
            filled($data['vehicle_number'] ?? null)
                ? 'Vehicle: '.trim((string) $data['vehicle_number'])
                : null,
            filled($data['driver_name'] ?? null)
                ? 'Driver: '.trim((string) $data['driver_name'])
                : null,
        ]));

        $base = trim((string) ($notes ?? ''));
        if ($lines === []) {
            return $base !== '' ? $base : null;
        }

        $meta = implode("\n", $lines);

        return $base !== '' ? "$base\n$meta" : $meta;
    }

    public function update(Request $request, InventoryTransaction $transaction): JsonResponse
    {
        $data = $request->validate([
            'receipt_phase' => 'nullable|string|in:order_placed,partial,checked_in,order_received',
            'status' => 'nullable|string|in:order_placed,partial,checked_in,order_received,completed',
        ]);

        $transaction->update($data);

        return response()->json($this->format($transaction->fresh()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkoutTrackedItems(InventoryTransaction $transaction): array
    {
        $rawItems = is_array($transaction->items) ? $transaction->items : [];

        return array_values(array_map(function (array $item): array {
            $available = (int) (
                $item['available_quantity']
                ?? $item['ordered_quantity']
                ?? $item['quantity']
                ?? 0
            );
            $available = max(0, $available);
            $dispatched = min((int) ($item['dispatched_quantity'] ?? 0), $available);

            return [
                'item_name' => trim((string) ($item['item_name'] ?? '')),
                'ordered_quantity' => $available,
                'available_quantity' => $available,
                'quantity' => $available,
                'dispatched_quantity' => $dispatched,
                'pending_quantity' => max(0, $available - $dispatched),
                'unit' => (string) ($item['unit'] ?? 'pcs'),
                'inventory_id' => $item['inventory_id'] ?? null,
                'batch_number' => $item['batch_number'] ?? null,
                'status' => (string) ($item['status'] ?? (
                    $dispatched >= $available && $available > 0
                        ? 'dispatched'
                        : ($dispatched > 0 ? 'partial' : 'pending')
                )),
                'dispatch_history' => is_array($item['dispatch_history'] ?? null)
                    ? $item['dispatch_history']
                    : [],
            ];
        }, array_filter($rawItems, fn ($raw) => is_array($raw))));
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $item
     */
    private function dispatchFromInventory(array $incoming, array $item): void
    {
        $qty = (int) ($incoming['quantity'] ?? 0);
        $itemName = trim((string) ($item['item_name'] ?? ''));
        if ($qty < 1 || $itemName === '') {
            return;
        }

        $inventoryId = $incoming['inventory_id'] ?? $item['inventory_id'] ?? null;
        $inventory = null;

        if ($inventoryId !== null) {
            $inventory = InventoryItem::query()->find($inventoryId);
        }

        if ($inventory === null) {
            $inventory = InventoryItem::query()
                ->where('name', $itemName)
                ->where('quantity', '>=', $qty)
                ->orderBy('id')
                ->first();
        }

        if ($inventory === null) {
            throw ValidationException::withMessages([
                'items' => ["Insufficient stock for '$itemName'"],
            ]);
        }

        if ((int) $inventory->quantity < $qty) {
            throw ValidationException::withMessages([
                'items' => [
                    "'$itemName' only has {$inventory->quantity} {$inventory->unit} available",
                ],
            ]);
        }

        $inventory->update([
            'quantity' => max(0, (int) $inventory->quantity - $qty),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function deriveCheckoutDispatchStatus(array $items): string
    {
        if ($items === []) {
            return 'completed';
        }

        $allDispatched = true;
        $anyDispatched = false;

        foreach ($items as $item) {
            $available = (int) ($item['available_quantity'] ?? 0);
            $dispatched = (int) ($item['dispatched_quantity'] ?? 0);

            if ($dispatched > 0) {
                $anyDispatched = true;
            }

            if ($dispatched < $available) {
                $allDispatched = false;
            }
        }

        if ($allDispatched) {
            return 'completed';
        }

        if ($anyDispatched) {
            return 'partial';
        }

        return 'pending';
    }

    private function assertCheckinAttachments(InventoryTransaction $transaction): void
    {
        $count = Document::query()
            ->where('module', 'checkin')
            ->where('entity_id', (string) $transaction->id)
            ->count();

        if ($count < 1) {
            throw ValidationException::withMessages([
                'attachments' => 'Upload at least one attachment (invoice, delivery note, or photo) before receiving materials.',
            ]);
        }
    }

    private function receiveIntoInventory(
        InventoryTransaction $transaction,
        array $item,
    ): void {
        $itemName = $item['item_name'] ?? $transaction->material_name;
        $quantity = (int) ($item['quantity'] ?? 0);
        $unit = $item['unit'] ?? 'pcs';

        if ($itemName === null || trim($itemName) === '' || $quantity < 1) {
            return;
        }

        $reference = $transaction->po_number
            ?? $transaction->batch_number
            ?? ('CHK-'.$transaction->id);

        InventoryController::receivePurchaseOrderItem(
            [
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit' => $unit,
            ],
            $reference,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trackedItems(InventoryTransaction $transaction): array
    {
        $raw = $transaction->items;
        if (! is_array($raw) || $raw === []) {
            if (
                $transaction->flow_source === JobWorkFlowService::FLOW_SOURCE
                && filled($transaction->material_name)
            ) {
                $raw = [[
                    'item_name' => $transaction->material_name,
                    'ordered_quantity' => max(1, (int) $transaction->quantity),
                    'received_quantity' => 0,
                    'unit' => $transaction->unit ?? 'pcs',
                    'status' => 'pending',
                ]];
            } else {
                $raw = [];
            }
        }

        return $this->poItems->normalizeTrackedItems($raw);
    }

    private function format(InventoryTransaction $transaction): array
    {
        $items = $this->trackedItems($transaction);
        $isPoLevel = $transaction->po_number !== null
            && $transaction->flow_source !== 'job_work'
            && (
                $transaction->flow_source === 'purchase_order'
                || count($items) > 1
            );

        $displayName = $isPoLevel && $transaction->po_number !== null
            ? $transaction->po_number
            : ($transaction->material_name ?? '—');

        $receivedCount = count(array_filter(
            $items,
            fn (array $item) => in_array($item['status'], ['partial', 'checked_in', 'order_received'], true),
        ));

        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'material_name' => $displayName,
            'supplier' => $transaction->supplier,
            'destination' => $transaction->destination,
            'quantity' => $transaction->quantity,
            'unit' => $transaction->unit,
            'batch_number' => $transaction->batch_number,
            'po_number' => $transaction->po_number,
            'pr_number' => $transaction->pr_number,
            'mrn_number' => $transaction->mrn_number,
            'items' => $items,
            'item_count' => count($items),
            'received_item_count' => $receivedCount,
            'is_po_level' => $isPoLevel,
            'notes' => $transaction->notes,
            'status' => $transaction->status,
            'receipt_phase' => $transaction->receipt_phase,
            'user' => $transaction->user?->name ?? 'System',
            'datetime' => $transaction->created_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'),
            'flow_source' => $transaction->flow_source,
            'production_batch_id' => $transaction->production_batch_id,
            'job_work_id' => $transaction->job_work_id,
            'work_order_id' => $transaction->work_order_id,
        ];
    }
}
