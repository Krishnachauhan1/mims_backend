<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinishedGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinishedGoodController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            FinishedGood::query()
                ->latest()
                ->get()
                ->map(fn (FinishedGood $product) => $this->format($product)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        $payload = $this->normalizedPayload($validated);

        $product = FinishedGood::query()->updateOrCreate(
            [
                'batch_number' => $payload['batch_number'] ?? null,
            ],
            $payload,
        );

        return response()->json($this->format($product), 201);
    }

    public function update(Request $request, FinishedGood $finishedGood): JsonResponse
    {
        $validated = $this->validatedPayload($request, isUpdate: true);
        $finishedGood->update($this->normalizedPayload($validated));

        return response()->json($this->format($finishedGood->fresh()));
    }

    private function validatedPayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'product' => $isUpdate ? 'sometimes|string|max:255' : 'required|string|max:255',
            'sku' => 'nullable|string|max:255',
            'batch_number' => 'nullable|string|max:255',
            'quantity' => 'nullable|integer|min:0',
            'stock' => 'nullable|integer|min:0',
            'dispatched' => 'nullable|integer|min:0',
            'status' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
            'warehouse' => 'nullable|string|max:255',
            'quality_status' => 'nullable|string|max:100',
            'production_date' => 'nullable|date',
            'client_id' => 'nullable|integer',
            'source_batch_id' => 'nullable|integer',
            'flow_step' => 'nullable|string|max:100',
            'pr_number' => 'nullable|string|max:255',
            'workflow' => 'nullable|string|max:255',
            'rack_id' => 'nullable|string|max:255',
        ]);
    }

    private function format(FinishedGood $product): array
    {
        return [
            'id' => $product->id,
            'product' => $product->product_name,
            'product_name' => $product->product_name,
            'sku' => $product->sku,
            'batch_number' => $product->batch_number,
            'quantity' => $product->quantity,
            'stock' => $product->stock,
            'dispatched' => $product->dispatched,
            'status' => $product->status,
            'unit' => $product->unit,
            'location' => $product->location,
            'warehouse' => $product->warehouse,
            'quality_status' => $product->quality_status,
            'production_date' => $product->production_date?->format('Y-m-d'),
            'client_id' => $product->client_id,
            'source_batch_id' => $product->source_batch_id,
            'flow_step' => $product->flow_step,
            'pr_number' => $product->pr_number,
            'workflow' => $product->workflow,
            'rack_id' => $product->rack_id,
        ];
    }

    private function normalizedPayload(array $validated): array
    {
        if (array_key_exists('product', $validated)) {
            $validated['product_name'] = $validated['product'];
            unset($validated['product']);
        }

        if (! array_key_exists('stock', $validated) && array_key_exists('quantity', $validated)) {
            $validated['stock'] = $validated['quantity'];
        }

        return $validated;
    }
}
