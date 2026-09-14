<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rejection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RejectionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Rejection::query()->latest()->get()->map(fn (Rejection $rejection) => $this->format($rejection)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->normalizedPayload($this->validatedPayload($request));
        $rejection = Rejection::create($payload);

        if (filled($rejection->rework_assigned_to)) {
            \App\Services\AppNotificationService::reworkAssigned(
                $rejection,
                $request->user()?->name,
            );
        }

        return response()->json($this->format($rejection), 201);
    }

    public function update(Request $request, Rejection $rejection): JsonResponse
    {
        $rejection->update($this->normalizedPayload($this->validatedPayload($request, isUpdate: true)));

        return response()->json($this->format($rejection->fresh()));
    }

    public function updateRework(Request $request, Rejection $rejection): JsonResponse
    {
        $data = $request->validate([
            'rework_status' => 'nullable|string|in:pending,in_rework,completed,scrapped',
            'rework_assigned_to' => 'nullable|string|max:255',
            'rework_assigned_to_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $nextStatus = $data['rework_status'] ?? null;
        if ($nextStatus === 'in_rework' && $rejection->rework_possible === false) {
            throw new HttpException(422, 'This item is not eligible for rework');
        }

        $updates = [];
        if ($nextStatus !== null) {
            $updates['rework_status'] = $nextStatus;
        }

        if (array_key_exists('rework_assigned_to', $data)) {
            $updates['rework_assigned_to'] = filled($data['rework_assigned_to'])
                ? trim($data['rework_assigned_to'])
                : null;
        }

        if (array_key_exists('rework_assigned_to_user_id', $data)) {
            $updates['rework_assigned_to_user_id'] = $data['rework_assigned_to_user_id'];
        }

        if (
            filled($updates['rework_assigned_to'] ?? $rejection->rework_assigned_to)
            && $nextStatus === null
            && ($rejection->rework_status === 'pending' || ! isset($updates['rework_status']))
        ) {
            $updates['rework_status'] = 'in_rework';
        }

        $rejection->update($updates);
        $rejection = $rejection->fresh();

        if (
            (array_key_exists('rework_assigned_to', $data) || array_key_exists('rework_assigned_to_user_id', $data))
            && filled($rejection->rework_assigned_to)
        ) {
            \App\Services\AppNotificationService::reworkAssigned(
                $rejection,
                $request->user()?->name,
            );
        }

        return response()->json($this->format($rejection));
    }

    private function validatedPayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'product_name' => $isUpdate ? 'sometimes|string|max:255' : 'required|string|max:255',
            'product' => 'nullable|string|max:255',
            'batch_number' => 'nullable|string|max:255',
            'production_batch_id' => 'nullable|integer|exists:production_batches,id',
            'work_order_id' => 'nullable|integer|exists:work_orders,id',
            'item_code' => 'nullable|string|max:255',
            'stage' => 'nullable|string|max:255',
            'quantity' => 'required|integer|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'scrap_cost' => 'nullable|numeric|min:0',
            'rejection_date' => 'nullable|date',
            'date' => 'nullable|date',
            'user_id' => 'nullable|integer',
            'reason' => 'required|string|max:255',
            'severity' => 'nullable|string|max:100',
            'rework_status' => 'nullable|string|in:pending,in_rework,completed,scrapped',
            'rework_possible' => 'nullable|boolean',
            'reported_by' => 'nullable|string|max:255',
            'rework_assigned_to' => 'nullable|string|max:255',
            'rework_assigned_to_user_id' => 'nullable|integer|exists:users,id',
            'total_produced' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);
    }

    private function format(Rejection $rejection): array
    {
        return [
            'id' => $rejection->id,
            'production_batch_id' => $rejection->production_batch_id,
            'work_order_id' => $rejection->work_order_id,
            'product_name' => $rejection->product_name,
            'product' => $rejection->product_name,
            'item_code' => $rejection->item_code,
            'batch_number' => $rejection->batch_number,
            'stage' => $rejection->stage,
            'quantity' => $rejection->quantity,
            'unit_cost' => $rejection->unit_cost,
            'scrap_cost' => $rejection->scrap_cost,
            'reason' => $rejection->reason,
            'severity' => $rejection->severity,
            'rework_status' => $rejection->rework_status,
            'rework_possible' => $rejection->rework_possible,
            'reported_by' => $rejection->reported_by,
            'rework_assigned_to' => $rejection->rework_assigned_to,
            'rework_assigned_to_user_id' => $rejection->rework_assigned_to_user_id,
            'total_produced' => $rejection->total_produced,
            'notes' => $rejection->notes,
            'date' => $rejection->rejection_date?->format('Y-m-d'),
            'rejection_date' => $rejection->rejection_date?->format('Y-m-d'),
            'created_at' => $rejection->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function normalizedPayload(array $validated): array
    {
        if (! array_key_exists('product_name', $validated) && array_key_exists('product', $validated)) {
            $validated['product_name'] = $validated['product'];
        }

        unset($validated['product']);

        if (! array_key_exists('rejection_date', $validated) && array_key_exists('date', $validated)) {
            $validated['rejection_date'] = $validated['date'];
        }

        unset($validated['date']);

        if (
            filled($validated['rework_assigned_to'] ?? null)
            && empty($validated['rework_status'])
        ) {
            $validated['rework_status'] = 'in_rework';
        }

        return $validated;
    }
}
