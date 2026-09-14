<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\JobWork;
use App\Models\Process;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\WorkOrderMaterial;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InventoryCostService
{
    /** @var Collection<string, Item> */
    private Collection $itemsByName;

    /** @var array<string, Item> */
    private array $itemsByLookupKey = [];

    /** @var Collection<string, PurchaseOrder> */
    private Collection $purchaseOrdersByNumber;

    /** @var Collection<string, ProductionBatch> */
    private Collection $batchesByNumber;

    /** @var Collection<string, Process> */
    private Collection $processesByName;

    /** @var array<int, float|null> */
    private array $batchUnitCostCache = [];

    public function warmFor(Collection $inventoryItems): void
    {
        $items = Item::query()->get();
        $this->itemsByName = $items->keyBy(fn (Item $item) => $this->normalizeKey($item->name));
        $this->buildCatalogLookupIndex($items);

        $poNumbers = $inventoryItems
            ->pluck('batch_number')
            ->filter()
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        $this->purchaseOrdersByNumber = $poNumbers->isEmpty()
            ? collect()
            : PurchaseOrder::query()
                ->whereIn('po_number', $poNumbers)
                ->get()
                ->keyBy(fn (PurchaseOrder $po) => $this->normalizeKey($po->po_number));

        $batchNumbers = $inventoryItems
            ->pluck('batch_number')
            ->filter()
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        $this->batchesByNumber = $batchNumbers->isEmpty()
            ? collect()
            : ProductionBatch::query()
                ->whereIn('batch_number', $batchNumbers)
                ->get()
                ->keyBy(fn (ProductionBatch $batch) => $this->normalizeKey($batch->batch_number));

        $this->processesByName = Process::query()
            ->get()
            ->keyBy(fn (Process $process) => $this->normalizeKey($process->name));
    }

    /**
     * @return array{item_code: ?string, description: ?string, catalog_name: ?string}
     */
    public function catalogDisplayFor(InventoryItem $item): array
    {
        $this->ensureMasterItemsLoaded();

        $master = null;
        foreach ([$item->name, $item->sku] as $value) {
            if (! filled($value)) {
                continue;
            }

            $master = $this->itemsByLookupKey[$this->normalizeKey($value)] ?? null;
            if ($master !== null) {
                break;
            }
        }

        if ($master === null) {
            return [
                'item_code' => null,
                'description' => null,
                'catalog_name' => null,
            ];
        }

        $code = filled($master->code) ? trim((string) $master->code) : null;
        $description = filled($master->description) ? trim((string) $master->description) : null;
        if ($description !== null && $code !== null && strcasecmp($description, $code) === 0) {
            $description = null;
        }

        return [
            'item_code' => $code,
            'description' => $description,
            'catalog_name' => filled($master->name) ? trim((string) $master->name) : null,
        ];
    }

    /**
     * @param  Collection<int, Item>  $items
     */
    private function buildCatalogLookupIndex(Collection $items): void
    {
        $this->itemsByLookupKey = [];

        foreach ($items as $item) {
            foreach ([$item->name, $item->code, $item->description] as $value) {
                if (filled($value)) {
                    $this->itemsByLookupKey[$this->normalizeKey($value)] = $item;
                }
            }

            if (filled($item->grade) && (float) ($item->diameter_mm ?? 0) > 0) {
                $dia = (float) $item->diameter_mm;
                $diaLabel = fmod($dia, 1.0) === 0.0 ? (string) (int) $dia : (string) $dia;
                $this->itemsByLookupKey[$this->normalizeKey(strtoupper($item->grade).' DIA '.$diaLabel)] = $item;
            }
        }
    }

    /**
     * @return array{
     *     unit_cost: float|null,
     *     total_value: float|null,
     *     cost_type: string|null,
     *     manufacturing_cost: float|null
     * }
     */
    public function costFieldsFor(InventoryItem $item): array
    {
        $unitCost = match ($item->type) {
            'raw_material' => $this->rawMaterialUnitCost($item),
            'finished_goods', 'wip' => $this->manufacturingUnitCost($item),
            default => null,
        };

        if ($unitCost === null || $unitCost <= 0) {
            return [
                'unit_cost' => null,
                'total_value' => null,
                'cost_type' => null,
                'manufacturing_cost' => null,
            ];
        }

        $unitCost = round($unitCost, 2);
        $totalValue = round($unitCost * max(0, (int) $item->quantity), 2);
        $costType = in_array($item->type, ['finished_goods', 'wip'], true)
            ? 'manufacturing'
            : 'purchase';

        return [
            'unit_cost' => $unitCost,
            'total_value' => $totalValue,
            'cost_type' => $costType,
            'manufacturing_cost' => $costType === 'manufacturing' ? $unitCost : null,
        ];
    }

    private function rawMaterialUnitCost(InventoryItem $item): ?float
    {
        $this->ensureMasterItemsLoaded();

        $poNumber = trim((string) ($item->batch_number ?? ''));
        if ($poNumber !== '') {
            $purchaseOrder = $this->purchaseOrdersByNumber[$this->normalizeKey($poNumber)] ?? null;
            if ($purchaseOrder !== null) {
                $fromPo = $this->purchasePriceFromOrder($purchaseOrder, $item->name);
                if ($fromPo !== null && $fromPo > 0) {
                    return $fromPo;
                }
            }
        }

        $master = $this->itemsByName[$this->normalizeKey($item->name)] ?? null;
        if ($master !== null && (float) $master->purchase_price > 0) {
            return (float) $master->purchase_price;
        }

        return null;
    }

    private function manufacturingUnitCost(InventoryItem $item): ?float
    {
        $batchNumber = trim((string) ($item->batch_number ?? ''));
        if ($batchNumber === '') {
            return null;
        }

        $batch = $this->resolveBatch($batchNumber);
        if ($batch === null) {
            return null;
        }

        if (array_key_exists($batch->id, $this->batchUnitCostCache)) {
            return $this->batchUnitCostCache[$batch->id];
        }

        $this->ensureMasterItemsLoaded();
        if (! isset($this->processesByName)) {
            $this->processesByName = Process::query()
                ->get()
                ->keyBy(fn (Process $process) => $this->normalizeKey($process->name));
        }

        $batchQty = max(1, (int) ($batch->quantity ?: $item->quantity ?: 1));
        $totalCost = $this->batchTotalManufacturingCost($batch, $batchQty);
        $unitCost = $totalCost > 0 ? round($totalCost / $batchQty, 2) : null;
        $this->batchUnitCostCache[$batch->id] = $unitCost;

        return $unitCost;
    }

    private function batchTotalManufacturingCost(ProductionBatch $batch, int $batchQty): float
    {
        $totalCost = 0.0;

        if ($batch->work_order_id) {
            $materials = WorkOrderMaterial::query()
                ->with('inventoryItem')
                ->where('work_order_id', $batch->work_order_id)
                ->get();

            foreach ($materials as $material) {
                $inventoryMaterial = $material->inventoryItem;
                if ($inventoryMaterial === null) {
                    continue;
                }

                if (! isset($this->itemsByName)) {
                    $this->ensureMasterItemsLoaded();
                }

                $materialItems = collect([$inventoryMaterial]);
                if (! isset($this->purchaseOrdersByNumber)) {
                    $this->warmFor($materialItems);
                }

                $materialUnitCost = $this->rawMaterialUnitCost($inventoryMaterial) ?? 0.0;
                $totalCost += $materialUnitCost * (int) $material->quantity;
            }
        }

        $jobWorks = JobWork::query()
            ->where(function ($query) use ($batch): void {
                $query->where('batch_id', $batch->id);
                if ($batch->work_order_id) {
                    $query->orWhere('work_order_id', $batch->work_order_id);
                }
            })
            ->get();

        foreach ($jobWorks as $jobWork) {
            $totalCost += $this->jobWorkTotal($jobWork);
        }

        foreach ($batch->stage_history ?? [] as $stage) {
            if (! is_array($stage)) {
                continue;
            }

            $stageName = trim((string) ($stage['stage']
                ?? $stage['name']
                ?? $stage['process_name']
                ?? ''));
            if ($stageName === '') {
                continue;
            }

            $process = ($this->processesByName ?? collect())
                ->get($this->normalizeKey($stageName));
            if ($process === null || (float) $process->rate <= 0) {
                continue;
            }

            $totalCost += (float) $process->rate * $batchQty;
        }

        return $totalCost;
    }

    public function manufacturingUnitCostForProduct(string $productName): ?float
    {
        $key = $this->normalizeKey($productName);
        if ($key === '') {
            return null;
        }

        $fromInventory = $this->weightedManufacturingCostFromInventory($key);
        if ($fromInventory !== null) {
            return $fromInventory;
        }

        $fromBatch = $this->latestBatchManufacturingCostForProduct($key, $productName);
        if ($fromBatch !== null) {
            return $fromBatch;
        }

        $this->ensureMasterItemsLoaded();
        $item = $this->itemsByName[$key] ?? null;
        if ($item !== null && (float) $item->purchase_price > 0) {
            return round((float) $item->purchase_price, 2);
        }

        return null;
    }

    private function weightedManufacturingCostFromInventory(string $productKey): ?float
    {
        $items = InventoryItem::query()
            ->where('type', 'finished_goods')
            ->get()
            ->filter(fn (InventoryItem $item) => $this->normalizeKey($item->name) === $productKey);

        if ($items->isEmpty()) {
            return null;
        }

        $this->warmFor($items);

        $totalQty = 0;
        $totalCost = 0.0;

        foreach ($items as $item) {
            $fields = $this->costFieldsFor($item);
            $unit = $fields['manufacturing_cost'] ?? $fields['unit_cost'] ?? null;
            if ($unit === null || $unit <= 0) {
                continue;
            }

            $qty = max(0, (int) $item->quantity);
            if ($qty < 1) {
                continue;
            }

            $totalCost += $unit * $qty;
            $totalQty += $qty;
        }

        return $totalQty > 0 ? round($totalCost / $totalQty, 2) : null;
    }

    private function latestBatchManufacturingCostForProduct(
        string $productKey,
        string $productName,
    ): ?float {
        $batch = ProductionBatch::query()
            ->whereNotNull('batch_number')
            ->orderByDesc('completed_at')
            ->orderByDesc('updated_at')
            ->get()
            ->first(fn (ProductionBatch $candidate) => $this->normalizeKey($candidate->product_name) === $productKey);

        if ($batch === null) {
            return null;
        }

        if (array_key_exists($batch->id, $this->batchUnitCostCache)) {
            return $this->batchUnitCostCache[$batch->id];
        }

        $stub = new InventoryItem([
            'name' => $productName,
            'type' => 'finished_goods',
            'batch_number' => $batch->batch_number,
            'quantity' => max(1, (int) $batch->quantity),
            'unit' => $batch->unit ?? 'pcs',
        ]);

        $this->warmFor(collect([$stub]));

        return $this->manufacturingUnitCost($stub);
    }

    private function resolveBatch(string $batchNumber): ?ProductionBatch
    {
        $key = $this->normalizeKey($batchNumber);
        if (isset($this->batchesByNumber[$key])) {
            return $this->batchesByNumber[$key];
        }

        return ProductionBatch::query()
            ->where('batch_number', $batchNumber)
            ->first();
    }

    private function ensureMasterItemsLoaded(): void
    {
        if (isset($this->itemsByName)) {
            return;
        }

        $this->itemsByName = Item::query()
            ->get()
            ->keyBy(fn (Item $item) => $this->normalizeKey($item->name));

        $this->buildCatalogLookupIndex($this->itemsByName->values());
    }

    private function purchasePriceFromOrder(PurchaseOrder $purchaseOrder, string $itemName): ?float
    {
        $target = $this->normalizeKey($itemName);
        $items = $purchaseOrder->items ?? [];
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lineName = $this->normalizeKey((string) ($line['item_name'] ?? ''));
            if ($lineName !== $target) {
                continue;
            }

            $price = (float) ($line['purchase_price'] ?? 0);
            if ($price > 0) {
                return $price;
            }
        }

        return null;
    }

    private function jobWorkTotal(JobWork $jobWork): float
    {
        $total = (float) ($jobWork->total_amount ?? 0);
        if ($total > 0) {
            return $total;
        }

        return (float) ($jobWork->quantity ?? 0) * (float) ($jobWork->rate ?? 0);
    }

    private function normalizeKey(?string $value): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
    }
}
