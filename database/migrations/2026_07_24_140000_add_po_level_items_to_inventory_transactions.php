<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_transactions', 'items')) {
                $table->json('items')->nullable()->after('pr_number');
            }
        });

        $this->consolidatePoCheckins();
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_transactions', 'items')) {
                $table->dropColumn('items');
            }
        });
    }

    private function consolidatePoCheckins(): void
    {
        $rows = DB::table('inventory_transactions')
            ->where('type', 'checkin')
            ->whereNotNull('po_number')
            ->where(function ($query): void {
                $query->whereNull('flow_source')
                    ->orWhere('flow_source', 'purchase_order');
            })
            ->orderBy('id')
            ->get();

        $groups = $rows->groupBy('po_number');

        foreach ($groups as $poNumber => $group) {
            if ($group->count() <= 1 && ! empty($group->first()->items)) {
                continue;
            }

            $items = [];
            foreach ($group as $row) {
                if (! empty($row->items)) {
                    $decoded = json_decode($row->items, true);
                    if (is_array($decoded)) {
                        $items = array_merge($items, $decoded);

                        continue;
                    }
                }

                if (! empty($row->material_name)) {
                    $items[] = [
                        'item_name' => $row->material_name,
                        'quantity' => (int) $row->quantity,
                        'unit' => $row->unit ?? 'pcs',
                    ];
                }
            }

            if ($items === []) {
                continue;
            }

            $canonical = $group->sortByDesc(fn ($row) => $this->phaseRank($row->receipt_phase ?? $row->status))->first();
            $totalQty = array_sum(array_map(fn ($item) => (int) ($item['quantity'] ?? 0), $items));

            DB::table('inventory_transactions')
                ->where('id', $canonical->id)
                ->update([
                    'material_name' => $poNumber,
                    'items' => json_encode(array_values($items)),
                    'quantity' => $totalQty,
                    'flow_source' => 'purchase_order',
                    'updated_at' => now(),
                ]);

            $duplicateIds = $group->pluck('id')->filter(fn ($id) => $id !== $canonical->id)->values();
            if ($duplicateIds->isNotEmpty()) {
                DB::table('inventory_transactions')->whereIn('id', $duplicateIds)->delete();
            }
        }
    }

    private function phaseRank(?string $phase): int
    {
        return match ($phase) {
            'order_received', 'received' => 3,
            'checked_in', 'arrived', 'completed' => 2,
            default => 1,
        };
    }
};
