<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\ItemMachineTime;
use App\Services\CatalogInventorySyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->filled('grade') && $request->string('category')->toString() !== 'product') {
            $this->ensureInventoryRawMaterialsInCatalog();
        }

        $query = $this->filteredItemsQuery($request);

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min(100, max(5, (int) $request->integer('per_page', 20)));
            $page = max(1, (int) $request->integer('page', 1));

            $statsQuery = $this->baseItemsQuery($request);
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $paginator->getCollection()
                    ->map(fn (Item $item) => $this->format($item))
                    ->values(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'active_count' => (int) (clone $statsQuery)->where('status', 'active')->count(),
                    'inactive_count' => (int) (clone $statsQuery)->where('status', 'inactive')->count(),
                    'with_documents_count' => $this->countWithDocuments($statsQuery),
                ],
            ]);
        }

        return response()->json(
            $query->get()->map(fn (Item $item) => $this->format($item)),
        );
    }

    /**
     * @return Builder<Item>
     */
    private function filteredItemsQuery(Request $request): Builder
    {
        $query = $this->baseItemsQuery($request);
        $this->applyListFilters($query, $request);

        return $query;
    }

    /**
     * @return Builder<Item>
     */
    private function baseItemsQuery(Request $request): Builder
    {
        $query = Item::query()->latest();

        if ($request->filled('grade')) {
            $query->where('grade', $request->string('grade'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function (Builder $builder) use ($term) {
                $builder
                    ->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('grade', 'like', $term)
                    ->orWhere('cutting_size', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Item>  $query
     */
    private function applyListFilters(Builder $query, Request $request): void
    {
        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if (in_array($status, ['active', 'inactive'], true)) {
                $query->where('status', $status);
            }
        }

        if ($request->boolean('with_documents')) {
            $query->whereExists(function ($sub) {
                $sub->from('documents')
                    ->where('documents.module', 'item')
                    ->whereRaw('documents.entity_id = CAST(items.id AS CHAR)');
            });
        }
    }

    /**
     * @param  Builder<Item>  $query
     */
    private function countWithDocuments(Builder $query): int
    {
        $ids = (clone $query)->pluck('id')->map(fn ($id) => (string) $id);

        if ($ids->isEmpty()) {
            return 0;
        }

        return (int) Document::query()
            ->where('module', 'item')
            ->whereIn('entity_id', $ids)
            ->distinct()
            ->count('entity_id');
    }

    public function store(Request $request): JsonResponse
    {
        $item = Item::create($this->validatedPayload($request));
        if (class_exists(CatalogInventorySyncService::class)) {
            app(CatalogInventorySyncService::class)->ensureItem($item);
        }

        return response()->json($this->format($item), 201);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        $item->update($this->validatedPayload($request, $item));
        $item->refresh();
        if (class_exists(CatalogInventorySyncService::class)) {
            app(CatalogInventorySyncService::class)->ensureItem($item);
        }

        return response()->json($this->format($item));
    }

    public function destroy(Item $item): JsonResponse
    {
        $item->delete();

        return response()->json(['message' => 'Item deleted']);
    }

    public function machineTimes(Item $item): JsonResponse
    {
        $rows = ItemMachineTime::query()
            ->where('item_id', $item->id)
            ->with('machine:id,name,machine_code')
            ->get()
            ->map(fn (ItemMachineTime $row) => [
                'machine_id' => $row->machine_id,
                'machine_name' => $row->machine?->name,
                'machine_code' => $row->machine?->machine_code,
                'per_piece_time' => $row->per_piece_time,
                'average_time' => $row->average_time,
            ])
            ->values();

        return response()->json($rows);
    }

    public function upsertMachineTime(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'machine_id' => 'required|integer|exists:machinery,id',
            'per_piece_time' => 'required|numeric|min:0',
        ]);

        $row = ItemMachineTime::query()->updateOrCreate(
            [
                'item_id' => $item->id,
                'machine_id' => $data['machine_id'],
            ],
            [
                'per_piece_time' => round((float) $data['per_piece_time'], 2),
            ],
        );

        $row->load('machine:id,name,machine_code');

        return response()->json([
            'machine_id' => $row->machine_id,
            'machine_name' => $row->machine?->name,
            'machine_code' => $row->machine?->machine_code,
            'per_piece_time' => $row->per_piece_time,
            'average_time' => $row->average_time,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, ?Item $item = null): array
    {
        $data = $request->validate([
            'code' => 'required|string|max:255|unique:items,code,'.($item?->id ?? 'NULL'),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'grade' => 'nullable|string|max:50',
            'diameter_mm' => 'nullable|numeric|min:0',
            'cutting_weight' => 'nullable|numeric|min:0',
            'final_weight' => 'nullable|numeric|min:0',
            'cutting_size' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:50',
            'min_stock_alert' => 'nullable|integer|min:0',
            'per_piece_time' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'category' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:active,inactive',
            'remark' => 'nullable|string|max:1000',
        ]);

        $status = $data['status'] ?? 'active';

        if ($status === 'inactive') {
            Validator::make($request->all(), [
                'remark' => 'required|string|max:1000',
            ])->validate();

            $data['remark'] = trim((string) $request->input('remark'));
            $wasInactive = $item !== null && $item->status === 'inactive';
            $data['inactive_date'] = ($wasInactive && $item->inactive_date)
                ? $item->inactive_date->format('Y-m-d')
                : now()->toDateString();
        } else {
            $remark = trim((string) $request->input('remark', ''));
            $data['remark'] = $remark === '' ? null : $remark;
            $data['inactive_date'] = null;
        }

        if (empty($data['category'])) {
            $data['category'] = 'product';
        }

        return $data;
    }

    private function ensureInventoryRawMaterialsInCatalog(): void
    {
        $rows = InventoryItem::query()
            ->where('type', 'raw_material')
            ->select(['name', 'unit', 'min_stock'])
            ->get()
            ->unique(fn (InventoryItem $row) => strtolower(trim((string) $row->name)));

        foreach ($rows as $inventoryRow) {
            $name = trim((string) $inventoryRow->name);
            if ($name === '') {
                continue;
            }

            $normalized = strtolower($name);
            $existing = Item::query()
                ->where(function (Builder $query) use ($normalized) {
                    $query
                        ->whereRaw('LOWER(name) = ?', [$normalized])
                        ->orWhereRaw('LOWER(code) = ?', [$normalized])
                        ->orWhereRaw('LOWER(description) = ?', [$normalized]);
                })
                ->first();

            if ($existing !== null) {
                continue;
            }

            $spec = $this->parseRodSpecFromName($name);

            Item::create([
                'code' => $spec['code'] ?? $this->codeForInventoryName($name),
                'name' => $spec['name'] ?? $name,
                'description' => $name,
                'grade' => $spec['grade'],
                'diameter_mm' => $spec['diameter_mm'],
                'category' => 'raw_material',
                'unit' => $inventoryRow->unit ?: 'kg',
                'min_stock_alert' => max(0, (int) ($inventoryRow->min_stock ?? 0)),
                'status' => 'active',
            ]);
        }
    }

    /**
     * @return array{grade: ?string, diameter_mm: ?float, code: ?string, name: ?string}
     */
    private function parseRodSpecFromName(string $name): array
    {
        $result = [
            'grade' => null,
            'diameter_mm' => null,
            'code' => null,
            'name' => null,
        ];

        if (preg_match('/^(EN\d+[A-Z]?|MS|HC|HEX)\s+DIA\s+(\d+(?:\.\d+)?)/i', $name, $match)) {
            $grade = strtoupper($match[1]);
            $diameter = round((float) $match[2], 2);
            $diaLabel = fmod($diameter, 1.0) === 0.0 ? (string) (int) $diameter : (string) $diameter;
            $result['grade'] = $grade;
            $result['diameter_mm'] = $diameter;
            $result['code'] = $grade.'-'.$diaLabel;
            $result['name'] = $grade.' DIA '.$diaLabel;
        }

        return $result;
    }

    private function codeForInventoryName(string $name): string
    {
        $base = Str::upper(Str::slug($name, '-'));
        $code = Str::limit($base !== '' ? $base : 'RM', 50, '');
        $suffix = 1;

        while (Item::query()->where('code', $code)->exists()) {
            $code = Str::limit($base !== '' ? $base : 'RM', 45, '').'-'.$suffix;
            $suffix++;
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Item $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'description' => $item->description,
            'grade' => $item->grade,
            'diameter_mm' => $item->diameter_mm,
            'cutting_weight' => $item->cutting_weight,
            'final_weight' => $item->final_weight,
            'cutting_size' => $item->cutting_size,
            'remark' => $item->remark,
            'inactive_date' => $item->inactive_date?->format('Y-m-d'),
            'inactive_date_display' => $item->inactive_date?->format('d/m/Y'),
            'unit' => $item->unit,
            'min_stock_alert' => (int) ($item->min_stock_alert ?? 0),
            'per_piece_time' => $item->per_piece_time,
            'average_time' => $item->average_time,
            'purchase_price' => $item->purchase_price,
            'category' => $item->category,
            'status' => $item->status,
            'documents_count' => Document::query()
                ->where('module', 'item')
                ->whereIn('entity_id', array_values(array_filter([
                    (string) $item->id,
                    filled($item->code) ? trim((string) $item->code) : null,
                ])))
                ->count(),
        ];
    }
}
