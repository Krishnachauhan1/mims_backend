<?php

namespace App\Support;

use App\Models\InventoryItem;

class InventoryStockStatus
{
    /** Workflow statuses — not overridden by quantity alone. */
    private const PRESERVED = [
        'pending_qc',
        'pending_packing',
        'rejected',
        'dispatched',
        'on_hold',
        'damaged',
    ];

    public static function forItem(InventoryItem $item): string
    {
        return self::resolve(
            (int) $item->quantity,
            (int) $item->min_stock,
            (string) ($item->status ?? 'available'),
        );
    }

    public static function resolve(int $quantity, int $minStock, string $storedStatus): string
    {
        $status = strtolower(trim($storedStatus));
        if ($status === '') {
            $status = 'available';
        }

        if (in_array($status, self::PRESERVED, true)) {
            return $storedStatus;
        }

        if ($quantity <= 0) {
            return 'out_of_stock';
        }

        if ($minStock > 0 && $quantity <= $minStock) {
            return 'low_stock';
        }

        if (in_array($status, ['out_of_stock', 'low_stock'], true)) {
            return 'available';
        }

        return $storedStatus;
    }
}
