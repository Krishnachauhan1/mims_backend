<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Item::query()->latest();

        if ($request->filled('grade')) {
            $query->where('grade', $request->string('grade'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        return response()->json(
            $query->get()->map(fn (Item $item) => $this->format($item)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $item = Item::create($this->validatedPayload($request));

        return response()->json($this->format($item), 201);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        $item->update($this->validatedPayload($request, $item));

        return response()->json($this->format($item->fresh()));
    }

    public function destroy(Item $item): JsonResponse
    {
        $item->delete();

        return response()->json(['message' => 'Item deleted']);
    }

    private function validatedPayload(Request $request, ?Item $item = null): array
    {
        return $request->validate([
            'code' => 'required|string|max:255|unique:items,code,'.($item?->id ?? 'NULL'),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'grade' => 'nullable|string|max:50',
            'diameter_mm' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'min_stock_alert' => 'nullable|integer|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'category' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:100',
        ]);
    }

    private function format(Item $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'description' => $item->description,
            'grade' => $item->grade,
            'diameter_mm' => $item->diameter_mm,
            'unit' => $item->unit,
            'min_stock_alert' => (int) ($item->min_stock_alert ?? 0),
            'purchase_price' => $item->purchase_price,
            'category' => $item->category,
            'status' => $item->status,
            'documents_count' => Document::query()
                ->where('module', 'item')
                ->where('entity_id', (string) $item->id)
                ->count(),
        ];
    }
}
