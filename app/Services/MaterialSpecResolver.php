<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\ProductionBatch;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use Illuminate\Support\Facades\Schema;

class MaterialSpecResolver
{
    /**
     * @return array{grade: ?string, diameter_mm: ?float}
     */
    public function forWorkOrderMaterial(WorkOrderMaterial $line): array
    {
        $grade = filled($line->grade) ? strtoupper(trim((string) $line->grade)) : null;
        $diameter = $line->diameter_mm !== null ? (float) $line->diameter_mm : null;

        if ($grade !== null && $diameter !== null && $diameter > 0) {
            return ['grade' => $grade, 'diameter_mm' => $diameter];
        }

        $line->loadMissing('inventoryItem');

        foreach (array_filter([
            $line->inventoryItem?->name,
            $line->inventoryItem?->sku,
        ]) as $text) {
            $parsed = $this->parseGradeDiameterFromText($text);
            if ($parsed !== null) {
                return $parsed;
            }

            $catalog = $this->catalogSpecForMaterialKey($text);
            if ($catalog !== null) {
                return $catalog;
            }
        }

        return [
            'grade' => $grade,
            'diameter_mm' => ($diameter !== null && $diameter > 0) ? $diameter : null,
        ];
    }

    /**
     * @return array{grade: string, diameter_mm: float}|null
     */
    public function forBatch(ProductionBatch $batch): ?array
    {
        if ($batch->work_order_id !== null) {
            $order = WorkOrder::query()
                ->with(['materials.inventoryItem'])
                ->find($batch->work_order_id);

            if ($order !== null) {
                foreach ($order->materials as $line) {
                    $resolved = $this->forWorkOrderMaterial($line);
                    if (filled($resolved['grade']) && ($resolved['diameter_mm'] ?? 0) > 0) {
                        return [
                            'grade' => (string) $resolved['grade'],
                            'diameter_mm' => (float) $resolved['diameter_mm'],
                        ];
                    }
                }
            }
        }

        $productName = trim((string) ($batch->product_name ?? ''));
        if ($productName !== '') {
            $parsed = $this->parseGradeDiameterFromText($productName)
                ?? $this->catalogSpecForMaterialKey($productName);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $transaction = InventoryTransaction::query()
            ->where('type', 'checkout')
            ->where(function ($query) use ($batch): void {
                $query->where('notes', 'like', '%'.$batch->batch_number.'%');

                if (Schema::hasColumn('inventory_transactions', 'production_batch_id')) {
                    $query->orWhere('production_batch_id', $batch->id);
                }

                if ($batch->work_order_id !== null
                    && Schema::hasColumn('inventory_transactions', 'work_order_id')) {
                    $query->orWhere('work_order_id', $batch->work_order_id);
                }
            })
            ->orderByDesc('id')
            ->first();

        if ($transaction !== null && filled($transaction->material_name)) {
            return $this->parseGradeDiameterFromText((string) $transaction->material_name)
                ?? $this->catalogSpecForMaterialKey((string) $transaction->material_name);
        }

        return null;
    }

    /**
     * @return array{grade: string, diameter_mm: float}|null
     */
    public function parseGradeDiameterFromText(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (! preg_match('/\b(EN\s*\d+)\b/i', $text, $gradeMatch)) {
            return null;
        }

        $grade = strtoupper(preg_replace('/\s+/', '', $gradeMatch[1]));

        if (preg_match('/(?:DIA|Ø)\s*(\d+(?:\.\d+)?)/i', $text, $diaMatch)) {
            $dia = (float) $diaMatch[1];
            if ($dia > 0) {
                return ['grade' => $grade, 'diameter_mm' => $dia];
            }
        }

        return null;
    }

    /**
     * @return array{grade: string, diameter_mm: float}|null
     */
    public function catalogSpecForMaterialKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        $lower = strtolower($key);

        $item = Item::query()
            ->where('category', 'raw_material')
            ->whereNotNull('grade')
            ->whereNotNull('diameter_mm')
            ->where(function ($query) use ($lower): void {
                $query->whereRaw('LOWER(code) = ?', [$lower])
                    ->orWhereRaw('LOWER(name) = ?', [$lower]);
            })
            ->first();

        if ($item !== null && filled($item->grade) && (float) $item->diameter_mm > 0) {
            return [
                'grade' => strtoupper(trim((string) $item->grade)),
                'diameter_mm' => (float) $item->diameter_mm,
            ];
        }

        $compact = strtolower(preg_replace('/\s+/', '', $key));

        $catalogItems = Item::query()
            ->where('category', 'raw_material')
            ->whereNotNull('grade')
            ->whereNotNull('diameter_mm')
            ->get();

        foreach ($catalogItems as $catalogItem) {
            $gradeCompact = strtolower(preg_replace('/\s+/', '', (string) $catalogItem->grade));
            $dia = (float) $catalogItem->diameter_mm;
            if ($gradeCompact === '' || $dia <= 0) {
                continue;
            }

            $diaInt = (int) round($dia);
            if (str_contains($compact, $gradeCompact)
                && (str_contains($compact, 'dia'.$diaInt)
                    || str_contains($compact, 'dia'.(string) $dia)
                    || (str_contains($compact, 'dia') && str_contains($compact, (string) $diaInt)))) {
                return [
                    'grade' => strtoupper(trim((string) $catalogItem->grade)),
                    'diameter_mm' => $dia,
                ];
            }
        }

        return null;
    }
}
