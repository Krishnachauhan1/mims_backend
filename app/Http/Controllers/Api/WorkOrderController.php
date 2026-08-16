<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinishedGood;
use App\Models\WorkOrder;
use App\Models\Workflow;
use App\Services\AppNotificationService;
use App\Services\TaskMaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    public function __construct(private TaskMaterialService $taskMaterials) {}

    public function index(): JsonResponse
    {
        $query = WorkOrder::query()->latest();

        if ($this->materialsTableExists()) {
            $query->with(['materials.inventoryItem']);
        }

        return response()->json(
            $query->get()->map(fn (WorkOrder $order) => $this->format($order)),
        );
    }

    public function manufacturedProducts(): JsonResponse
    {
        $byProduct = [];

        $query = WorkOrder::query()
            ->whereNotNull('product')
            ->where('product', '!=', '')
            ->orderByDesc('updated_at');

        if ($this->materialsTableExists()) {
            $query->with(['materials.inventoryItem']);
        }

        $orders = $query->get();

        $ordersByProduct = [];
        foreach ($orders as $order) {
            $key = strtolower(trim($order->product));
            if ($key === '') {
                continue;
            }
            $ordersByProduct[$key][] = $order;
        }

        foreach ($ordersByProduct as $key => $productOrders) {
            $best = $productOrders[0];
            foreach ($productOrders as $order) {
                if (! empty($order->stages)) {
                    $best = $order;
                    break;
                }
            }

            $materialsSource = null;
            foreach ($productOrders as $order) {
                if ($this->materialsTableExists() && $order->materials->isNotEmpty()) {
                    $materialsSource = $order;
                    break;
                }
            }

            $option = $this->formatManufacturedProductOption($best);
            if ($materialsSource !== null) {
                $option['last_materials'] = $this->formatMaterialLines($materialsSource);
                $option['last_materials_at'] = ($materialsSource->updated_at ?? $materialsSource->created_at)
                    ?->format('Y-m-d H:i');
                $option['last_materials_route_card'] = $materialsSource->route_card_no;
                $option['last_materials_work_order_id'] = $materialsSource->id;
            }

            $byProduct[$key] = $option;
        }

        $finished = FinishedGood::query()
            ->whereNotNull('product_name')
            ->where('product_name', '!=', '')
            ->orderByDesc('updated_at')
            ->get();

        foreach ($finished as $fg) {
            $key = strtolower(trim($fg->product_name));
            if ($key === '' || isset($byProduct[$key])) {
                continue;
            }

            $stages = [];
            $workflowId = null;
            if (filled($fg->workflow)) {
                $workflow = Workflow::query()
                    ->where('name', $fg->workflow)
                    ->first();
                if ($workflow) {
                    $workflowId = $workflow->id;
                    $stages = $workflow->stages ?? [];
                }
            }

            $byProduct[$key] = [
                'name' => $fg->product_name,
                'product' => $fg->product_name,
                'unit' => $fg->unit ?? 'pcs',
                'client_id' => $fg->client_id,
                'workflow_id' => $workflowId,
                'workflow_name' => $fg->workflow,
                'stages' => $stages,
                'source' => 'finished_good',
            ];
        }

        return response()->json(array_values($byProduct));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        $materials = $validated['materials'] ?? [];
        unset($validated['materials']);

        if (! $request->filled('pr_number') && $materials === []) {
            throw ValidationException::withMessages([
                'materials' => ['At least one raw material is required.'],
            ]);
        }

        $order = WorkOrder::create($validated);

        if ($materials !== []) {
            $this->taskMaterials->attachMaterialsForWorkOrder($order, $materials);
        }

        if ($this->materialsTableExists()) {
            $order->load(['materials.inventoryItem']);
        }

        AppNotificationService::workflowAssigned($order);

        return response()->json($this->format($order), 201);
    }

    public function updateStatus(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'supervisor_status' => 'required|string|in:pending,wip,rejection,finished_goods',
            'updated_by' => 'nullable|string|max:255',
        ]);

        $previousStatus = $workOrder->supervisor_status ?? 'pending';

        $workOrder->update([
            'supervisor_status' => $data['supervisor_status'],
            'updated_by' => $data['updated_by'] ?? null,
        ]);

        if ($previousStatus !== $data['supervisor_status']) {
            AppNotificationService::workflowStatusChanged(
                $workOrder->fresh(),
                $previousStatus,
            );
        }

        return response()->json($this->format($workOrder->fresh(
            $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
        )));
    }

    private function materialsTableExists(): bool
    {
        return Schema::hasTable('work_order_materials');
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'workflow_id' => 'nullable|integer',
            'workflow_name' => 'nullable|string|max:255',
            'client_id' => 'nullable|integer',
            'client_name' => 'nullable|string|max:255',
            'vendor_id' => 'nullable|integer',
            'vendor_name' => 'nullable|string|max:255',
            'item_id' => 'nullable|integer',
            'product' => 'nullable|string|max:255',
            'item_code' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'quantity' => 'nullable|integer|min:1',
            'pr_number' => 'nullable|string|max:255',
            'route_card_no' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:2000',
            'stages' => 'nullable|array',
            'is_job_work' => 'nullable|boolean',
            'supervisor_status' => 'nullable|string|in:pending,wip,rejection,finished_goods',
            'flow_step' => 'nullable|string|max:100',
            'created_by' => 'nullable|string|max:255',
            'updated_by' => 'nullable|string|max:255',
            'materials' => [
                Rule::requiredIf(fn () => ! $request->filled('pr_number')),
                'nullable',
                'array',
            ],
            'materials.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'materials.*.quantity' => 'required|integer|min:1',
        ]);
    }

    private function formatManufacturedProductOption(WorkOrder $order): array
    {
        $stages = $order->stages ?? [];
        if (empty($stages) && $order->workflow_id) {
            $workflow = Workflow::query()->find($order->workflow_id);
            if ($workflow) {
                $stages = $workflow->stages ?? [];
            }
        }
        if (empty($stages) && filled($order->workflow_name)) {
            $workflow = Workflow::query()
                ->where('name', $order->workflow_name)
                ->first();
            if ($workflow) {
                $stages = $workflow->stages ?? [];
            }
        }

        return [
            'name' => $order->product,
            'product' => $order->product,
            'item_id' => $order->item_id,
            'item_code' => $order->item_code,
            'unit' => $order->unit ?? 'pcs',
            'quantity' => $order->quantity,
            'default_qty' => $order->quantity,
            'client_id' => $order->client_id,
            'client_name' => $order->client_name,
            'vendor_id' => $order->vendor_id,
            'vendor_name' => $order->vendor_name,
            'workflow_id' => $order->workflow_id,
            'workflow_name' => $order->workflow_name,
            'route_card_no' => $order->route_card_no ?? null,
            'stages' => $stages,
            'is_job_work' => (bool) $order->is_job_work,
            'source' => 'work_order',
            'last_work_order_id' => $order->id,
        ];
    }

    private function format(WorkOrder $order): array
    {
        if ($this->materialsTableExists()) {
            $order->loadMissing(['materials.inventoryItem']);
        }

        $materialLines = $this->materialsTableExists() && $order->relationLoaded('materials')
            ? $this->formatMaterialLines($order)
            : [];

        return [
            'id' => $order->id,
            'workflow_id' => $order->workflow_id,
            'workflow_name' => $order->workflow_name,
            'client_id' => $order->client_id,
            'client_name' => $order->client_name,
            'vendor_id' => $order->vendor_id,
            'vendor_name' => $order->vendor_name,
            'item_id' => $order->item_id,
            'product' => $order->product,
            'item_code' => $order->item_code,
            'unit' => $order->unit,
            'quantity' => $order->quantity,
            'pr_number' => $order->pr_number,
            'route_card_no' => $order->route_card_no,
            'remarks' => $order->remarks,
            'stages' => $order->stages ?? [],
            'is_job_work' => $order->is_job_work,
            'supervisor_status' => $order->supervisor_status,
            'flow_step' => $order->flow_step,
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'updated_at' => $order->updated_at?->format('Y-m-d H:i'),
            'created_by' => $order->created_by,
            'updated_by' => $order->updated_by,
            'materials' => $materialLines,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatMaterialLines(WorkOrder $order): array
    {
        if (! $this->materialsTableExists()) {
            return [];
        }

        $order->loadMissing(['materials.inventoryItem']);

        return $order->materials->map(fn ($line) => [
            'inventory_item_id' => $line->inventory_item_id,
            'name' => $line->inventoryItem?->name ?? '—',
            'quantity' => $line->quantity,
            'unit' => $line->unit ?? $line->inventoryItem?->unit ?? 'pcs',
            'sku' => $line->inventoryItem?->sku,
        ])->values()->all();
    }
}
