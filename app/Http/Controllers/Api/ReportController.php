<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FinishedGood;
use App\Models\Item;
use App\Models\BatchMaterialRequest;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MaterialRequisition;
use App\Models\WorkOrderMaterial;
use App\Models\Machinery;
use App\Models\PackingEntry;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\QualityInspection;
use App\Models\Rejection;
use App\Support\DisplayValue;
use App\Models\SalesOrder;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\BatchStageMachineService;
use App\Services\SpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function machineActiveWork(Request $request): JsonResponse
    {
        try {
            return response()->json($this->buildMachineActiveWorkPayload($request));
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/machine-active-work] failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to load machine active work report',
                'summary' => [
                    'total_machines' => 0,
                    'busy_machines' => 0,
                    'idle_machines' => 0,
                    'total_active_jobs' => 0,
                    'total_active_quantity' => 0,
                    'generated_at' => now()->format('Y-m-d H:i'),
                ],
                'machines' => [],
            ], 500);
        }
    }

    public function machineProcessingHistory(Request $request): JsonResponse
    {
        try {
            return response()->json($this->buildMachineProcessingHistoryPayload($request));
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/machine-processing-history] failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to load machine processing history',
                'summary' => [
                    'total_machines' => 0,
                    'total_events' => 0,
                    'generated_at' => now()->format('Y-m-d H:i'),
                ],
                'machines' => [],
            ], 500);
        }
    }

    public function machineProcessingHistoryExport(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $payload = $this->buildMachineProcessingHistoryPayload($request);
            $rows = $this->flattenMachineProcessingHistoryForExport(
                $payload['machines'] ?? [],
            );

            if ($rows === []) {
                return response()->json(['message' => 'No processing records to export'], 422);
            }

            $headers = [
                'Machine Code',
                'Machine Name',
                'Machine Type',
                'Location',
                'Batch',
                'Route Card',
                'Item Code',
                'Item / Product',
                'Grade',
                'Diameter (mm)',
                'Stage',
                'Quantity',
                'Unit',
                'Started At',
                'Processed At',
                'Duration',
                'Operator',
                'Batch Status',
                'In Progress',
            ];

            $filename = 'machine_processing_history_'.now()->format('Y-m-d_His').'.xlsx';

            return app(SpreadsheetService::class)->xlsxDownload($filename, $headers, $rows);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/machine-processing-history/export] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to export machine processing history'], 500);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $machines
     * @return array<int, array<string, string>>
     */
    private function flattenMachineProcessingHistoryForExport(array $machines): array
    {
        $rows = [];

        foreach ($machines as $machine) {
            if (! is_array($machine)) {
                continue;
            }

            $machineCode = (string) ($machine['machine_code'] ?? '');
            $machineName = (string) ($machine['machine_name'] ?? '');
            $machineType = (string) ($machine['type'] ?? '');
            $location = (string) ($machine['location'] ?? '');
            $events = $machine['events'] ?? [];

            if (! is_array($events)) {
                continue;
            }

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $inProgress = ($event['in_progress'] ?? false) === true
                    && ($event['batch_completed'] ?? false) !== true;

                $rows[] = [
                    'Machine Code' => $machineCode,
                    'Machine Name' => $machineName,
                    'Machine Type' => $machineType,
                    'Location' => $location,
                    'Batch' => (string) ($event['batch_number'] ?? ''),
                    'Route Card' => (string) ($event['route_card'] ?? ''),
                    'Item Code' => (string) ($event['item_code'] ?? ''),
                    'Item / Product' => (string) ($event['item_name'] ?? $event['product_name'] ?? ''),
                    'Grade' => (string) ($event['grade'] ?? ''),
                    'Diameter (mm)' => $event['diameter_mm'] !== null
                        ? (string) $event['diameter_mm']
                        : '',
                    'Stage' => (string) ($event['stage'] ?? ''),
                    'Quantity' => (string) ($event['quantity'] ?? ''),
                    'Unit' => (string) ($event['unit'] ?? 'pcs'),
                    'Started At' => (string) ($event['started_at'] ?? ''),
                    'Processed At' => (string) ($event['processed_at'] ?? ''),
                    'Duration' => (string) ($event['duration'] ?? ''),
                    'Operator' => (string) ($event['operator'] ?? ''),
                    'Batch Status' => (string) ($event['batch_status'] ?? ''),
                    'In Progress' => $inProgress ? 'Yes' : 'No',
                ];
            }
        }

        return $rows;
    }

    public function itemProductionHistory(Request $request): JsonResponse
    {
        try {
            $itemCode = trim($request->string('item_code')->toString());
            if ($itemCode === '') {
                return response()->json(['message' => 'Item code is required'], 422);
            }

            return response()->json($this->buildItemProductionHistoryPayload($itemCode));
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/item-production-history] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load item production history',
                'summary' => [
                    'item_code' => trim($request->string('item_code')->toString()),
                    'made_count' => 0,
                    'in_production_count' => 0,
                    'generated_at' => now()->format('Y-m-d H:i'),
                ],
                'made_history' => [],
                'in_production' => [],
            ], 500);
        }
    }

    public function itemProductionHistoryExport(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $itemCode = trim($request->string('item_code')->toString());
            if ($itemCode === '') {
                return response()->json(['message' => 'Item code is required'], 422);
            }

            $payload = $this->buildItemProductionHistoryPayload($itemCode);
            $rows = $this->flattenItemProductionHistoryForExport($payload);

            if ($rows === []) {
                return response()->json(['message' => 'No production records to export'], 422);
            }

            $headers = [
                'Section',
                'Item Code',
                'Item Name',
                'Batch',
                'Route Card',
                'Quantity',
                'Unit',
                'Status',
                'Current Stage',
                'Machine',
                'Machine Code',
                'Stage Order',
                'Total Stages',
                'Event At',
                'Started At',
                'Reference',
            ];

            $safeCode = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $itemCode) ?: 'item';
            $filename = 'item_production_history_'.$safeCode.'_'.now()->format('Y-m-d_His').'.xlsx';

            return app(SpreadsheetService::class)->xlsxDownload($filename, $headers, $rows);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/item-production-history/export] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to export item production history'], 500);
        }
    }

    public function rawMaterialHistory(Request $request): JsonResponse
    {
        try {
            $materialCode = trim($request->string('material_code')->toString());
            if ($materialCode === '') {
                return response()->json(['message' => 'Material code or SKU is required'], 422);
            }

            return response()->json($this->buildRawMaterialHistoryPayload($materialCode));
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/raw-material-history] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load raw material history',
                'summary' => [
                    'material_code' => trim($request->string('material_code')->toString()),
                    'received_count' => 0,
                    'in_use_count' => 0,
                    'stock_quantity' => 0,
                    'generated_at' => now()->format('Y-m-d H:i'),
                ],
                'received_history' => [],
                'in_use' => [],
            ], 500);
        }
    }

    public function rawMaterialHistoryExport(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $materialCode = trim($request->string('material_code')->toString());
            if ($materialCode === '') {
                return response()->json(['message' => 'Material code or SKU is required'], 422);
            }

            $payload = $this->buildRawMaterialHistoryPayload($materialCode);
            $rows = $this->flattenRawMaterialHistoryForExport($payload);

            if ($rows === []) {
                return response()->json(['message' => 'No raw material records to export'], 422);
            }

            $headers = [
                'Section',
                'Material',
                'SKU',
                'Kind',
                'Location',
                'Quantity',
                'Unit',
                'Status',
                'Event At',
                'PO Number',
                'MRN Number',
                'Supplier',
                'Check-in ID',
                'Reference',
            ];

            $safeCode = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $materialCode) ?: 'material';
            $filename = 'raw_material_history_'.$safeCode.'_'.now()->format('Y-m-d_His').'.xlsx';

            return app(SpreadsheetService::class)->xlsxDownload($filename, $headers, $rows);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/raw-material-history/export] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to export raw material history'], 500);
        }
    }

    public function rawMaterialRouteAllocation(Request $request): JsonResponse
    {
        try {
            return response()->json($this->buildRawMaterialRouteAllocationPayload(
                trim($request->string('material_code')->toString()),
                trim($request->string('item_code')->toString()),
                trim($request->string('route_card')->toString()),
            ));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/raw-material-route-allocation] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to load raw material route allocation'], 500);
        }
    }

    public function rawMaterialRouteAllocationExport(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $payload = $this->buildRawMaterialRouteAllocationPayload(
                trim($request->string('material_code')->toString()),
                trim($request->string('item_code')->toString()),
                trim($request->string('route_card')->toString()),
            );
            $rows = $this->flattenRawMaterialRouteAllocationForExport($payload);

            if ($rows === []) {
                return response()->json(['message' => 'No route card material assignments to export'], 422);
            }

            $headers = [
                'Route Card',
                'Item Code',
                'Product',
                'Client',
                'Order Qty',
                'Route Status',
                'Raw Material',
                'Material SKU',
                'Grade',
                'Diameter (mm)',
                'Allocated Qty',
                'Unit',
                'Assigned At',
                'Work Order ID',
            ];

            $filename = 'raw_material_route_allocation_'.now()->format('Y-m-d_His').'.xlsx';

            return app(SpreadsheetService::class)->xlsxDownload($filename, $headers, $rows);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/raw-material-route-allocation/export] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to export raw material route allocation'], 500);
        }
    }

    /**
     * @return array{summary: array<string, mixed>, rows: array<int, array<string, mixed>>}
     */
    private function buildRawMaterialRouteAllocationPayload(
        string $materialCode,
        string $itemCode,
        string $routeCard,
    ): array {
        if ($materialCode === '' && $itemCode === '' && $routeCard === '') {
            throw new \InvalidArgumentException(
                'Enter raw material code, item code, or route card number',
            );
        }

        if (! Schema::hasTable('work_order_materials')) {
            return [
                'summary' => [
                    'material_code' => $materialCode,
                    'item_code' => $itemCode,
                    'route_card' => $routeCard,
                    'match_count' => 0,
                    'generated_at' => now()->format('Y-m-d H:i'),
                ],
                'rows' => [],
            ];
        }

        $query = WorkOrderMaterial::query()
            ->with(['workOrder', 'inventoryItem'])
            ->whereHas('inventoryItem', function ($inventoryQuery): void {
                $inventoryQuery->where('type', 'raw_material');
            });

        if ($materialCode !== '') {
            $inventoryIds = $this->resolveRawMaterialInventoryItems($materialCode)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($inventoryIds !== []) {
                $query->whereIn('inventory_item_id', $inventoryIds);
            } else {
                $needle = '%'.strtolower($materialCode).'%';
                $query->where(function ($lineQuery) use ($needle): void {
                    $lineQuery->whereHas('inventoryItem', function ($inventoryQuery) use ($needle): void {
                        $inventoryQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(COALESCE(sku, "")) LIKE ?', [$needle]);
                    })->orWhereRaw('LOWER(COALESCE(grade, "")) LIKE ?', [$needle]);
                });
            }
        }

        if ($itemCode !== '') {
            $needle = '%'.strtolower($itemCode).'%';
            $query->whereHas('workOrder', function ($workOrderQuery) use ($needle): void {
                $workOrderQuery->whereRaw('LOWER(COALESCE(item_code, "")) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(product, "")) LIKE ?', [$needle]);
            });
        }

        if ($routeCard !== '') {
            $needle = '%'.strtolower($routeCard).'%';
            $query->whereHas('workOrder', function ($workOrderQuery) use ($needle): void {
                $workOrderQuery->whereRaw('LOWER(COALESCE(route_card_no, "")) LIKE ?', [$needle]);
            });
        }

        $lines = $query->orderByDesc('id')->limit(500)->get();

        $rows = [];
        foreach ($lines as $line) {
            $workOrder = $line->workOrder;
            $inventoryItem = $line->inventoryItem;
            $resolvedItemCode = DisplayValue::normalize($workOrder?->item_code)
                ?? DisplayValue::normalize($workOrder?->product)
                ?? '—';

            $rows[] = [
                'route_card_no' => (string) ($workOrder?->route_card_no ?? '—'),
                'item_code' => (string) $resolvedItemCode,
                'product' => (string) (DisplayValue::normalize($workOrder?->product) ?? '—'),
                'client_name' => (string) ($workOrder?->client_name ?? ''),
                'work_order_id' => (int) ($workOrder?->id ?? 0),
                'work_order_status' => (string) ($workOrder?->supervisor_status ?? ''),
                'order_quantity' => (int) ($workOrder?->quantity ?? 0),
                'raw_material_name' => (string) ($inventoryItem?->name ?? '—'),
                'raw_material_sku' => (string) ($inventoryItem?->sku ?? ''),
                'grade' => (string) ($line->grade ?? ''),
                'diameter_mm' => $line->diameter_mm,
                'allocated_quantity' => (int) $line->quantity,
                'unit' => (string) ($line->unit ?? $inventoryItem?->unit ?? 'pcs'),
                'assigned_at' => $line->created_at?->format('Y-m-d H:i'),
            ];
        }

        return [
            'summary' => [
                'material_code' => $materialCode,
                'item_code' => $itemCode,
                'route_card' => $routeCard,
                'match_count' => count($rows),
                'generated_at' => now()->format('Y-m-d H:i'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, string>>
     */
    private function flattenRawMaterialRouteAllocationForExport(array $payload): array
    {
        $rows = [];
        foreach ($payload['rows'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $rows[] = [
                'Route Card' => (string) ($entry['route_card_no'] ?? ''),
                'Item Code' => (string) ($entry['item_code'] ?? ''),
                'Product' => (string) ($entry['product'] ?? ''),
                'Client' => (string) ($entry['client_name'] ?? ''),
                'Order Qty' => (string) ($entry['order_quantity'] ?? ''),
                'Route Status' => (string) ($entry['work_order_status'] ?? ''),
                'Raw Material' => (string) ($entry['raw_material_name'] ?? ''),
                'Material SKU' => (string) ($entry['raw_material_sku'] ?? ''),
                'Grade' => (string) ($entry['grade'] ?? ''),
                'Diameter (mm)' => (string) ($entry['diameter_mm'] ?? ''),
                'Allocated Qty' => (string) ($entry['allocated_quantity'] ?? ''),
                'Unit' => (string) ($entry['unit'] ?? ''),
                'Assigned At' => (string) ($entry['assigned_at'] ?? ''),
                'Work Order ID' => (string) ($entry['work_order_id'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InventoryItem>  $inventoryItems
     * @param  array<int, int>  $inventoryIds
     * @return array<int, array<string, mixed>>
     */
    private function buildRawMaterialUsageHistory(
        \Illuminate\Support\Collection $inventoryItems,
        array $inventoryIds,
        string $needle,
    ): array {
        if ($inventoryIds === [] && $inventoryItems->isNotEmpty()) {
            $inventoryIds = $inventoryItems
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        /** @var array<int, array<string, mixed>> $usage */
        $usage = [];

        if ($inventoryIds === []) {
            return $this->sortRawMaterialUsageHistory(
                $this->checkoutsAsUsageHistoryByMaterialName($inventoryItems, $needle),
            );
        }

        $requisitions = MaterialRequisition::query()
            ->whereIn('inventory_item_id', $inventoryIds)
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        foreach ($requisitions as $requisition) {
            $location = $this->materialRequisitionLocationLabel($requisition);
            $eventAt = $requisition->issued_at ?? $requisition->created_at;
            $kind = $requisition->status === 'issued'
                ? 'issued_to_machine'
                : 'material_request';

            $usage[] = [
                'kind' => $kind,
                'location' => $location,
                'quantity' => (float) $requisition->quantity,
                'unit' => (string) ($requisition->unit ?? 'pcs'),
                'status' => (string) $requisition->status,
                'since_at' => $this->safeReportDateTime($eventAt),
                'reference' => trim(implode(' · ', array_filter([
                    $requisition->issued_by_name
                        ? 'Issued by '.$requisition->issued_by_name
                        : null,
                    $requisition->requested_by_name
                        ? 'Requested by '.$requisition->requested_by_name
                        : null,
                    $requisition->recipient_name
                        ? 'Recipient '.$requisition->recipient_name
                        : null,
                    filled($requisition->notes) ? trim((string) $requisition->notes) : null,
                ]))),
            ];
        }

        $batchRequests = BatchMaterialRequest::query()
            ->with('productionBatch.workOrder')
            ->whereIn('inventory_item_id', $inventoryIds)
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        foreach ($batchRequests as $batchRequest) {
            $batch = $batchRequest->productionBatch;
            $workOrder = $batch?->workOrder;
            $location = trim(implode(' · ', array_filter([
                $batch?->current_stage,
                $batch?->batch_number,
                $batchRequest->request_type,
            ])));

            $usage[] = [
                'kind' => 'batch_material',
                'location' => $location !== '' ? $location : 'Production batch',
                'quantity' => (float) $batchRequest->quantity,
                'unit' => (string) ($batchRequest->unit ?? 'pcs'),
                'status' => (string) $batchRequest->status,
                'since_at' => $this->safeReportDateTime(
                    $batchRequest->reviewed_at ?? $batchRequest->created_at,
                ),
                'item_code' => filled($workOrder?->item_code) ? (string) $workOrder->item_code : null,
                'route_card_no' => filled($workOrder?->route_card_no)
                    ? (string) $workOrder->route_card_no
                    : null,
                'reference' => trim(implode(' · ', array_filter([
                    $workOrder?->route_card_no ? 'Route '.$workOrder->route_card_no : null,
                    $workOrder?->item_code ? 'Item '.$workOrder->item_code : null,
                    $batchRequest->requested_by_name
                        ? 'By '.$batchRequest->requested_by_name
                        : null,
                ]))),
            ];
        }

        $checkouts = InventoryTransaction::query()
            ->where('type', 'checkout')
            ->orderByDesc('id')
            ->limit(600)
            ->get();

        foreach ($checkouts as $checkout) {
            $items = is_array($checkout->items) ? $checkout->items : [];
            $matchedLines = [];
            if ($items !== []) {
                foreach ($items as $line) {
                    if (is_array($line) && $this->checkinLineMatchesRawMaterial($line, $inventoryItems, $needle)) {
                        $matchedLines[] = $line;
                    }
                }
            } elseif ($this->transactionMatchesRawMaterial($checkout, $inventoryItems, $needle)) {
                $matchedLines[] = [
                    'item_name' => $checkout->material_name,
                    'quantity' => $checkout->quantity,
                    'unit' => $checkout->unit,
                ];
            }

            foreach ($matchedLines as $line) {
                $history = is_array($line['dispatch_history'] ?? null)
                    ? $line['dispatch_history']
                    : [];
                if ($history !== []) {
                    foreach ($history as $dispatch) {
                        if (! is_array($dispatch)) {
                            continue;
                        }
                        $qty = (int) ($dispatch['quantity'] ?? 0);
                        if ($qty <= 0) {
                            continue;
                        }
                        $usage[] = [
                            'kind' => 'checkout_dispatch',
                            'location' => filled($checkout->destination)
                                ? (string) $checkout->destination
                                : 'Checkout',
                            'quantity' => $qty,
                            'unit' => (string) ($line['unit'] ?? $checkout->unit ?? 'pcs'),
                            'status' => (string) ($checkout->status ?? 'dispatched'),
                            'since_at' => $this->safeReportDateTime(
                                $dispatch['dispatched_at'] ?? $checkout->created_at,
                            ),
                            'reference' => trim(implode(' · ', array_filter([
                                filled($checkout->batch_number)
                                    ? 'Batch '.$checkout->batch_number
                                    : null,
                                filled($checkout->notes) ? (string) $checkout->notes : null,
                            ]))),
                        ];
                    }

                    continue;
                }

                $qty = (int) ($line['dispatched_quantity'] ?? $line['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $usage[] = [
                    'kind' => 'checkout_dispatch',
                    'location' => filled($checkout->destination)
                        ? (string) $checkout->destination
                        : 'Checkout',
                    'quantity' => $qty,
                    'unit' => (string) ($line['unit'] ?? $checkout->unit ?? 'pcs'),
                    'status' => (string) ($checkout->status ?? 'dispatched'),
                    'since_at' => $this->safeReportDateTime($checkout->created_at),
                    'reference' => (string) ($line['item_name'] ?? $checkout->material_name ?? ''),
                ];
            }
        }

        $workOrderIds = WorkOrderMaterial::query()
            ->whereIn('inventory_item_id', $inventoryIds)
            ->pluck('work_order_id')
            ->unique()
            ->values()
            ->all();

        if ($workOrderIds !== []) {
            $completedBatches = ProductionBatch::query()
                ->with('workOrder')
                ->whereIn('work_order_id', $workOrderIds)
                ->where(function ($query): void {
                    $query->where('status', 'completed')
                        ->orWhereNotNull('completed_at');
                })
                ->orderByDesc('id')
                ->limit(120)
                ->get();

            foreach ($completedBatches as $batch) {
                $workOrder = $batch->workOrder;
                $usage[] = [
                    'kind' => 'production_completed',
                    'location' => trim(implode(' · ', array_filter([
                        $batch->current_stage,
                        $batch->batch_number,
                    ]))),
                    'quantity' => (int) ($batch->quantity ?? 0),
                    'unit' => (string) ($batch->unit ?? 'pcs'),
                    'status' => 'completed',
                    'since_at' => $this->safeReportDateTime(
                        $batch->completed_at ?? $batch->updated_at ?? $batch->created_at,
                    ),
                    'item_code' => filled($workOrder?->item_code) ? (string) $workOrder->item_code : null,
                    'route_card_no' => filled($workOrder?->route_card_no)
                        ? (string) $workOrder->route_card_no
                        : null,
                    'reference' => trim(implode(' · ', array_filter([
                        $workOrder?->route_card_no ? 'Route '.$workOrder->route_card_no : null,
                        $workOrder?->item_code ? 'Item '.$workOrder->item_code : null,
                        $workOrder?->client_name ? 'Client '.$workOrder->client_name : null,
                    ]))),
                ];
            }
        }

        return $this->sortRawMaterialUsageHistory($usage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $usage
     * @return array<int, array<string, mixed>>
     */
    private function sortRawMaterialUsageHistory(array $usage): array
    {
        usort($usage, function (array $left, array $right): int {
            return strcmp((string) ($right['since_at'] ?? ''), (string) ($left['since_at'] ?? ''));
        });

        return array_values($usage);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InventoryItem>  $inventoryItems
     * @return array<int, array<string, mixed>>
     */
    private function inventoryLocationsAsUsageHistory(\Illuminate\Support\Collection $inventoryItems): array
    {
        $usage = [];

        foreach ($inventoryItems as $item) {
            $qty = (int) $item->quantity;
            if ($qty <= 0) {
                continue;
            }

            $location = filled($item->location)
                ? (string) $item->location
                : 'Warehouse';

            $usage[] = [
                'kind' => 'material_at',
                'location' => $location,
                'quantity' => $qty,
                'unit' => (string) ($item->unit ?? 'pcs'),
                'status' => (string) ($item->status ?? 'in_stock'),
                'since_at' => $this->safeReportDateTime($item->updated_at ?? $item->created_at),
                'reference' => trim(implode(' · ', array_filter([
                    filled($item->batch_number) ? 'Batch '.$item->batch_number : null,
                    filled($item->sku) ? 'SKU '.$item->sku : null,
                ]))) ?: null,
            ];
        }

        return $usage;
    }

    /**
     * @param  array<int, array<string, mixed>>  $routeAllocations
     * @return array<int, array<string, mixed>>
     */
    private function routeAllocationsAsUsageHistory(array $routeAllocations): array
    {
        $usage = [];

        foreach ($routeAllocations as $row) {
            if (! is_array($row)) {
                continue;
            }

            $usage[] = [
                'kind' => 'route_allocation',
                'location' => filled($row['route_card_no'] ?? null)
                    ? 'Route '.$row['route_card_no']
                    : 'Route card',
                'quantity' => (float) ($row['allocated_quantity'] ?? 0),
                'unit' => (string) ($row['unit'] ?? 'pcs'),
                'status' => (string) ($row['work_order_status'] ?? 'assigned'),
                'since_at' => $this->safeReportDateTime($row['assigned_at'] ?? null),
                'item_code' => filled($row['item_code'] ?? null) ? (string) $row['item_code'] : null,
                'route_card_no' => filled($row['route_card_no'] ?? null)
                    ? (string) $row['route_card_no']
                    : null,
                'reference' => trim(implode(' · ', array_filter([
                    filled($row['client_name'] ?? null) ? 'Client '.$row['client_name'] : null,
                    filled($row['product'] ?? null) && ($row['product'] ?? '') !== ($row['item_code'] ?? '')
                        ? (string) $row['product']
                        : null,
                    filled($row['floor_location'] ?? null)
                        ? 'On floor: '.$row['floor_location']
                        : null,
                    filled($row['batch_number'] ?? null) ? 'Batch '.$row['batch_number'] : null,
                ]))) ?: null,
            ];
        }

        return $usage;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InventoryItem>  $inventoryItems
     * @return array<int, array<string, mixed>>
     */
    private function checkoutsAsUsageHistoryByMaterialName(
        \Illuminate\Support\Collection $inventoryItems,
        string $needle,
    ): array {
        /** @var array<int, array<string, mixed>> $usage */
        $usage = [];

        $checkouts = InventoryTransaction::query()
            ->where('type', 'checkout')
            ->orderByDesc('id')
            ->limit(600)
            ->get();

        foreach ($checkouts as $checkout) {
            $items = is_array($checkout->items) ? $checkout->items : [];
            $matchedLines = [];
            if ($items !== []) {
                foreach ($items as $line) {
                    if (is_array($line) && $this->checkinLineMatchesRawMaterial($line, $inventoryItems, $needle)) {
                        $matchedLines[] = $line;
                    }
                }
            } elseif ($this->transactionMatchesRawMaterial($checkout, $inventoryItems, $needle)) {
                $matchedLines[] = [
                    'item_name' => $checkout->material_name,
                    'quantity' => $checkout->quantity,
                    'unit' => $checkout->unit,
                ];
            }

            foreach ($matchedLines as $line) {
                $qty = (int) ($line['dispatched_quantity'] ?? $line['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $usage[] = [
                    'kind' => 'checkout_dispatch',
                    'location' => filled($checkout->destination)
                        ? (string) $checkout->destination
                        : 'Checkout',
                    'quantity' => $qty,
                    'unit' => (string) ($line['unit'] ?? $checkout->unit ?? 'pcs'),
                    'status' => (string) ($checkout->status ?? 'dispatched'),
                    'since_at' => $this->safeReportDateTime($checkout->created_at),
                    'reference' => (string) ($line['item_name'] ?? $checkout->material_name ?? ''),
                ];
            }
        }

        return $usage;
    }

    private function materialRequisitionLocationLabel(MaterialRequisition $requisition): string
    {
        if (($requisition->destination_type ?? '') === 'packing') {
            return 'Packing';
        }

        if (($requisition->destination_type ?? '') === 'route_card') {
            return 'Route card material issue';
        }

        $machine = trim(implode(' · ', array_filter([
            $requisition->machine_name,
            $requisition->machine_code,
        ])));

        return $machine !== '' ? $machine : 'Material request';
    }

    private function transactionMatchesRawMaterial(
        InventoryTransaction $transaction,
        \Illuminate\Support\Collection $inventoryItems,
        string $needle,
    ): bool {
        $materialName = (string) ($transaction->material_name ?? '');
        if ($materialName === '') {
            return false;
        }

        foreach ($inventoryItems as $inventoryItem) {
            if (strcasecmp(trim($materialName), trim((string) $inventoryItem->name)) === 0) {
                return true;
            }
            $sku = trim((string) ($inventoryItem->sku ?? ''));
            if ($sku !== '' && strcasecmp($materialName, $sku) === 0) {
                return true;
            }
        }

        $searchKey = $this->materialCompactKey($this->normalizeMaterialSearchPhrase($needle));
        $lineKey = $this->materialCompactKey($materialName);

        return $searchKey !== '' && $lineKey === $searchKey;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, string>>
     */
    private function flattenItemProductionHistoryForExport(array $payload): array
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $itemCode = (string) ($summary['item_code'] ?? '');
        $itemName = (string) ($summary['item_name'] ?? '');

        $rows = [];

        foreach ($payload['made_history'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Section' => 'Production completed',
                'Item Code' => $itemCode,
                'Item Name' => $itemName,
                'Batch' => (string) ($entry['batch_number'] ?? ''),
                'Route Card' => (string) ($entry['route_card_no'] ?? ''),
                'Quantity' => (string) ($entry['quantity'] ?? ''),
                'Unit' => (string) ($entry['unit'] ?? ''),
                'Status' => 'completed',
                'Current Stage' => '',
                'Machine' => '',
                'Machine Code' => '',
                'Stage Order' => '',
                'Total Stages' => '',
                'Event At' => (string) ($entry['made_at'] ?? ''),
                'Started At' => (string) ($entry['started_at'] ?? ''),
                'Reference' => '',
            ];
        }

        foreach ($payload['in_production'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Section' => 'Currently in production',
                'Item Code' => $itemCode,
                'Item Name' => $itemName,
                'Batch' => (string) ($entry['batch_number'] ?? ''),
                'Route Card' => (string) ($entry['route_card_no'] ?? ''),
                'Quantity' => (string) ($entry['quantity'] ?? ''),
                'Unit' => (string) ($entry['unit'] ?? ''),
                'Status' => (string) ($entry['status'] ?? ''),
                'Current Stage' => (string) ($entry['current_stage'] ?? ''),
                'Machine' => (string) ($entry['machine_name'] ?? ''),
                'Machine Code' => (string) ($entry['machine_code'] ?? ''),
                'Stage Order' => (string) ($entry['stage_order'] ?? ''),
                'Total Stages' => (string) ($entry['total_stages'] ?? ''),
                'Event At' => (string) ($entry['stage_started_at'] ?? $entry['started_at'] ?? ''),
                'Started At' => (string) ($entry['started_at'] ?? ''),
                'Reference' => '',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, string>>
     */
    private function flattenRawMaterialHistoryForExport(array $payload): array
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $materialName = (string) ($summary['material_name'] ?? $summary['material_code'] ?? '');
        $sku = (string) ($summary['material_sku'] ?? $summary['material_code'] ?? '');

        $rows = [];

        foreach ($payload['received_history'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Section' => 'Received',
                'Material' => $materialName,
                'SKU' => $sku,
                'Kind' => '',
                'Location' => (string) ($entry['item_name'] ?? ''),
                'Quantity' => (string) ($entry['quantity'] ?? ''),
                'Unit' => (string) ($entry['unit'] ?? ''),
                'Status' => 'received',
                'Event At' => (string) ($entry['received_at'] ?? ''),
                'PO Number' => (string) ($entry['po_number'] ?? ''),
                'MRN Number' => (string) ($entry['mrn_number'] ?? ''),
                'Supplier' => (string) ($entry['supplier'] ?? ''),
                'Check-in ID' => (string) ($entry['checkin_id'] ?? ''),
                'Unit Price' => isset($entry['unit_price']) && is_numeric($entry['unit_price'])
                    ? (string) $entry['unit_price']
                    : '',
                'Line Total' => isset($entry['line_total']) && is_numeric($entry['line_total'])
                    ? (string) $entry['line_total']
                    : '',
                'Reference' => '',
            ];
        }

        foreach ($payload['in_use'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Section' => 'Currently in use',
                'Material' => $materialName,
                'SKU' => $sku,
                'Kind' => (string) ($entry['kind'] ?? ''),
                'Location' => (string) ($entry['location'] ?? ''),
                'Quantity' => (string) ($entry['quantity'] ?? ''),
                'Unit' => (string) ($entry['unit'] ?? ''),
                'Status' => (string) ($entry['status'] ?? ''),
                'Event At' => (string) ($entry['since_at'] ?? ''),
                'PO Number' => '',
                'MRN Number' => '',
                'Supplier' => '',
                'Check-in ID' => '',
                'Unit Price' => '',
                'Line Total' => '',
                'Reference' => (string) ($entry['reference'] ?? ''),
            ];
        }

        foreach ($payload['usage_history'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Section' => 'Where used',
                'Material' => $materialName,
                'SKU' => $sku,
                'Kind' => (string) ($entry['kind'] ?? ''),
                'Location' => (string) ($entry['location'] ?? ''),
                'Quantity' => (string) ($entry['quantity'] ?? ''),
                'Unit' => (string) ($entry['unit'] ?? ''),
                'Status' => (string) ($entry['status'] ?? ''),
                'Event At' => (string) ($entry['since_at'] ?? ''),
                'PO Number' => '',
                'MRN Number' => '',
                'Supplier' => '',
                'Check-in ID' => '',
                'Unit Price' => '',
                'Line Total' => '',
                'Reference' => (string) ($entry['reference'] ?? ''),
            ];
        }

        foreach ($payload['route_allocations'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Section' => 'Route card assignment',
                'Material' => $materialName,
                'SKU' => $sku,
                'Kind' => 'route_card',
                'Location' => (string) ($entry['route_card_no'] ?? ''),
                'Quantity' => (string) ($entry['allocated_quantity'] ?? ''),
                'Unit' => (string) ($entry['unit'] ?? ''),
                'Status' => (string) ($entry['work_order_status'] ?? ''),
                'Event At' => (string) ($entry['assigned_at'] ?? ''),
                'PO Number' => '',
                'MRN Number' => '',
                'Supplier' => '',
                'Check-in ID' => '',
                'Unit Price' => '',
                'Line Total' => '',
                'Reference' => trim(implode(' · ', array_filter([
                    filled($entry['item_code'] ?? null) ? 'Item '.$entry['item_code'] : null,
                    filled($entry['product'] ?? null) ? (string) $entry['product'] : null,
                    filled($entry['client_name'] ?? null) ? 'Client '.$entry['client_name'] : null,
                ]))),
            ];
        }

        return $rows;
    }

    public function clientProductionHistory(Request $request): JsonResponse
    {
        try {
            $clientQuery = trim($request->string('client_query')->toString());
            if ($clientQuery === '') {
                return response()->json(['message' => 'Client name is required'], 422);
            }

            return response()->json($this->buildClientProductionHistoryPayload($clientQuery));
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/client-production-history] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load client production history',
                'summary' => [
                    'client_query' => trim($request->string('client_query')->toString()),
                    'product_count' => 0,
                    'made_count' => 0,
                    'in_production_count' => 0,
                    'generated_at' => now()->format('Y-m-d H:i'),
                ],
                'products' => [],
            ], 500);
        }
    }

    public function clientProductionHistoryExport(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $clientQuery = trim($request->string('client_query')->toString());
            if ($clientQuery === '') {
                return response()->json(['message' => 'Client name is required'], 422);
            }

            $payload = $this->buildClientProductionHistoryPayload($clientQuery);
            $rows = $this->flattenClientProductionHistoryForExport($payload);

            if ($rows === []) {
                return response()->json(['message' => 'No production records to export'], 422);
            }

            $headers = [
                'Client',
                'Product / Item',
                'Item Code',
                'Section',
                'Batch',
                'Route Card',
                'Quantity',
                'Unit',
                'Status',
                'Current Stage',
                'Machine',
                'Machine Code',
                'Stage Order',
                'Total Stages',
                'Event At',
                'Started At',
            ];

            $safeClient = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $clientQuery) ?: 'client';
            $filename = 'client_production_history_'.$safeClient.'_'.now()->format('Y-m-d_His').'.xlsx';

            return app(SpreadsheetService::class)->xlsxDownload($filename, $headers, $rows);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/client-production-history/export] failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to export client production history'], 500);
        }
    }

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     products: array<int, array<string, mixed>>,
     * }
     */
    private function buildClientProductionHistoryPayload(string $clientQuery): array
    {
        $needle = trim($clientQuery);
        $lower = strtolower($needle);

        $client = Client::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$lower])
            ->orWhere('name', 'like', '%'.$needle.'%')
            ->first();

        $workOrdersQuery = WorkOrder::query();
        $workOrdersQuery->where(function ($query) use ($client, $needle, $lower) {
            if ($client !== null) {
                $query->where('client_id', (int) $client->id)
                    ->orWhereRaw('LOWER(TRIM(client_name)) = ?', [strtolower((string) $client->name)]);
            }
            $query->orWhereRaw('LOWER(TRIM(client_name)) = ?', [$lower])
                ->orWhere('client_name', 'like', '%'.$needle.'%');
        });

        $workOrders = $workOrdersQuery->orderByDesc('id')->limit(500)->get();
        $workOrdersById = $workOrders->keyBy('id')->all();
        $workOrderIds = $workOrders->pluck('id')->all();

        $resolvedClientName = $client?->name
            ?? (string) ($workOrders->first()?->client_name ?? $needle);
        $resolvedClientId = $client?->id
            ?? ($workOrders->first()?->client_id !== null ? (int) $workOrders->first()->client_id : null);

        if ($workOrderIds === []) {
            return [
                'summary' => [
                    'client_id' => $resolvedClientId,
                    'client_name' => $resolvedClientName,
                    'client_query' => $needle,
                    'product_count' => 0,
                    'made_count' => 0,
                    'in_production_count' => 0,
                    'route_card_count' => 0,
                    'generated_at' => now()->format('Y-m-d H:i'),
                ],
                'products' => [],
            ];
        }

        $batches = ProductionBatch::query()
            ->with(['workflow', 'workOrder'])
            ->whereIn('work_order_id', $workOrderIds)
            ->orderByDesc('id')
            ->limit(600)
            ->get();

        $batchStageMachine = app(BatchStageMachineService::class);
        /** @var array<string, array<string, mixed>> $productBuckets */
        $productBuckets = [];
        $totalMade = 0;
        $totalInProduction = 0;
        $routeCards = [];

        foreach ($batches as $batch) {
            $workOrder = $workOrdersById[(int) ($batch->work_order_id ?? 0)] ?? $batch->workOrder;
            if ($workOrder === null) {
                continue;
            }

            $itemDisplay = $this->resolveItemCatalogDisplay(
                $workOrder->item_code,
                $workOrder->product,
                $batch->product_name,
            );
            $itemCode = (string) ($itemDisplay['item_code'] ?? '');
            $productName = $itemCode !== ''
                ? $itemCode
                : (string) ($itemDisplay['item_name'] ?? '');
            $bucketKey = strtolower($itemCode !== '' ? $itemCode : $productName);
            if ($bucketKey === '') {
                $bucketKey = 'batch-'.(int) $batch->id;
            }

            if (! isset($productBuckets[$bucketKey])) {
                $productBuckets[$bucketKey] = [
                    'item_code' => $itemCode !== '' ? $itemCode : null,
                    'item_name' => $productName !== '' ? $productName : null,
                    'product' => $productName !== '' ? $productName : null,
                    'grade' => $itemDisplay['grade'],
                    'diameter_mm' => $itemDisplay['diameter_mm'],
                    'made_history' => [],
                    'in_production' => [],
                    'made_count' => 0,
                    'in_production_count' => 0,
                ];
            }

            $routeCard = $this->resolveRouteCardNo($workOrder);
            if ($routeCard !== null) {
                $routeCards[$routeCard] = true;
            }
            $clientName = $this->resolveClientDisplay($batch, $workOrder);

            $status = strtolower(trim((string) ($batch->status ?? '')));
            $isCompleted = $status === 'completed' || $batch->completed_at !== null;

            if ($isCompleted) {
                $madeAt = $this->safeReportDateTime($batch->completed_at ?? $batch->updated_at);
                if ($madeAt === null) {
                    continue;
                }

                $productBuckets[$bucketKey]['made_history'][] = [
                    'batch_number' => (string) $batch->batch_number,
                    'route_card_no' => $routeCard,
                    'quantity' => (int) $batch->quantity,
                    'unit' => $batch->unit ?? 'pcs',
                    'made_at' => $madeAt,
                    'started_at' => $this->safeReportDateTime($batch->started_at ?? $batch->created_at),
                    'client_name' => $clientName ?? $resolvedClientName,
                ];
                $productBuckets[$bucketKey]['made_count']++;
                $totalMade++;

                continue;
            }

            if (in_array($status, ['cancelled', 'deleted'], true)) {
                continue;
            }

            $currentStage = $batchStageMachine->currentStageForBatch($batch, $workOrdersById);
            $machineName = is_array($currentStage) ? ($currentStage['machine_name'] ?? null) : null;
            $machineCode = is_array($currentStage) ? ($currentStage['machine_code'] ?? null) : null;

            $productBuckets[$bucketKey]['in_production'][] = [
                'batch_number' => (string) $batch->batch_number,
                'route_card_no' => $routeCard,
                'quantity' => (int) $batch->quantity,
                'unit' => $batch->unit ?? 'pcs',
                'status' => (string) ($batch->status ?? 'in_progress'),
                'current_stage' => (string) ($batch->current_stage ?? '—'),
                'machine_name' => $machineName !== null ? (string) $machineName : null,
                'machine_code' => $machineCode !== null ? (string) $machineCode : null,
                'stage_order' => max(1, (int) ($batch->stage_order ?? 1)),
                'total_stages' => max(1, (int) ($batch->total_stages ?? 1)),
                'started_at' => $this->safeReportDateTime($batch->started_at ?? $batch->created_at),
                'stage_started_at' => $this->safeReportDateTime($batch->stage_started_at),
                'client_name' => $clientName ?? $resolvedClientName,
            ];
            $productBuckets[$bucketKey]['in_production_count']++;
            $totalInProduction++;
        }

        $products = array_values($productBuckets);
        usort($products, function (array $left, array $right) {
            $floorDiff = ((int) ($right['in_production_count'] ?? 0))
                <=> ((int) ($left['in_production_count'] ?? 0));
            if ($floorDiff !== 0) {
                return $floorDiff;
            }

            return strcmp(
                strtolower((string) ($left['item_code'] ?? $left['product'] ?? '')),
                strtolower((string) ($right['item_code'] ?? $right['product'] ?? '')),
            );
        });

        foreach ($products as &$product) {
            if (! is_array($product['made_history'] ?? null)) {
                continue;
            }
            usort($product['made_history'], fn ($a, $b) => strcmp(
                (string) ($b['made_at'] ?? ''),
                (string) ($a['made_at'] ?? ''),
            ));
        }
        unset($product);

        $resolvedClientName = DisplayValue::normalize($resolvedClientName) ?? $resolvedClientName;

        return [
            'summary' => [
                'client_id' => $resolvedClientId,
                'client_name' => $resolvedClientName,
                'client_query' => $needle,
                'product_count' => count($products),
                'made_count' => $totalMade,
                'in_production_count' => $totalInProduction,
                'route_card_count' => count($routeCards),
                'generated_at' => now()->format('Y-m-d H:i'),
            ],
            'products' => $products,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, string>>
     */
    private function flattenClientProductionHistoryForExport(array $payload): array
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $clientName = (string) ($summary['client_name'] ?? '');

        $rows = [];
        foreach ($payload['products'] ?? [] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $itemCode = (string) ($product['item_code'] ?? '');
            $itemName = (string) ($product['item_name'] ?? $product['product'] ?? '');

            foreach ($product['made_history'] ?? [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $rows[] = [
                    'Client' => $clientName,
                    'Product / Item' => $itemName,
                    'Item Code' => $itemCode,
                    'Section' => 'Production completed',
                    'Batch' => (string) ($entry['batch_number'] ?? ''),
                    'Route Card' => (string) ($entry['route_card_no'] ?? ''),
                    'Quantity' => (string) ($entry['quantity'] ?? ''),
                    'Unit' => (string) ($entry['unit'] ?? ''),
                    'Status' => 'completed',
                    'Current Stage' => '',
                    'Machine' => '',
                    'Machine Code' => '',
                    'Stage Order' => '',
                    'Total Stages' => '',
                    'Event At' => (string) ($entry['made_at'] ?? ''),
                    'Started At' => (string) ($entry['started_at'] ?? ''),
                ];
            }

            foreach ($product['in_production'] ?? [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $rows[] = [
                    'Client' => $clientName,
                    'Product / Item' => $itemName,
                    'Item Code' => $itemCode,
                    'Section' => 'Currently in production',
                    'Batch' => (string) ($entry['batch_number'] ?? ''),
                    'Route Card' => (string) ($entry['route_card_no'] ?? ''),
                    'Quantity' => (string) ($entry['quantity'] ?? ''),
                    'Unit' => (string) ($entry['unit'] ?? ''),
                    'Status' => (string) ($entry['status'] ?? ''),
                    'Current Stage' => (string) ($entry['current_stage'] ?? ''),
                    'Machine' => (string) ($entry['machine_name'] ?? ''),
                    'Machine Code' => (string) ($entry['machine_code'] ?? ''),
                    'Stage Order' => (string) ($entry['stage_order'] ?? ''),
                    'Total Stages' => (string) ($entry['total_stages'] ?? ''),
                    'Event At' => (string) ($entry['stage_started_at'] ?? $entry['started_at'] ?? ''),
                    'Started At' => (string) ($entry['started_at'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     made_history: array<int, array<string, mixed>>,
     *     in_production: array<int, array<string, mixed>>,
     * }
     */
    private function buildItemProductionHistoryPayload(string $itemCode): array
    {
        $needle = trim($itemCode);
        $lower = strtolower($needle);

        $catalogItem = Item::query()
            ->whereRaw('LOWER(TRIM(code)) = ?', [$lower])
            ->orWhereRaw('LOWER(TRIM(name)) = ?', [$lower])
            ->first();

        if ($catalogItem === null) {
            $catalogItem = Item::query()
                ->where('code', 'like', '%'.$needle.'%')
                ->orWhere('name', 'like', '%'.$needle.'%')
                ->first();
        }

        $workOrders = WorkOrder::query()
            ->where(function ($query) use ($needle, $lower) {
                $query->where('item_code', 'like', '%'.$needle.'%')
                    ->orWhereRaw('LOWER(TRIM(item_code)) = ?', [$lower])
                    ->orWhere('product', 'like', '%'.$needle.'%');
            })
            ->orderByDesc('created_at')
            ->get();

        $workOrdersById = $workOrders->keyBy('id')->all();
        $workOrderIds = $workOrders->pluck('id')->all();

        $batchesQuery = ProductionBatch::query()->with(['workflow', 'workOrder']);
        $batchesQuery->where(function ($query) use ($workOrderIds, $needle) {
            if ($workOrderIds !== []) {
                $query->whereIn('work_order_id', $workOrderIds)
                    ->orWhere('product_name', 'like', '%'.$needle.'%');
            } else {
                $query->where('product_name', 'like', '%'.$needle.'%');
            }
        });
        $batches = $batchesQuery->orderByDesc('id')->limit(400)->get();

        $batchStageMachine = app(BatchStageMachineService::class);
        /** @var array<int, array<string, mixed>> $madeHistory */
        $madeHistory = [];
        /** @var array<int, array<string, mixed>> $inProduction */
        $inProduction = [];

        foreach ($batches as $batch) {
            $workOrder = $workOrdersById[(int) ($batch->work_order_id ?? 0)] ?? $batch->workOrder;
            $routeCard = $workOrder?->route_card_no;
            $status = strtolower(trim((string) ($batch->status ?? '')));
            $isCompleted = $status === 'completed' || $batch->completed_at !== null;

            if ($isCompleted) {
                $madeAt = $this->safeReportDateTime($batch->completed_at ?? $batch->updated_at);
                if ($madeAt === null) {
                    continue;
                }
                $madeHistory[] = [
                    'batch_number' => (string) $batch->batch_number,
                    'route_card_no' => $routeCard !== null ? (string) $routeCard : null,
                    'quantity' => (int) $batch->quantity,
                    'unit' => $batch->unit ?? 'pcs',
                    'made_at' => $madeAt,
                    'started_at' => $this->safeReportDateTime($batch->started_at ?? $batch->created_at),
                ];

                continue;
            }

            if (in_array($status, ['cancelled', 'deleted'], true)) {
                continue;
            }

            $currentStage = $batchStageMachine->currentStageForBatch($batch, $workOrdersById);
            $machineName = is_array($currentStage) ? ($currentStage['machine_name'] ?? null) : null;
            $machineCode = is_array($currentStage) ? ($currentStage['machine_code'] ?? null) : null;

            $inProduction[] = [
                'batch_number' => (string) $batch->batch_number,
                'route_card_no' => $routeCard !== null ? (string) $routeCard : null,
                'quantity' => (int) $batch->quantity,
                'unit' => $batch->unit ?? 'pcs',
                'status' => (string) ($batch->status ?? 'in_progress'),
                'current_stage' => (string) ($batch->current_stage ?? '—'),
                'machine_name' => $machineName !== null ? (string) $machineName : null,
                'machine_code' => $machineCode !== null ? (string) $machineCode : null,
                'stage_order' => max(1, (int) ($batch->stage_order ?? 1)),
                'total_stages' => max(1, (int) ($batch->total_stages ?? 1)),
                'started_at' => $this->safeReportDateTime($batch->started_at ?? $batch->created_at),
                'stage_started_at' => $this->safeReportDateTime($batch->stage_started_at),
            ];
        }

        usort($madeHistory, function (array $left, array $right) {
            return strcmp((string) ($right['made_at'] ?? ''), (string) ($left['made_at'] ?? ''));
        });

        usort($inProduction, function (array $left, array $right) {
            return strcmp(
                (string) ($right['stage_started_at'] ?? $right['started_at'] ?? ''),
                (string) ($left['stage_started_at'] ?? $left['started_at'] ?? ''),
            );
        });

        return [
            'summary' => [
                'item_code' => $needle,
                'item_name' => $catalogItem?->name,
                'item_description' => $catalogItem?->description,
                'grade' => $catalogItem?->grade,
                'diameter_mm' => $catalogItem?->diameter_mm !== null
                    ? round((float) $catalogItem->diameter_mm, 2)
                    : null,
                'batch_count' => $batches->count(),
                'made_count' => count($madeHistory),
                'in_production_count' => count($inProduction),
                'generated_at' => now()->format('Y-m-d H:i'),
            ],
            'made_history' => array_values($madeHistory),
            'in_production' => array_values($inProduction),
        ];
    }

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     received_history: array<int, array<string, mixed>>,
     *     in_use: array<int, array<string, mixed>>,
     * }
     */
    private function buildRawMaterialHistoryPayload(string $materialCode): array
    {
        $needle = trim($materialCode);
        $inventoryItems = $this->resolveRawMaterialInventoryItems($needle);
        $primaryItem = $inventoryItems->first();

        /** @var array<int, array<string, mixed>> $receivedHistory */
        $receivedHistory = [];
        $checkins = InventoryTransaction::query()
            ->where('type', 'checkin')
            ->orderByDesc('id')
            ->limit(600)
            ->get();

        foreach ($checkins as $checkin) {
            $items = is_array($checkin->items) ? $checkin->items : [];
            foreach ($items as $line) {
                if (! is_array($line) || ! $this->checkinLineMatchesRawMaterial($line, $inventoryItems, $needle)) {
                    continue;
                }

                $unit = (string) ($line['unit'] ?? $checkin->unit ?? 'pcs');
                $itemName = (string) ($line['item_name'] ?? $checkin->material_name ?? '—');
                $history = is_array($line['receipt_history'] ?? null) ? $line['receipt_history'] : [];

                if ($history !== []) {
                    foreach ($history as $receipt) {
                        if (! is_array($receipt)) {
                            continue;
                        }
                        $qty = (int) ($receipt['quantity'] ?? 0);
                        if ($qty <= 0) {
                            continue;
                        }
                        $receivedAt = $this->safeReportDateTime($receipt['received_at'] ?? null)
                            ?? $this->safeReportDateTime($checkin->updated_at ?? $checkin->created_at);

                        $unitPrice = $this->rawMaterialLineUnitPrice($line);
                        $receivedHistory[] = [
                            'received_at' => $receivedAt,
                            'quantity' => $qty,
                            'unit' => $unit,
                            'item_name' => $itemName,
                            'po_number' => $checkin->po_number,
                            'mrn_number' => $checkin->mrn_number,
                            'supplier' => $checkin->supplier,
                            'checkin_id' => (int) $checkin->id,
                            'unit_price' => $unitPrice,
                            'line_total' => $unitPrice !== null ? round($qty * $unitPrice, 2) : null,
                        ];
                    }

                    continue;
                }

                $receivedQty = (int) ($line['received_quantity'] ?? 0);
                if ($receivedQty <= 0) {
                    continue;
                }

                $unitPrice = $this->rawMaterialLineUnitPrice($line);
                $receivedHistory[] = [
                    'received_at' => $this->safeReportDateTime($checkin->updated_at ?? $checkin->created_at),
                    'quantity' => $receivedQty,
                    'unit' => $unit,
                    'item_name' => $itemName,
                    'po_number' => $checkin->po_number,
                    'mrn_number' => $checkin->mrn_number,
                    'supplier' => $checkin->supplier,
                    'checkin_id' => (int) $checkin->id,
                    'unit_price' => $unitPrice,
                    'line_total' => $unitPrice !== null ? round($receivedQty * $unitPrice, 2) : null,
                ];
            }
        }

        usort($receivedHistory, function (array $left, array $right) {
            return strcmp((string) ($right['received_at'] ?? ''), (string) ($left['received_at'] ?? ''));
        });

        /** @var array<int, array<string, mixed>> $inUse */
        $inUse = [];

        $stockByLocation = [];
        foreach ($inventoryItems as $inventoryRow) {
            $qty = (int) $inventoryRow->quantity;
            if ($qty <= 0) {
                continue;
            }
            $location = filled($inventoryRow->location)
                ? (string) $inventoryRow->location
                : 'Warehouse';
            $unit = (string) ($inventoryRow->unit ?? 'pcs');
            $stockByLocation[$location] ??= ['quantity' => 0, 'unit' => $unit, 'status' => 'in_stock'];
            $stockByLocation[$location]['quantity'] += $qty;
            if (($inventoryRow->status ?? '') === 'pending_qc') {
                $stockByLocation[$location]['status'] = 'pending_qc';
            }
        }

        foreach ($stockByLocation as $location => $stockRow) {
            $inUse[] = [
                'kind' => 'stock',
                'location' => $location,
                'quantity' => (int) $stockRow['quantity'],
                'unit' => (string) $stockRow['unit'],
                'status' => (string) $stockRow['status'],
                'since_at' => null,
                'reference' => null,
            ];
        }

        $inventoryIds = $inventoryItems->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        if ($inventoryIds !== []) {
            $requisitions = MaterialRequisition::query()
                ->whereIn('inventory_item_id', $inventoryIds)
                ->whereIn('status', ['pending'])
                ->orderByDesc('created_at')
                ->limit(80)
                ->get();

            foreach ($requisitions as $requisition) {
                $destination = $requisition->destination_type === 'packing'
                    ? 'Packing'
                    : trim(implode(' · ', array_filter([
                        $requisition->machine_name,
                        $requisition->machine_code,
                    ])));

                $inUse[] = [
                    'kind' => 'requisition',
                    'location' => $destination !== '' ? $destination : 'Requested',
                    'quantity' => (float) $requisition->quantity,
                    'unit' => (string) ($requisition->unit ?? 'pcs'),
                    'status' => (string) $requisition->status,
                    'since_at' => $this->safeReportDateTime($requisition->created_at),
                    'reference' => $requisition->recipient_name
                        ? 'For '.$requisition->recipient_name
                        : null,
                ];
            }

            $batchRequests = BatchMaterialRequest::query()
                ->with('productionBatch.workOrder')
                ->whereIn('inventory_item_id', $inventoryIds)
                ->whereIn('status', ['pending', 'approved'])
                ->orderByDesc('created_at')
                ->limit(80)
                ->get();

            foreach ($batchRequests as $batchRequest) {
                $batch = $batchRequest->productionBatch;
                $workOrder = $batch?->workOrder;
                $stage = (string) ($batch?->current_stage ?? '—');
                $machineBits = array_filter([$stage, $batch?->batch_number]);

                $inUse[] = [
                    'kind' => 'batch_request',
                    'location' => $machineBits !== [] ? implode(' · ', $machineBits) : 'Production',
                    'quantity' => (float) $batchRequest->quantity,
                    'unit' => (string) ($batchRequest->unit ?? 'pcs'),
                    'status' => (string) $batchRequest->status,
                    'since_at' => $this->safeReportDateTime($batchRequest->created_at),
                    'reference' => $workOrder?->route_card_no
                        ? 'Route '.$workOrder->route_card_no
                        : ($batch?->batch_number ? 'Batch '.$batch->batch_number : null),
                ];
            }

            $workOrderIds = WorkOrderMaterial::query()
                ->whereIn('inventory_item_id', $inventoryIds)
                ->pluck('work_order_id')
                ->unique()
                ->values()
                ->all();

            // Active production batches are shown via route card assignment rows
            // (avoid duplicating the same route as a separate "on production floor" card).
        }

        $totalStock = (int) $inventoryItems->sum(fn (InventoryItem $row) => (int) $row->quantity);
        $stockUnit = (string) ($primaryItem?->unit ?? 'pcs');
        $locations = $inventoryItems
            ->pluck('location')
            ->filter(fn ($value) => filled($value))
            ->unique()
            ->values()
            ->all();

        $routeAllocations = [];
        try {
            $routePayload = $this->buildRawMaterialRouteAllocationPayload($needle, '', '');
            $routeAllocations = is_array($routePayload['rows'] ?? null)
                ? $routePayload['rows']
                : [];
            $routeAllocations = $this->enrichRouteAllocationsWithActiveBatch($routeAllocations);
        } catch (\Throwable $e) {
            report($e);
        }

        $usageHistory = $this->buildRawMaterialUsageHistory(
            $inventoryItems,
            $inventoryIds,
            $needle,
        );

        $usageHistory = $this->sortRawMaterialUsageHistory(array_merge(
            $usageHistory,
            $this->inventoryLocationsAsUsageHistory($inventoryItems),
            $this->routeAllocationsAsUsageHistory($routeAllocations),
        ));

        $inventorySku = $inventoryItems
            ->pluck('sku')
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->first();

        /** @var array<int, string> $linkedItemCodes */
        $linkedItemCodes = [];
        foreach ($routeAllocations as $allocationRow) {
            if (! is_array($allocationRow)) {
                continue;
            }
            $code = trim((string) ($allocationRow['item_code'] ?? ''));
            if ($code !== '' && $code !== '—') {
                $linkedItemCodes[] = $code;
            }
        }
        foreach ($usageHistory as $usageRow) {
            if (! is_array($usageRow)) {
                continue;
            }
            $code = trim((string) ($usageRow['item_code'] ?? ''));
            if ($code !== '' && $code !== '—') {
                $linkedItemCodes[] = $code;
            }
        }
        $linkedItemCodes = array_values(array_unique($linkedItemCodes));

        $resolvedItemCode = $linkedItemCodes[0]
            ?? ($this->isInternalMaterialBatchSku($inventorySku) ? null : $inventorySku);

        return [
            'summary' => [
                'material_code' => $primaryItem?->name ?? $needle,
                'material_name' => $primaryItem?->name,
                'material_sku' => $inventorySku ?? $primaryItem?->sku,
                'item_code' => $resolvedItemCode,
                'linked_item_codes' => $linkedItemCodes,
                'inventory_item_id' => $primaryItem?->id,
                'inventory_batch_count' => $inventoryItems->count(),
                'stock_quantity' => $totalStock,
                'stock_unit' => $stockUnit,
                'stock_location' => $locations !== []
                    ? implode(', ', array_map('strval', $locations))
                    : $primaryItem?->location,
                'received_count' => count($receivedHistory),
                'in_use_count' => count($inUse),
                'usage_count' => count($usageHistory),
                'route_allocation_count' => count($routeAllocations),
                'generated_at' => now()->format('Y-m-d H:i'),
            ],
            'received_history' => array_values($receivedHistory),
            'in_use' => array_values($inUse),
            'usage_history' => $usageHistory,
            'route_allocations' => array_values($routeAllocations),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, InventoryItem>
     */
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichRouteAllocationsWithActiveBatch(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $workOrderIds = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $workOrderId = (int) ($row['work_order_id'] ?? 0);
            if ($workOrderId > 0) {
                $workOrderIds[] = $workOrderId;
            }
        }
        $workOrderIds = array_values(array_unique($workOrderIds));
        if ($workOrderIds === []) {
            return $rows;
        }

        $batches = ProductionBatch::query()
            ->with('workOrder')
            ->whereIn('work_order_id', $workOrderIds)
            ->where(function ($query): void {
                $query->whereNull('completed_at')
                    ->whereNotIn('status', ['completed', 'cancelled', 'deleted']);
            })
            ->orderByDesc('id')
            ->get()
            ->keyBy('work_order_id');

        if ($batches->isEmpty()) {
            return $rows;
        }

        $batchStageMachine = app(BatchStageMachineService::class);

        return array_map(function (array $row) use ($batches, $batchStageMachine): array {
            $workOrderId = (int) ($row['work_order_id'] ?? 0);
            $batch = $batches->get($workOrderId);
            if ($batch === null) {
                return $row;
            }

            $workOrder = $batch->workOrder;
            $workOrdersById = $workOrder !== null
                ? [(int) $workOrder->id => $workOrder]
                : [];
            $currentStage = $batchStageMachine->currentStageForBatch($batch, $workOrdersById);
            $machineName = is_array($currentStage) ? ($currentStage['machine_name'] ?? null) : null;
            $machineCode = is_array($currentStage) ? ($currentStage['machine_code'] ?? null) : null;

            $stageLabel = (string) ($batch->current_stage ?? '');
            if (trim($stageLabel) === '' || $stageLabel === '—') {
                $stageLabel = 'Stage '.max(1, (int) ($batch->stage_order ?? 1));
            }

            $floorLocation = trim(implode(' · ', array_filter([
                $stageLabel,
                $machineName,
                $machineCode,
            ])));

            $row['batch_number'] = $batch->batch_number;
            $row['batch_status'] = (string) ($batch->status ?? 'in_progress');
            $row['floor_location'] = $floorLocation !== '' ? $floorLocation : 'Production floor';
            $row['floor_since_at'] = $this->safeReportDateTime(
                $batch->stage_started_at ?? $batch->started_at,
            );

            return $row;
        }, $rows);
    }

    private function resolveRawMaterialInventoryItems(string $needle): \Illuminate\Support\Collection
    {
        $trimmed = trim($needle);
        if ($trimmed === '') {
            return collect();
        }

        $lower = strtolower($trimmed);
        $searchKey = $this->materialCompactKey($this->normalizeMaterialSearchPhrase($trimmed));
        [$grade, $diameter] = $this->parseMaterialGradeDiameter($trimmed);

        $candidates = InventoryItem::query()
            ->where('type', 'raw_material')
            ->orderByDesc('quantity')
            ->get();

        $matched = $candidates->filter(function (InventoryItem $item) use ($lower, $searchKey, $grade, $diameter) {
            $name = (string) ($item->name ?? '');
            $sku = (string) ($item->sku ?? '');

            if (strtolower(trim($sku)) === $lower || strtolower(trim($name)) === $lower) {
                return true;
            }

            if ($searchKey !== '') {
                $nameKey = $this->materialCompactKey($name);
                $skuKey = $this->materialCompactKey($sku);
                if ($nameKey === $searchKey || $skuKey === $searchKey) {
                    return true;
                }
            }

            if ($grade !== null && $diameter !== null) {
                [$itemGrade, $itemDiameter] = $this->parseMaterialGradeDiameter($name);
                if ($itemGrade !== null && $itemDiameter !== null) {
                    return strtolower($itemGrade) === strtolower($grade)
                        && abs($itemDiameter - $diameter) < 0.01;
                }

                $normalizedName = strtolower($name);
                $gradeLower = strtolower($grade);
                $diameterText = rtrim(rtrim(sprintf('%.2f', $diameter), '0'), '.');

                return str_contains($normalizedName, $gradeLower)
                    && (
                        str_contains($normalizedName, 'dia '.$diameterText)
                        || str_contains($normalizedName, 'dia'.$diameterText)
                        || preg_match('/\b'.preg_quote($diameterText, '/').'\b/', $normalizedName) === 1
                    );
            }

            return str_contains(strtolower($name), $lower)
                || str_contains(strtolower($sku), $lower);
        });

        if ($matched->isEmpty()) {
            return collect();
        }

        $primaryNameKey = $this->materialCompactKey((string) ($matched->first()->name ?? ''));

        return $candidates
            ->filter(fn (InventoryItem $item) => $this->materialCompactKey((string) ($item->name ?? '')) === $primaryNameKey)
            ->values();
    }

    private function normalizeMaterialSearchPhrase(string $needle): string
    {
        $value = trim($needle);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = preg_replace('/dia\s*(\d)/i', 'DIA $1', $value) ?? $value;

        return $value;
    }

    private function materialCompactKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        return preg_replace('/[^a-z0-9.]/', '', $normalized) ?? '';
    }

    /**
     * @return array{0: ?string, 1: ?float}
     */
    private function parseMaterialGradeDiameter(string $value): array
    {
        $phrase = $this->normalizeMaterialSearchPhrase($value);
        if (preg_match('/^([a-z0-9]+)\s*(?:dia\s*)?(\d+(?:\.\d+)?)/i', $phrase, $matches) === 1) {
            return [$matches[1], (float) $matches[2]];
        }

        if (preg_match('/^([a-z0-9]+)\s*[-\s]\s*(\d+(?:\.\d+)?)/i', $phrase, $matches) === 1) {
            return [$matches[1], (float) $matches[2]];
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isInternalMaterialBatchSku(?string $sku): bool
    {
        if (! filled($sku)) {
            return false;
        }

        $upper = strtoupper(trim($sku));

        return str_contains($upper, '-PO')
            || str_contains($upper, '-MRN')
            || str_ends_with($upper, '-QC');
    }

    private function rawMaterialLineUnitPrice(array $line): ?float
    {
        foreach (['unit_price', 'purchase_price', 'rate', 'price'] as $key) {
            if (! isset($line[$key]) || ! is_numeric($line[$key])) {
                continue;
            }
            $value = (float) $line[$key];
            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    private function checkinLineMatchesRawMaterial(
        array $line,
        \Illuminate\Support\Collection $inventoryItems,
        string $needle,
    ): bool {
        foreach ($inventoryItems as $inventoryItem) {
            if ($this->checkinLineMatchesInventoryItem($line, $inventoryItem)) {
                return true;
            }
        }

        $lineName = (string) ($line['item_name'] ?? '');
        $searchKey = $this->materialCompactKey($this->normalizeMaterialSearchPhrase($needle));
        $lineKey = $this->materialCompactKey($lineName);

        if ($searchKey !== '' && $lineKey === $searchKey) {
            return true;
        }

        [$grade, $diameter] = $this->parseMaterialGradeDiameter($needle);
        if ($grade !== null && $diameter !== null) {
            [$lineGrade, $lineDiameter] = $this->parseMaterialGradeDiameter($lineName);
            if ($lineGrade !== null && $lineDiameter !== null) {
                return strtolower($lineGrade) === strtolower($grade)
                    && abs($lineDiameter - $diameter) < 0.01;
            }
        }

        $lowerNeedle = strtolower(trim($needle));
        $lowerLine = strtolower(trim($lineName));

        if ($lowerNeedle === '') {
            return false;
        }

        return $lowerLine === $lowerNeedle
            || str_contains($lowerLine, $lowerNeedle)
            || str_contains($lowerNeedle, $lowerLine);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function checkinLineMatchesInventoryItem(array $line, InventoryItem $inventoryItem): bool
    {
        $lineInventoryId = (int) ($line['inventory_id'] ?? $line['inventory_item_id'] ?? 0);
        if ($lineInventoryId > 0 && $lineInventoryId === (int) $inventoryItem->id) {
            return true;
        }

        $skuLower = strtolower(trim((string) ($inventoryItem->sku ?? '')));
        $nameLower = strtolower(trim((string) ($inventoryItem->name ?? '')));
        $lineName = strtolower(trim((string) ($line['item_name'] ?? '')));

        if ($lineName !== '' && ($lineName === $skuLower || $lineName === $nameLower)) {
            return true;
        }

        $searchKey = $this->materialCompactKey($nameLower);
        $lineKey = $this->materialCompactKey($lineName);

        return $searchKey !== '' && $lineKey === $searchKey;
    }

    /**
     * @return array{summary: array<string, mixed>, machines: array<int, array<string, mixed>>}
     */
    private function buildMachineProcessingHistoryPayload(Request $request): array
    {
        $batchStageMachine = app(BatchStageMachineService::class);
        $machineFilter = $request->filled('machine_id')
            ? (int) $request->query('machine_id')
            : null;
        $includeInProgress = filter_var(
            $request->query('include_in_progress', true),
            FILTER_VALIDATE_BOOL,
        );
        $activeBatchesOnly = $request->boolean('active_only');
        $searchNeedle = strtolower(trim((string) $request->query('search', '')));

        $from = $this->parseReportDateFilter($request->query('from'));
        $to = $this->parseReportDateFilter($request->query('to'), endOfDay: true);

        $batches = ProductionBatch::query()
            ->with(['workflow'])
            ->whereNotNull('stage_history')
            ->latest('updated_at')
            ->get();

        $workOrdersById = $this->workOrdersForBatches($batches);

        /** @var array<int, array<string, mixed>> $machineRows */
        $machineRows = [];
        foreach (Machinery::query()->orderBy('id')->get() as $machine) {
            $machineRows[(int) $machine->id] = [
                'machine_id' => (int) $machine->id,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->name,
                'type' => $machine->type,
                'location' => $machine->location,
                'event_count' => 0,
                'events' => [],
            ];
        }

        $unassigned = [
            'machine_id' => null,
            'machine_code' => null,
            'machine_name' => 'Unassigned / Unknown machine',
            'type' => null,
            'location' => null,
            'event_count' => 0,
            'events' => [],
        ];

        $totalEvents = 0;

        foreach ($batches as $batch) {
            $batchStatus = strtolower(trim((string) ($batch->status ?? '')));
            $batchIsFinished = $batchStatus === 'completed' || $batch->completed_at !== null;

            if ($activeBatchesOnly && $batchIsFinished) {
                continue;
            }

            $history = array_values($batch->stage_history ?? []);
            if ($history === []) {
                continue;
            }

            $stages = $batchStageMachine->resolveStagesForBatch($batch, $workOrdersById);
            $workOrder = $workOrdersById[(int) ($batch->work_order_id ?? 0)] ?? null;
            $materialMeta = $this->batchMaterialMeta($batch, $workOrder);

            foreach ($history as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $stageName = trim((string) ($entry['stage'] ?? ''));
                if ($stageName === '') {
                    continue;
                }

                $completedRaw = $entry['completed'] ?? $entry['completed_at'] ?? null;
                $isOpen = $completedRaw === null
                    || (is_string($completedRaw) && trim($completedRaw) === '');

                if ($isOpen && $batchIsFinished) {
                    $isOpen = false;
                }

                if (! $includeInProgress && $isOpen) {
                    continue;
                }

                $startedAt = $this->safeReportDateTime($entry['started'] ?? $entry['started_at'] ?? null);
                $completedAt = $isOpen ? null : $this->safeReportDateTime($completedRaw);
                if (! $isOpen && $completedAt === null && $batchIsFinished) {
                    $completedAt = $this->safeReportDateTime($batch->completed_at);
                }
                $sortAt = $completedAt ?? $startedAt;
                if ($sortAt === null) {
                    continue;
                }

                if ($from !== null || $to !== null) {
                    try {
                        $sortCarbon = Carbon::parse($sortAt);
                        if ($from !== null && $sortCarbon->lt($from)) {
                            continue;
                        }
                        if ($to !== null && $sortCarbon->gt($to)) {
                            continue;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }

                $stageDef = $this->stageDefinitionForHistoryEntry($stages, $history, $index);
                $machineId = $stageDef !== null
                    ? $batchStageMachine->machineIdForStage($stageDef)
                    : null;

                if ($machineFilter !== null && (int) $machineFilter !== (int) ($machineId ?? 0)) {
                    continue;
                }

                $event = [
                    'batch_id' => (int) $batch->id,
                    'batch_number' => (string) $batch->batch_number,
                    'work_order_id' => $batch->work_order_id ? (int) $batch->work_order_id : null,
                    'route_card' => $materialMeta['route_card'],
                    'product_name' => $materialMeta['product_name'],
                    'item_name' => $materialMeta['item_name'],
                    'item_code' => $materialMeta['item_code'],
                    'grade' => $materialMeta['grade'],
                    'diameter_mm' => $materialMeta['diameter_mm'],
                    'quantity' => (int) $batch->quantity,
                    'unit' => $batch->unit,
                    'stage' => $stageName,
                    'operator' => trim((string) ($entry['user'] ?? '')) ?: ($batch->assigned_to ?? $batch->user_name ?? '—'),
                    'started_at' => $startedAt ?? '—',
                    'processed_at' => $completedAt ?? ($isOpen ? '—' : $startedAt ?? '—'),
                    'completed_at' => $completedAt,
                    'duration' => trim((string) ($entry['duration'] ?? '')) ?: '—',
                    'in_progress' => $isOpen,
                    'batch_status' => (string) ($batch->status ?? ''),
                    'batch_completed' => $batchIsFinished,
                    'sort_at' => $sortAt,
                ];

                if ($searchNeedle !== '') {
                    $machineMeta = $machineId !== null && isset($machineRows[$machineId])
                        ? $machineRows[$machineId]
                        : $unassigned;
                    if (! $this->machineProcessingEventMatchesSearch($machineMeta, $event, $searchNeedle)) {
                        continue;
                    }
                }

                $totalEvents++;

                if ($machineId !== null && isset($machineRows[$machineId])) {
                    $machineRows[$machineId]['events'][] = $event;
                    $machineRows[$machineId]['event_count']++;
                } else {
                    $unassigned['events'][] = $event;
                    $unassigned['event_count']++;
                }
            }
        }

        $sortEvents = function (array &$events): void {
            usort($events, function (array $left, array $right) {
                return strcmp((string) ($right['sort_at'] ?? ''), (string) ($left['sort_at'] ?? ''));
            });
            foreach ($events as &$event) {
                unset($event['sort_at']);
            }
            unset($event);
        };

        foreach ($machineRows as &$row) {
            $sortEvents($row['events']);
        }
        unset($row);
        $sortEvents($unassigned['events']);

        $rows = array_values($machineRows);
        if ($unassigned['event_count'] > 0) {
            $rows[] = $unassigned;
        }

        if ($machineFilter !== null) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => (int) ($row['machine_id'] ?? 0) === $machineFilter
                    || ($row['machine_id'] === null && $machineFilter === 0),
            ));
        } else {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => (int) ($row['event_count'] ?? 0) > 0,
            ));
        }

        return [
            'summary' => [
                'total_machines' => count(array_filter($rows, fn (array $r) => $r['machine_id'] !== null)),
                'total_events' => $totalEvents,
                'generated_at' => now()->format('Y-m-d H:i'),
            ],
            'machines' => $rows,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @param  array<int, array<string, mixed>>  $history
     * @return array<string, mixed>|null
     */
    private function stageDefinitionForHistoryEntry(
        array $stages,
        array $history,
        int $entryIndex,
    ): ?array {
        $entry = $history[$entryIndex] ?? null;
        if (! is_array($entry)) {
            return null;
        }

        $stageName = trim((string) ($entry['stage'] ?? ''));
        if ($stageName === '') {
            return null;
        }

        $targetOccurrence = 0;
        for ($i = 0; $i <= $entryIndex; $i++) {
            $prior = $history[$i] ?? null;
            if (! is_array($prior)) {
                continue;
            }
            if (strcasecmp(trim((string) ($prior['stage'] ?? '')), $stageName) === 0) {
                $targetOccurrence++;
            }
        }

        if ($targetOccurrence < 1) {
            return ['name' => $stageName];
        }

        $seen = 0;
        foreach ($stages as $stage) {
            if (strcasecmp(trim((string) ($stage['name'] ?? '')), $stageName) !== 0) {
                continue;
            }
            $seen++;
            if ($seen === $targetOccurrence) {
                return $stage;
            }
        }

        return ['name' => $stageName];
    }

    /**
     * @return array{
     *     product_name: string,
     *     item_name: string,
     *     item_code: ?string,
     *     grade: ?string,
     *     diameter_mm: ?float,
     *     route_card: ?string,
     * }
     */
    private function batchMaterialMeta(ProductionBatch $batch, ?WorkOrder $workOrder = null): array
    {
        if ($workOrder === null && ! empty($batch->work_order_id)) {
            $workOrder = WorkOrder::query()->find($batch->work_order_id);
        }

        $itemDisplay = $this->resolveItemCatalogDisplay(
            $workOrder?->item_code,
            $workOrder?->product,
            $batch->product_name,
        );
        $productLabel = $itemDisplay['item_code']
            ?? $itemDisplay['item_name']
            ?? DisplayValue::normalize($batch->product_name)
            ?? 'Product';

        $routeCard = $this->resolveRouteCardNo($workOrder);
        if ($routeCard === null) {
            $routeCard = DisplayValue::normalize($batch->workflow?->name);
        }

        return [
            'product_name' => (string) $productLabel,
            'item_name' => (string) $productLabel,
            'item_code' => $itemDisplay['item_code'],
            'item_description' => $itemDisplay['item_description'] ?? null,
            'grade' => $itemDisplay['grade'],
            'diameter_mm' => $itemDisplay['diameter_mm'],
            'route_card' => $routeCard !== null ? (string) $routeCard : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $machineRow
     * @param  array<string, mixed>  $event
     */
    private function machineProcessingEventMatchesSearch(
        array $machineRow,
        array $event,
        string $needle,
    ): bool {
        if ($needle === '') {
            return true;
        }

        $haystack = strtolower(implode(' ', array_filter([
            (string) ($machineRow['machine_name'] ?? ''),
            (string) ($machineRow['machine_code'] ?? ''),
            (string) ($machineRow['location'] ?? ''),
            (string) ($event['batch_number'] ?? ''),
            (string) ($event['route_card'] ?? ''),
            (string) ($event['item_code'] ?? ''),
            (string) ($event['item_name'] ?? ''),
            (string) ($event['product_name'] ?? ''),
            (string) ($event['grade'] ?? ''),
            (string) ($event['stage'] ?? ''),
            (string) ($event['operator'] ?? ''),
        ], fn (string $part) => $part !== '')));

        return $haystack !== '' && str_contains($haystack, $needle);
    }

    private function parseReportDateFilter(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse((string) $value);

            return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{summary: array<string, mixed>, machines: array<int, array<string, mixed>>}
     */
    private function buildMachineActiveWorkPayload(Request $request): array
    {
        $batchStageMachine = app(BatchStageMachineService::class);
        // On-hold route cards stay visible on the machine floor at the stage where hold was applied.
        $includeOnHold = filter_var($request->query('include_on_hold', true), FILTER_VALIDATE_BOOL);
        $machineFilter = $request->filled('machine_id')
            ? (int) $request->query('machine_id')
            : null;

        $machinesQuery = Machinery::query();
        if (Schema::hasColumn('machinery', 'sequence')) {
            $machinesQuery->orderBy('sequence');
        }
        $machines = $machinesQuery->orderBy('id')->get();

        $batchQuery = ProductionBatch::query()
            ->with(['workflow'])
            ->where(function ($query) {
                $query->whereNull('completed_at')
                    ->whereNotIn('status', ['completed', 'cancelled', 'deleted']);
            });

        if (! $includeOnHold) {
            $batchQuery->where('status', '!=', 'on_hold');
        }

        $batches = $batchQuery->get();
        $batchesById = $batches->keyBy('id')->all();

        $workOrdersById = $this->workOrdersForBatches($batches);

        $machineRows = [];
        foreach ($machines as $machine) {
            $machineRows[(int) $machine->id] = [
                'machine_id' => (int) $machine->id,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->name,
                'type' => $machine->type,
                'location' => $machine->location,
                'capacity' => $machine->capacity,
                'status' => $machine->status,
                'default_stage' => $machine->default_stage,
                'active_job_count' => 0,
                'active_quantity' => 0,
                'is_busy' => false,
                'jobs' => [],
            ];
        }

        $unassigned = [
            'machine_id' => null,
            'machine_code' => null,
            'machine_name' => 'Unassigned / No machine',
            'type' => null,
            'location' => null,
            'capacity' => null,
            'status' => 'unknown',
            'default_stage' => null,
            'active_job_count' => 0,
            'active_quantity' => 0,
            'is_busy' => false,
            'jobs' => [],
        ];

        foreach ($batches as $batch) {
            try {
                $currentStage = $this->resolveMachineFloorStage(
                    $batch,
                    $batchStageMachine,
                    $workOrdersById,
                );
            } catch (\Throwable $e) {
                Log::warning('[API][reports/machine-active-work] stage resolve failed', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($currentStage === null) {
                continue;
            }

            $machineId = $batchStageMachine->machineIdForStage($currentStage);
            $job = $this->formatMachineActiveJob(
                $batch,
                $currentStage,
                $workOrdersById[(int) $batch->work_order_id] ?? null,
            );

            if ($machineId !== null && isset($machineRows[$machineId])) {
                $machineRows[$machineId]['jobs'][] = $job;
                $machineRows[$machineId]['active_job_count']++;
                $machineRows[$machineId]['active_quantity'] += (int) $batch->quantity;
                $machineRows[$machineId]['is_busy'] = true;
            } else {
                $unassigned['jobs'][] = $job;
                $unassigned['active_job_count']++;
                $unassigned['active_quantity'] += (int) $batch->quantity;
                $unassigned['is_busy'] = true;
            }
        }

        $rows = array_values($machineRows);
        if ($unassigned['active_job_count'] > 0) {
            $rows[] = $unassigned;
        }

        $this->markCurrentlyWorkingJobsOnMachines($rows, $batchesById);

        if ($machineFilter !== null) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => (int) ($row['machine_id'] ?? 0) === $machineFilter,
            ));
        }

        $busyCount = collect($machineRows)->where('is_busy', true)->count();
        $totalJobs = (int) collect($rows)->sum('active_job_count');
        $totalQty = (int) collect($rows)->sum('active_quantity');
        $onHoldJobs = 0;
        foreach ($rows as $row) {
            foreach ($row['jobs'] ?? [] as $job) {
                if (! is_array($job)) {
                    continue;
                }
                if (strtolower((string) ($job['status'] ?? '')) === 'on_hold') {
                    $onHoldJobs++;
                }
            }
        }

        return [
            'summary' => [
                'total_machines' => $machines->count(),
                'busy_machines' => $busyCount,
                'idle_machines' => max(0, $machines->count() - $busyCount),
                'total_active_jobs' => $totalJobs,
                'total_active_quantity' => $totalQty,
                'on_hold_jobs' => $onHoldJobs,
                'generated_at' => now()->format('Y-m-d H:i'),
            ],
            'machines' => $rows,
        ];
    }

    /**
     * @param  array<int, WorkOrder>  $workOrdersById
     * @return array<string, mixed>|null
     */
    private function resolveMachineFloorStage(
        ProductionBatch $batch,
        BatchStageMachineService $batchStageMachine,
        array $workOrdersById,
    ): ?array {
        if (strtolower(trim((string) ($batch->status ?? ''))) !== 'on_hold') {
            return $batchStageMachine->currentStageForBatch($batch, $workOrdersById);
        }

        $stages = $batchStageMachine->resolveStagesForBatch($batch, $workOrdersById);
        if ($stages === []) {
            return $batchStageMachine->currentStageForBatch($batch, $workOrdersById);
        }

        $stageName = trim((string) ($batch->current_stage ?? ''));
        $history = array_values($batch->stage_history ?? []);
        for ($index = count($history) - 1; $index >= 0; $index--) {
            $entry = $history[$index];
            if (! is_array($entry)) {
                continue;
            }
            $completedRaw = $entry['completed'] ?? $entry['completed_at'] ?? null;
            $isOpen = $completedRaw === null
                || (is_string($completedRaw) && trim($completedRaw) === '');
            if (! $isOpen) {
                continue;
            }
            $fromHistory = trim((string) ($entry['stage'] ?? ''));
            if ($fromHistory !== '') {
                $stageName = $fromHistory;
            }
            break;
        }

        if ($stageName !== '') {
            foreach ($stages as $stage) {
                $name = trim((string) ($stage['name'] ?? ''));
                if ($name !== '' && strcasecmp($name, $stageName) === 0) {
                    return $stage;
                }
            }
        }

        return $batchStageMachine->currentStageForBatch($batch, $workOrdersById);
    }

    /**
     * @param  iterable<int, ProductionBatch>  $batches
     * @return array<int, WorkOrder>
     */
    private function resolveRouteCardNo(?WorkOrder $workOrder): ?string
    {
        return DisplayValue::normalize($workOrder?->route_card_no);
    }

    private function resolveClientDisplay(ProductionBatch $batch, ?WorkOrder $workOrder): ?string
    {
        foreach ([$batch->client_name, $workOrder?->client_name] as $candidate) {
            $normalized = DisplayValue::normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $clientId = (int) ($workOrder?->client_id ?? 0);
        if ($clientId > 0) {
            return Client::query()->find($clientId)?->name;
        }

        return null;
    }

    /**
     * @return array{
     *     item_code: ?string,
     *     item_name: ?string,
     *     item_description: ?string,
     *     grade: ?string,
     *     diameter_mm: ?float,
     * }
     */
    private function resolveItemCatalogDisplay(
        mixed $itemCodeRaw,
        mixed $productRaw,
        mixed $batchProductRaw,
    ): array {
        $itemCode = DisplayValue::normalize(is_string($itemCodeRaw) ? $itemCodeRaw : null)
            ?? DisplayValue::normalize(is_string($productRaw) ? $productRaw : null);

        if ($itemCode === null) {
            $fallback = DisplayValue::normalize(is_string($batchProductRaw) ? $batchProductRaw : null);

            return [
                'item_code' => null,
                'item_name' => $fallback,
                'grade' => null,
                'diameter_mm' => null,
            ];
        }

        $catalogItem = Item::query()
            ->whereRaw('LOWER(TRIM(code)) = ?', [strtolower($itemCode)])
            ->first();

        if ($catalogItem === null) {
            $catalogItem = Item::query()
                ->where('code', 'like', '%'.$itemCode.'%')
                ->orWhere('name', 'like', '%'.$itemCode.'%')
                ->first();
        }

        $description = DisplayValue::normalize($catalogItem?->description);
        $catalogName = DisplayValue::normalize($catalogItem?->name);
        $textFallback = DisplayValue::normalize(is_string($productRaw) ? $productRaw : null)
            ?? DisplayValue::normalize(is_string($batchProductRaw) ? $batchProductRaw : null);
        $itemDescription = $description
            ?? ($catalogName !== null && strcasecmp($catalogName, $itemCode) !== 0 ? $catalogName : null)
            ?? ($textFallback !== null && strcasecmp($textFallback, $itemCode) !== 0 ? $textFallback : null);

        return [
            'item_code' => $itemCode,
            'item_name' => $itemCode,
            'item_description' => $itemDescription,
            'grade' => DisplayValue::normalize($catalogItem?->grade),
            'diameter_mm' => $catalogItem?->diameter_mm !== null
                ? round((float) $catalogItem->diameter_mm, 2)
                : null,
        ];
    }

    private function workOrdersForBatches(iterable $batches): array
    {
        if (! Schema::hasColumn('production_batches', 'work_order_id')) {
            return [];
        }

        $ids = [];
        foreach ($batches as $batch) {
            if (! empty($batch->work_order_id)) {
                $ids[] = (int) $batch->work_order_id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        return WorkOrder::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $currentStage
     * @return array<string, mixed>
     */
    private function formatMachineActiveJob(
        ProductionBatch $batch,
        array $currentStage,
        ?WorkOrder $workOrder = null,
    ): array {
        if ($workOrder === null && ! empty($batch->work_order_id)) {
            $workOrder = WorkOrder::query()->find($batch->work_order_id);
        }

        $routeCard = $this->resolveRouteCardNo($workOrder);
        $itemDisplay = $this->resolveItemCatalogDisplay(
            $workOrder?->item_code,
            $workOrder?->product,
            $batch->product_name,
        );
        $clientName = $this->resolveClientDisplay($batch, $workOrder);

        $stageLabel = DisplayValue::normalize($batch->current_stage);
        if ($stageLabel === null) {
            $stageLabel = DisplayValue::normalize($currentStage['name'] ?? null) ?? '—';
        }
        $isOnHold = strtolower(trim((string) ($batch->status ?? ''))) === 'on_hold';
        $productLabel = $itemDisplay['item_code']
            ?? $itemDisplay['item_name']
            ?? DisplayValue::normalize($batch->product_name)
            ?? 'Product';

        return [
            'batch_id' => (int) $batch->id,
            'batch_number' => (string) $batch->batch_number,
            'product_name' => $productLabel,
            'item_name' => $productLabel,
            'item_code' => $itemDisplay['item_code'],
            'grade' => $itemDisplay['grade'],
            'diameter_mm' => $itemDisplay['diameter_mm'],
            'quantity' => (int) $batch->quantity,
            'unit' => $batch->unit,
            'current_stage' => $stageLabel !== '' ? $stageLabel : '—',
            'hold_stage' => $isOnHold ? $stageLabel : null,
            'stage_order' => (int) ($batch->stage_order ?? 1),
            'total_stages' => (int) ($batch->total_stages ?? 1),
            'status' => (string) ($batch->status ?? 'in_progress'),
            'priority' => Schema::hasColumn('production_batches', 'priority')
                ? (string) ($batch->priority ?? 'normal')
                : 'normal',
            'work_order_id' => $batch->work_order_id,
            'route_card_no' => $routeCard,
            'client_name' => $clientName,
            'stage_started_at' => $this->safeReportDateTime($batch->stage_started_at ?? $batch->started_at),
            'started_at' => $this->safeReportDateTime($batch->started_at ?? $batch->created_at),
            'operator' => $batch->assigned_to ?? $batch->user_name ?? $batch->performed_by,
            'hold_reason' => $batch->hold_reason,
            'machine_name' => $currentStage['machine_name'] ?? null,
            'machine_code' => $currentStage['machine_code'] ?? null,
            'is_job_work_stage' => (bool) ($currentStage['is_job_work'] ?? false),
            'is_currently_working' => false,
            'is_on_hold' => $isOnHold,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $machineRows
     * @param  array<int, ProductionBatch>  $batchesById
     */
    private function markCurrentlyWorkingJobsOnMachines(array &$machineRows, array $batchesById): void
    {
        foreach ($machineRows as &$row) {
            $jobs = $row['jobs'] ?? [];
            if (! is_array($jobs) || $jobs === []) {
                continue;
            }

            $currentBatchId = null;
            $currentStartedAt = null;

            foreach ($jobs as $index => $job) {
                if (! is_array($job)) {
                    continue;
                }

                $batchId = (int) ($job['batch_id'] ?? 0);
                $batch = $batchesById[$batchId] ?? null;
                $stageName = (string) ($job['current_stage'] ?? '');
                $activelyRunning = $batch !== null
                    && $this->isBatchActivelyRunningOnStage($batch, $stageName);

                $jobs[$index]['is_actively_running'] = $activelyRunning;

                if (! $activelyRunning) {
                    continue;
                }

                $startedAt = $this->batchActiveStageStartedAt($batch);
                if ($currentStartedAt === null || ($startedAt !== null && $startedAt->gt($currentStartedAt))) {
                    $currentStartedAt = $startedAt;
                    $currentBatchId = $batchId;
                }
            }

            if ($currentBatchId === null) {
                foreach ($jobs as $index => $job) {
                    if (! is_array($job)) {
                        continue;
                    }
                    if (strtolower((string) ($job['status'] ?? '')) === 'on_hold') {
                        continue;
                    }
                    $batchId = (int) ($job['batch_id'] ?? 0);
                    $batch = $batchesById[$batchId] ?? null;
                    if ($batch === null) {
                        continue;
                    }
                    $startedAt = $batch->stage_started_at ?? $batch->started_at;
                    if ($startedAt === null) {
                        continue;
                    }
                    if ($currentStartedAt === null || $startedAt->gt($currentStartedAt)) {
                        $currentStartedAt = $startedAt;
                        $currentBatchId = $batchId;
                    }
                }
            }

            foreach ($jobs as $index => $job) {
                if (! is_array($job)) {
                    continue;
                }
                $batchId = (int) ($job['batch_id'] ?? 0);
                $jobs[$index]['is_currently_working'] = $currentBatchId !== null
                    && $batchId === $currentBatchId;
            }

            $row['jobs'] = $jobs;
            $row['current_batch_id'] = $currentBatchId;
        }
        unset($row);
    }

    private function isBatchActivelyRunningOnStage(ProductionBatch $batch, string $stageName): bool
    {
        if (strtolower(trim((string) ($batch->status ?? ''))) === 'on_hold') {
            return false;
        }

        $history = array_values($batch->stage_history ?? []);
        if ($history === []) {
            return strtolower(trim((string) ($batch->status ?? ''))) === 'in_progress';
        }

        for ($index = count($history) - 1; $index >= 0; $index--) {
            $entry = $history[$index];
            if (! is_array($entry)) {
                continue;
            }

            $completedRaw = $entry['completed'] ?? $entry['completed_at'] ?? null;
            $isOpen = $completedRaw === null
                || (is_string($completedRaw) && trim($completedRaw) === '');

            if (! $isOpen) {
                continue;
            }

            $entryStage = trim((string) ($entry['stage'] ?? ''));
            $currentStage = trim($stageName !== '' ? $stageName : (string) ($batch->current_stage ?? ''));

            if ($entryStage === '' || $currentStage === ''
                || strcasecmp($entryStage, $currentStage) === 0) {
                return true;
            }

            return false;
        }

        return false;
    }

    private function batchActiveStageStartedAt(ProductionBatch $batch): ?Carbon
    {
        $history = array_values($batch->stage_history ?? []);
        for ($index = count($history) - 1; $index >= 0; $index--) {
            $entry = $history[$index];
            if (! is_array($entry)) {
                continue;
            }

            $completedRaw = $entry['completed'] ?? $entry['completed_at'] ?? null;
            $isOpen = $completedRaw === null
                || (is_string($completedRaw) && trim($completedRaw) === '');

            if (! $isOpen) {
                continue;
            }

            $started = $this->safeReportDateTime($entry['started'] ?? $entry['started_at'] ?? null);

            return $started !== null ? Carbon::parse($started) : null;
        }

        $fallback = $batch->stage_started_at ?? $batch->started_at;

        return $fallback instanceof Carbon ? $fallback : ($fallback ? Carbon::parse($fallback) : null);
    }

    private function safeReportDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    public function inventory(): JsonResponse
    {
        return response()->json(
            InventoryItem::query()
                ->latest()
                ->get()
                ->map(fn (InventoryItem $item) => [
                    'material' => $item->name,
                    'category' => $this->inventoryCategoryLabel($item->type),
                    'current_stock' => (int) $item->quantity,
                    'consumed' => max(0, (int) $item->min_stock - (int) $item->quantity),
                    'movement' => $item->status ?? 'stable',
                    'warehouse' => $item->location ?? 'Main Warehouse',
                    'status' => $this->stockStatus($item),
                ]),
        );
    }

    public function production(): JsonResponse
    {
        return response()->json(
            ProductionBatch::query()
                ->latest()
                ->get()
                ->map(fn (ProductionBatch $batch) => [
                    'product' => $batch->product_name,
                    'batch' => $batch->batch_number,
                    'stage' => $batch->current_stage ?? '—',
                    'quantity' => (int) $batch->quantity,
                    'completion_time' => ($batch->completed_at ?? $batch->updated_at)?->format('Y-m-d H:i') ?? '—',
                    'status' => $batch->status,
                    'operator' => $batch->assigned_to ?? $batch->user_name ?? '—',
                ]),
        );
    }

    public function vendor(): JsonResponse
    {
        $orders = PurchaseOrder::query()->get();

        return response()->json(
            Vendor::query()
                ->latest()
                ->get()
                ->map(function (Vendor $vendor) use ($orders) {
                    $vendorOrders = $orders->filter(
                        fn (PurchaseOrder $order) => strcasecmp($order->vendor_name ?? '', $vendor->name) === 0,
                    );

                    $totalAmount = $vendorOrders->sum('total_amount');
                    $lastOrder = $vendorOrders
                        ->sortByDesc('created_at')
                        ->first();

                    return [
                        'vendor' => $vendor->name,
                        'contact' => $vendor->contact_person ?? $vendor->phone ?? '—',
                        'total_purchases' => $vendorOrders->count() ?: (int) $vendor->total_orders,
                        'last_order' => ($lastOrder?->created_at ?? $vendor->last_order_date)?->format('Y-m-d') ?? '—',
                        'amount' => '₹'.number_format($totalAmount, 0),
                        'trend' => $vendorOrders->count() >= 3 ? 'Growing' : 'Stable',
                    ];
                }),
        );
    }

    public function customer(): JsonResponse
    {
        $finishedGoods = FinishedGood::query()->with('client')->get();

        return response()->json(
            Client::query()
                ->latest()
                ->get()
                ->map(function (Client $client) use ($finishedGoods) {
                    $clientProducts = $finishedGoods->where('client_id', $client->id);
                    $dispatched = (int) $clientProducts->sum('dispatched');
                    $revenue = $dispatched * 1000;

                    $productNames = $clientProducts
                        ->pluck('product_name')
                        ->unique()
                        ->take(3)
                        ->implode(', ');

                    $lastDispatch = $clientProducts
                        ->sortByDesc('updated_at')
                        ->first();

                    return [
                        'customer' => $client->name,
                        'contact' => $client->contact_person ?? $client->phone ?? '—',
                        'total_sales' => $clientProducts->count() ?: (int) $client->total_orders,
                        'revenue' => '₹'.number_format($revenue, 0),
                        'last_dispatch' => ($lastDispatch?->updated_at ?? $client->last_order_date)?->format('Y-m-d') ?? '—',
                        'products' => $productNames ?: '—',
                    ];
                }),
        );
    }

    public function finishedGoods(): JsonResponse
    {
        return response()->json(
            FinishedGood::query()
                ->latest()
                ->get()
                ->map(fn (FinishedGood $product) => [
                    'product' => $product->product_name,
                    'product_code' => $product->sku,
                    'batch' => $product->batch_number,
                    'produced_date' => $product->production_date?->format('Y-m-d') ?? '—',
                    'quantity' => (int) $product->quantity,
                    'stock' => (int) $product->stock,
                    'dispatched' => (int) $product->dispatched,
                    'warehouse' => $product->warehouse ?? $product->location ?? '—',
                    'status' => $product->status ?? 'In Stock',
                ]),
        );
    }

    public function rejections(): JsonResponse
    {
        return response()->json(
            Rejection::query()
                ->latest()
                ->get()
                ->map(fn (Rejection $rejection) => [
                    'product' => $rejection->product_name,
                    'batch' => $rejection->batch_number,
                    'rejected_quantity' => (int) $rejection->quantity,
                    'total_produced' => (int) ($rejection->total_produced ?: 0),
                    'reason' => $rejection->reason ?? '—',
                    'stage' => $rejection->stage ?? '—',
                    'date' => ($rejection->rejection_date ?? $rejection->created_at)?->format('Y-m-d') ?? '—',
                    'user' => $rejection->reported_by ?? '—',
                    'notes' => $rejection->notes,
                ]),
        );
    }

    public function mostSellingProducts(Request $request): JsonResponse
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())->startOfDay()
            : null;
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())->endOfDay()
            : null;

        $rows = [];

        $salesQuery = SalesOrder::query();
        if ($from !== null) {
            $salesQuery->where(function ($query) use ($from): void {
                $query->whereDate('sold_at', '>=', $from)
                    ->orWhere(function ($nested) use ($from): void {
                        $nested->whereNull('sold_at')
                            ->whereDate('order_date', '>=', $from);
                    })
                    ->orWhere(function ($nested) use ($from): void {
                        $nested->whereNull('sold_at')
                            ->whereNull('order_date')
                            ->whereDate('created_at', '>=', $from);
                    });
            });
        }
        if ($to !== null) {
            $salesQuery->where(function ($query) use ($to): void {
                $query->whereDate('sold_at', '<=', $to)
                    ->orWhere(function ($nested) use ($to): void {
                        $nested->whereNull('sold_at')
                            ->whereDate('order_date', '<=', $to);
                    })
                    ->orWhere(function ($nested) use ($to): void {
                        $nested->whereNull('sold_at')
                            ->whereNull('order_date')
                            ->whereDate('created_at', '<=', $to);
                    });
            });
        }

        foreach ($salesQuery->get() as $order) {
            $this->accumulateMostSellingRow(
                $rows,
                productName: (string) ($order->product_name ?: 'Unknown Product'),
                unit: (string) ($order->unit ?: 'pcs'),
                soldQty: $this->soldQuantityForOrder($order),
                revenue: $this->salesOrderRevenue($order),
                clientName: $order->client_name,
                source: 'sales_order',
            );
        }

        $finishedQuery = FinishedGood::query()->with('client')->where('dispatched', '>', 0);
        if ($from !== null) {
            $finishedQuery->where(function ($query) use ($from): void {
                $query->whereDate('updated_at', '>=', $from)
                    ->orWhereDate('production_date', '>=', $from);
            });
        }
        if ($to !== null) {
            $finishedQuery->where(function ($query) use ($to): void {
                $query->whereDate('updated_at', '<=', $to)
                    ->orWhereDate('production_date', '<=', $to);
            });
        }

        foreach ($finishedQuery->get() as $product) {
            $this->accumulateMostSellingRow(
                $rows,
                productName: (string) ($product->product_name ?: 'Unknown Product'),
                unit: 'pcs',
                soldQty: (int) $product->dispatched,
                revenue: 0.0,
                clientName: $product->client?->name,
                source: 'dispatch',
            );
        }

        $sorted = collect($rows)
            ->sortByDesc('sold_qty')
            ->values()
            ->map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;
                $row['revenue_display'] = '₹'.number_format((float) $row['revenue'], 0);
                $row['avg_order_qty'] = $row['order_count'] > 0
                    ? round($row['sold_qty'] / $row['order_count'], 1)
                    : 0;
                $row['top_client'] = $row['top_client'] ?? '—';
                $row['share_percent'] = 0.0;

                return $row;
            });

        $totalSold = (int) $sorted->sum('sold_qty');
        $totalRevenue = (float) $sorted->sum('revenue');

        $sorted = $sorted->map(function (array $row) use ($totalSold): array {
            $row['share_percent'] = $totalSold > 0
                ? round(((int) $row['sold_qty'] / $totalSold) * 100, 1)
                : 0.0;

            return $row;
        });

        return response()->json([
            'summary' => [
                'total_products' => $sorted->count(),
                'total_sold_qty' => $totalSold,
                'total_revenue' => round($totalRevenue, 2),
                'total_revenue_display' => '₹'.number_format($totalRevenue, 0),
                'top_product' => $sorted->first()['product'] ?? '—',
            ],
            'rows' => $sorted->all(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function accumulateMostSellingRow(
        array &$rows,
        string $productName,
        string $unit,
        int $soldQty,
        float $revenue,
        ?string $clientName,
        string $source,
    ): void {
        if ($soldQty <= 0 && $revenue <= 0) {
            return;
        }

        $key = strtolower(trim($productName));
        if ($key === '') {
            $key = 'unknown product';
        }

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'product' => trim($productName),
                'unit' => $unit,
                'sold_qty' => 0,
                'order_count' => 0,
                'revenue' => 0.0,
                'client_counts' => [],
                'sources' => [],
            ];
        }

        $rows[$key]['sold_qty'] += max(0, $soldQty);
        $rows[$key]['order_count']++;
        $rows[$key]['revenue'] = round($rows[$key]['revenue'] + max(0, $revenue), 2);
        $rows[$key]['sources'][$source] = true;

        if (filled($clientName)) {
            $client = trim((string) $clientName);
            $rows[$key]['client_counts'][$client] = ($rows[$key]['client_counts'][$client] ?? 0) + 1;
            arsort($rows[$key]['client_counts']);
            $rows[$key]['top_client'] = array_key_first($rows[$key]['client_counts']) ?? '—';
        }
    }

    private function soldQuantityForOrder(SalesOrder $order): int
    {
        $qty = (int) ($order->fulfilled_qty ?? 0);
        if ($qty < 1 && in_array($order->status, ['fulfilled', 'partial'], true)) {
            $qty = (int) $order->quantity;
        }

        return max(0, $qty);
    }

    private function salesOrderRevenue(SalesOrder $order): float
    {
        $qty = $this->soldQuantityForOrder($order);
        if ($qty < 1) {
            return 0.0;
        }

        return round($qty * (float) ($order->unit_price ?? 0), 2);
    }

    private function inventoryCategoryLabel(?string $type): string
    {
        $normalized = strtolower(str_replace(' ', '_', $type ?? 'other'));

        return match (true) {
            str_contains($normalized, 'raw') => 'Raw Material',
            str_contains($normalized, 'wip') => 'Work In Progress',
            str_contains($normalized, 'finished') => 'Finished Goods',
            default => ucwords(str_replace('_', ' ', $normalized)),
        };
    }

    private function stockStatus(InventoryItem $item): string
    {
        if ((int) $item->quantity <= 0) {
            return 'Out of Stock';
        }

        if ($item->min_stock !== null && (int) $item->quantity <= (int) $item->min_stock) {
            return 'Low Stock';
        }

        return 'In Stock';
    }
}
