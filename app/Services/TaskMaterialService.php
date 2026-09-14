<?php

namespace App\Services;

use App\Models\DashboardTask;
use App\Models\DashboardTaskMaterial;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\ProductionBatch;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TaskMaterialService
{
    /**
     * @param  array<int, array{inventory_item_id: int, quantity: int}>  $materials
     */
    public function attachAndConsumeForTask(
        DashboardTask $task,
        array $materials,
        ?int $userId = null,
    ): void {
        $this->attachAndConsume(
            $task,
            $materials,
            "Task #{$task->id}: {$task->task_name}",
            $task->assigned_to,
            $userId,
        );
    }

    /**
     * @param  array<int, array{inventory_item_id: int, quantity: int}>  $materials
     */
    public function attachAndConsumeForWorkOrder(
        WorkOrder $order,
        array $materials,
        ?int $userId = null,
    ): void {
        $destination = $order->client_name ?: $order->workflow_name;
        $this->attachAndConsume(
            $order,
            $materials,
            "Work order #{$order->id}: {$order->workflow_name}",
            $destination,
            $userId,
        );
    }

    /**
     * @param  array<int, array{inventory_item_id: int, quantity: int}>  $materials
     */
    public function attachMaterialsForWorkOrder(WorkOrder $order, array $materials): void
    {
        if ($materials === [] || ! $this->workOrderMaterialsTableExists()) {
            return;
        }

        foreach ($materials as $line) {
            $item = InventoryItem::query()->find($line['inventory_item_id']);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'materials' => ['Selected inventory item was not found.'],
                ]);
            }

            $qty = (int) $line['quantity'];
            if ($qty < 1) {
                throw ValidationException::withMessages([
                    'materials' => ['Material quantity must be at least 1.'],
                ]);
            }

            WorkOrderMaterial::query()->create([
                'work_order_id' => $order->id,
                'inventory_item_id' => $item->id,
                'grade' => isset($line['grade']) ? strtoupper(trim((string) $line['grade'])) : null,
                'diameter_mm' => isset($line['diameter_mm']) ? round((float) $line['diameter_mm'], 2) : null,
                'quantity' => $qty,
                'unit' => $item->unit,
            ]);
        }
    }

    public function consumeForProductionBatch(ProductionBatch $batch, ?int $userId = null): void
    {
        if ($this->productionBatchMaterialsAlreadyConsumed($batch)) {
            return;
        }

        if ($batch->work_order_id !== null) {
            $this->consumeForWorkOrderProductionBatch($batch, $userId);

            return;
        }

        if ($batch->checkin_id !== null) {
            $this->consumeForCheckinProductionBatch($batch, $userId);
        }
    }

    private function consumeForWorkOrderProductionBatch(ProductionBatch $batch, ?int $userId = null): void
    {
        $order = WorkOrder::query()->find($batch->work_order_id);
        if ($order === null) {
            return;
        }

        if ($this->workOrderMaterialsAlreadyConsumed($order)) {
            return;
        }

        $materials = $this->resolveMaterialsFromWorkOrder($order, max(1, (int) $batch->quantity));

        if ($materials === []) {
            return;
        }

        $destination = $order->client_name ?: $order->workflow_name;
        $this->attachAndConsume(
            $batch,
            $materials,
            "Production batch {$batch->batch_number} — work order #{$order->id}",
            $destination,
            $userId,
            linkProductionBatchId: $batch->id,
            linkWorkOrderId: $order->id,
        );
    }

    private function consumeForCheckinProductionBatch(ProductionBatch $batch, ?int $userId = null): void
    {
        $item = $this->findRawMaterialInventoryItem($batch->product_name);
        if ($item === null) {
            return;
        }

        $qty = min(max(1, (int) $batch->quantity), (int) $item->quantity);
        if ($qty < 1) {
            return;
        }

        $this->attachAndConsume(
            $batch,
            [
                [
                    'inventory_item_id' => $item->id,
                    'quantity' => $qty,
                ],
            ],
            "Production batch {$batch->batch_number} — check-in #{$batch->checkin_id}",
            $batch->supplier ?? 'Production',
            $userId,
            linkProductionBatchId: $batch->id,
        );
    }

    /**
     * @return array<int, array{inventory_item_id: int, quantity: int}>
     */
    private function resolveMaterialsFromWorkOrder(WorkOrder $order, int $fallbackQuantity): array
    {
        if ($this->workOrderMaterialsTableExists()) {
            $order->loadMissing('materials');
            if ($order->materials->isNotEmpty()) {
                return $order->materials
                    ->map(fn (WorkOrderMaterial $line) => [
                        'inventory_item_id' => (int) $line->inventory_item_id,
                        'quantity' => (int) $line->quantity,
                    ])
                    ->all();
            }
        }

        return $this->resolveAutoMaterialsForWorkOrder($order, $fallbackQuantity);
    }

    /**
     * @return array<int, array{inventory_item_id: int, quantity: int}>
     */
    private function resolveAutoMaterialsForWorkOrder(WorkOrder $order, int $quantity): array
    {
        $item = $this->findRawMaterialInventoryItem($order->product, $order->item_id);
        if ($item === null) {
            return [];
        }

        $qty = min(max(1, $quantity), (int) $item->quantity);

        return [
            [
                'inventory_item_id' => $item->id,
                'quantity' => $qty,
            ],
        ];
    }

    private function findRawMaterialInventoryItem(?string $productName, ?int $itemId = null): ?InventoryItem
    {
        if ($itemId !== null) {
            $master = Item::query()->find($itemId);
            if ($master !== null) {
                $byMaster = InventoryItem::query()
                    ->where('type', 'raw_material')
                    ->where('name', $master->name)
                    ->where('quantity', '>', 0)
                    ->orderByDesc('quantity')
                    ->first();

                if ($byMaster !== null) {
                    return $byMaster;
                }
            }
        }

        $name = trim((string) $productName);
        if ($name === '') {
            return null;
        }

        return InventoryItem::query()
            ->where('type', 'raw_material')
            ->where('quantity', '>', 0)
            ->where(function ($query) use ($name): void {
                $query->where('name', $name)
                    ->orWhere('name', 'like', $name.'%')
                    ->orWhere('name', 'like', '%'.$name.'%');
            })
            ->orderByDesc('quantity')
            ->first()
            ?? InventoryItem::query()
                ->where('quantity', '>', 0)
                ->where('type', '!=', 'finished_goods')
                ->where(function ($query) use ($name): void {
                    $query->where('name', $name)
                        ->orWhere('name', 'like', '%'.$name.'%');
                })
                ->orderByDesc('quantity')
                ->first();
    }

    private function workOrderMaterialsAlreadyConsumed(WorkOrder $order): bool
    {
        return InventoryTransaction::query()
            ->where('type', 'checkout')
            ->where(function ($query) use ($order): void {
                $query->where('notes', 'like', '%Work order #'.$order->id.':%')
                    ->orWhere('notes', 'like', '%work order #'.$order->id.'%');

                if (Schema::hasColumn('inventory_transactions', 'work_order_id')) {
                    $query->orWhere('work_order_id', $order->id);
                }
            })
            ->exists();
    }

    private function productionBatchMaterialsAlreadyConsumed(ProductionBatch $batch): bool
    {
        return InventoryTransaction::query()
            ->where('type', 'checkout')
            ->where(function ($query) use ($batch): void {
                $query->where('notes', 'like', '%Production batch '.$batch->batch_number.'%');

                if (Schema::hasColumn('inventory_transactions', 'production_batch_id')) {
                    $query->orWhere('production_batch_id', $batch->id);
                }
            })
            ->exists();
    }

    private function workOrderMaterialsTableExists(): bool
    {
        return Schema::hasTable('work_order_materials');
    }

    private function shouldCreateMaterialPivot(Model $owner): bool
    {
        return match ($owner::class) {
            WorkOrder::class => $this->workOrderMaterialsTableExists(),
            DashboardTask::class => Schema::hasTable('dashboard_task_materials'),
            default => false,
        };
    }

    /**
     * @param  array<int, array{inventory_item_id: int, quantity: int}>  $materials
     */
    public function attachAndConsume(
        Model $owner,
        array $materials,
        string $referenceLabel,
        ?string $destination = null,
        ?int $userId = null,
        ?int $linkProductionBatchId = null,
        ?int $linkWorkOrderId = null,
    ): void {
        if ($materials === []) {
            return;
        }

        [$pivotModel, $foreignKey] = match ($owner::class) {
            WorkOrder::class => [WorkOrderMaterial::class, 'work_order_id'],
            DashboardTask::class => [DashboardTaskMaterial::class, 'dashboard_task_id'],
            ProductionBatch::class => [null, null],
            default => throw new \InvalidArgumentException('Unsupported material owner model.'),
        };

        DB::transaction(function () use (
            $owner,
            $materials,
            $referenceLabel,
            $destination,
            $userId,
            $linkProductionBatchId,
            $linkWorkOrderId,
            $pivotModel,
            $foreignKey,
        ): void {
            foreach ($materials as $line) {
                $item = InventoryItem::query()
                    ->lockForUpdate()
                    ->find($line['inventory_item_id']);

                if ($item === null) {
                    throw ValidationException::withMessages([
                        'materials' => ['Selected inventory item was not found.'],
                    ]);
                }

                $qty = (int) $line['quantity'];
                if ($qty < 1) {
                    throw ValidationException::withMessages([
                        'materials' => ['Material quantity must be at least 1.'],
                    ]);
                }

                if ($item->quantity < $qty) {
                    throw ValidationException::withMessages([
                        'materials' => [
                            "Insufficient stock for {$item->name}. Available: {$item->quantity}, requested: {$qty}.",
                        ],
                    ]);
                }

                $item->update([
                    'quantity' => $item->quantity - $qty,
                ]);

                if ($pivotModel !== null && $foreignKey !== null && $this->shouldCreateMaterialPivot($owner)) {
                    $pivotModel::query()->create([
                        $foreignKey => $owner->id,
                        'inventory_item_id' => $item->id,
                        'quantity' => $qty,
                        'unit' => $item->unit,
                    ]);
                }

                $transactionData = [
                    'type' => 'checkout',
                    'material_name' => $item->name,
                    'destination' => $destination,
                    'quantity' => $qty,
                    'unit' => $item->unit ?? 'pcs',
                    'batch_number' => $item->batch_number,
                    'notes' => $referenceLabel,
                    'status' => 'completed',
                    'user_id' => $userId,
                ];

                if (Schema::hasColumn('inventory_transactions', 'production_batch_id')) {
                    $transactionData['production_batch_id'] = $linkProductionBatchId;
                }
                if (Schema::hasColumn('inventory_transactions', 'work_order_id')) {
                    $transactionData['work_order_id'] = $linkWorkOrderId ?? ($owner instanceof WorkOrder ? $owner->id : null);
                }
                if (Schema::hasColumn('inventory_transactions', 'flow_source')) {
                    $transactionData['flow_source'] = 'production';
                }

                InventoryTransaction::query()->create($transactionData);
            }
        });
    }
}
