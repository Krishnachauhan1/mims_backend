<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machinery;
use App\Models\MaterialRequisition;
use App\Services\MaterialRequisitionService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialRequisitionController extends Controller
{
    public function __construct(
        private PermissionService $permissions,
        private MaterialRequisitionService $requisitions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->canView($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = MaterialRequisition::query()
            ->with(['inventoryItem', 'recipient', 'requestedBy', 'machinery'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->string('destination')->toString() === 'packing') {
            $query->where('destination_type', 'packing');
        } elseif ($request->filled('machinery_id')) {
            $query->where('machinery_id', $request->integer('machinery_id'))
                ->where('destination_type', 'machine');
        }

        if ($request->boolean('mine') && ! $this->canManage($request)) {
            $user = $request->user();
            $query->where(function ($builder) use ($user) {
                $matched = false;
                if ($user?->id !== null) {
                    $builder->where('requested_by_user_id', $user->id);
                    $matched = true;
                }
                if ($user?->machinery_id !== null) {
                    $method = $matched ? 'orWhere' : 'where';
                    $builder->{$method}(function ($machineScope) use ($user) {
                        $machineScope
                            ->where('destination_type', 'machine')
                            ->where('machinery_id', $user->machinery_id);
                    });
                }
            });
        }

        $rows = $query->get()->map(fn (MaterialRequisition $row) => $this->format($row));

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->canRequest($request)) {
            return response()->json(['message' => 'Forbidden — request permission required'], 403);
        }

        $data = $request->validate([
            'destination_type' => 'required|string|in:machine,packing',
            'machinery_id' => 'required_if:destination_type,machine|nullable|integer|exists:machinery,id',
            'inventory_item_id' => 'nullable|integer|exists:inventory_items,id',
            'item_name' => 'required_without:inventory_item_id|nullable|string|max:255',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        [$itemName, $unit] = $this->requisitions->resolveItemName(
            $data['inventory_item_id'] ?? null,
            trim((string) ($data['item_name'] ?? '')),
        );

        $requisition = MaterialRequisition::query()->create([
            ...$this->destinationFields($data),
            'source' => 'operator_request',
            'inventory_item_id' => $data['inventory_item_id'] ?? null,
            'item_name' => $itemName,
            'quantity' => $data['quantity'],
            'unit' => $data['unit'] ?? $unit,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'requested_by_user_id' => $user?->id,
            'requested_by_name' => $user?->name,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->format($requisition->load(['inventoryItem', 'recipient', 'requestedBy'])),
        ], 201);
    }

    public function assign(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return response()->json(['message' => 'Forbidden — manage permission required'], 403);
        }

        $data = $request->validate([
            'destination_type' => 'required|string|in:machine,packing',
            'machinery_id' => 'required_if:destination_type,machine|nullable|integer|exists:machinery,id',
            'inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
            'issue_immediately' => 'nullable|boolean',
        ]);

        [$itemName, $unit] = $this->requisitions->resolveItemName(
            (int) $data['inventory_item_id'],
            '',
        );

        $staff = $request->user();
        $issueNow = $request->boolean('issue_immediately');

        $requisition = MaterialRequisition::query()->create([
            ...$this->destinationFields($data),
            'source' => 'staff_assignment',
            'inventory_item_id' => (int) $data['inventory_item_id'],
            'item_name' => $itemName,
            'quantity' => $data['quantity'],
            'unit' => $data['unit'] ?? $unit,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'requested_by_user_id' => $staff?->id,
            'requested_by_name' => $staff?->name,
        ]);

        if ($issueNow) {
            $requisition = $this->requisitions->issue(
                $requisition,
                (int) $data['inventory_item_id'],
            );
            $requisition->update([
                'issued_by_user_id' => $staff?->id,
                'issued_by_name' => $staff?->name,
                'issued_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->format($requisition->fresh(['inventoryItem', 'recipient', 'requestedBy'])),
        ], 201);
    }

    public function issue(Request $request, MaterialRequisition $materialRequisition): JsonResponse
    {
        if (! $this->canManage($request)) {
            return response()->json(['message' => 'Forbidden — manage permission required'], 403);
        }

        $data = $request->validate([
            'inventory_item_id' => 'nullable|integer|exists:inventory_items,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $inventoryItemId = $data['inventory_item_id']
            ?? $materialRequisition->inventory_item_id;

        if ($inventoryItemId === null) {
            return response()->json([
                'message' => 'Select an inventory item to issue from stock.',
            ], 422);
        }

        $staff = $request->user();
        $requisition = $this->requisitions->issue(
            $materialRequisition,
            (int) $inventoryItemId,
        );

        $requisition->update([
            'issued_by_user_id' => $staff?->id,
            'issued_by_name' => $staff?->name,
            'issued_at' => now(),
            'review_notes' => $data['notes'] ?? $requisition->review_notes,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->format($requisition->fresh(['inventoryItem', 'recipient', 'requestedBy'])),
        ]);
    }

    public function reject(Request $request, MaterialRequisition $materialRequisition): JsonResponse
    {
        if (! $this->canManage($request)) {
            return response()->json(['message' => 'Forbidden — manage permission required'], 403);
        }

        if ($materialRequisition->status !== 'pending') {
            return response()->json(['message' => 'This request was already processed'], 422);
        }

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $staff = $request->user();
        $materialRequisition->update([
            'status' => 'rejected',
            'review_notes' => $data['notes'] ?? null,
            'issued_by_user_id' => $staff?->id,
            'issued_by_name' => $staff?->name,
            'issued_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->format($materialRequisition->fresh(['inventoryItem', 'recipient', 'requestedBy'])),
        ]);
    }

    private function format(MaterialRequisition $row): array
    {
        $inventory = $row->inventoryItem;

        return [
            'id' => $row->id,
            'destination_type' => $row->destination_type ?? 'machine',
            'machinery_id' => $row->machinery_id,
            'machine_name' => $row->machine_name,
            'machine_code' => $row->machine_code,
            'source' => $row->source,
            'inventory_item_id' => $row->inventory_item_id,
            'item_name' => $row->item_name,
            'quantity' => $row->quantity,
            'unit' => $row->unit,
            'status' => $row->status,
            'notes' => $row->notes,
            'recipient_user_id' => $row->recipient_user_id,
            'recipient_name' => $row->recipient_name,
            'requested_by_user_id' => $row->requested_by_user_id,
            'requested_by_name' => $row->requested_by_name,
            'issued_by_user_id' => $row->issued_by_user_id,
            'issued_by_name' => $row->issued_by_name,
            'issued_at' => $row->issued_at?->format('Y-m-d H:i'),
            'review_notes' => $row->review_notes,
            'created_at' => $row->created_at?->format('Y-m-d H:i'),
            'stock_available' => $inventory !== null ? (int) $inventory->quantity : null,
            'inventory_sku' => $inventory?->sku,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function destinationFields(array $data): array
    {
        $type = $data['destination_type'] ?? 'machine';
        if ($type === 'packing') {
            return [
                'destination_type' => 'packing',
                'machinery_id' => null,
                'machine_name' => 'Packing',
                'machine_code' => 'PACKING',
            ];
        }

        $machine = Machinery::query()->findOrFail($data['machinery_id']);

        return [
            'destination_type' => 'machine',
            'machinery_id' => $machine->id,
            'machine_name' => $machine->name,
            'machine_code' => $machine->machine_code,
        ];
    }

    private function canView(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCanAny($user, [
            'material_requisition.view',
            'material_requisition.request',
            'material_requisition.manage',
        ]);
    }

    private function canRequest(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCanAny($user, [
            'material_requisition.request',
            'material_requisition.manage',
        ]);
    }

    private function canManage(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCan($user, 'material_requisition.manage');
    }
}
