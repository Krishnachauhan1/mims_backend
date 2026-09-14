<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QualityInspection;
use App\Services\QualityInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QualityInspectionController extends Controller
{
    public function __construct(private QualityInspectionService $qualityService) {}

    public function index(Request $request): JsonResponse
    {
        $query = QualityInspection::query()
            ->with(['productionBatch', 'workOrder', 'rejections'])
            ->latest();

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if ($status === 'passed') {
                $query->whereIn('status', ['passed', 'partial_pass']);
            } else {
                $query->where('status', $status);
            }
        }

        $inspections = $query->get()->map(fn (QualityInspection $inspection) => $this->format($inspection));

        return response()->json($inspections);
    }

    public function show(QualityInspection $qualityInspection): JsonResponse
    {
        $qualityInspection->load(['productionBatch', 'workOrder', 'rejections']);

        return response()->json($this->format($qualityInspection));
    }

    public function rejectItem(Request $request, QualityInspection $qualityInspection): JsonResponse
    {
        $data = $request->validate([
            'item_code' => 'nullable|string|max:255',
            'reason' => 'required|string|max:255',
            'quantity' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:500',
            'rework_assigned_to' => 'required|string|max:255',
            'rework_assigned_to_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $result = $this->qualityService->rejectItem(
            $qualityInspection,
            trim((string) ($data['item_code'] ?? '')),
            max(1, (int) ($data['quantity'] ?? 1)),
            $data['reason'],
            $request->user()?->name,
            $data['notes'] ?? null,
            $data['rework_assigned_to'],
            $data['rework_assigned_to_user_id'] ?? null,
        );

        $inspection = $result['inspection'];
        $rejection = $result['rejection'];

        return response()->json([
            'success' => true,
            'message' => 'Item rejected — sent to Rejection screen for rework',
            'data' => $this->format($inspection),
            'rejection' => [
                'id' => $rejection->id,
                'item_code' => $rejection->item_code,
                'quantity' => $rejection->quantity,
                'reason' => $rejection->reason,
                'rework_assigned_to' => $rejection->rework_assigned_to,
                'rework_assigned_to_user_id' => $rejection->rework_assigned_to_user_id,
                'rework_status' => $rejection->rework_status,
            ],
        ]);
    }

    public function pass(Request $request, QualityInspection $qualityInspection): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $inspection = $this->qualityService->approve(
            $qualityInspection,
            $request->user()?->name,
            $data['notes'] ?? null,
        );

        $remaining = max(0, (int) $inspection->passed_qty);
        $rejected = (int) $inspection->rejected_qty;

        $message = match ($inspection->status) {
            'failed' => 'All items rejected — nothing released to inventory',
            'partial_pass' => "{$remaining} unit(s) released to inventory · {$rejected} rejected",
            default => 'All items passed — batch released to inventory',
        };

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->format($inspection),
        ]);
    }

    public function passItem(Request $request, QualityInspection $qualityInspection): JsonResponse
    {
        $data = $request->validate([
            'quantity' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        $inspection = $this->qualityService->passItem(
            $qualityInspection,
            $quantity,
            $request->user()?->name,
            $data['notes'] ?? null,
        );

        $passed = (int) $inspection->passed_qty;
        $rejected = (int) $inspection->rejected_qty;
        $remaining = $inspection->remainingQty();

        $message = match ($inspection->status) {
            'pending' => "{$quantity} unit(s) passed · {$remaining} still pending QC",
            'partial_pass' => "{$passed} released · {$rejected} rejected",
            'failed' => 'All items rejected — nothing released to inventory',
            default => "{$passed} unit(s) released to inventory",
        };

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->format($inspection),
        ]);
    }

    private function format(QualityInspection $inspection): array
    {
        return [
            'id' => $inspection->id,
            'production_batch_id' => $inspection->production_batch_id,
            'work_order_id' => $inspection->work_order_id,
            'batch_number' => $inspection->batch_number,
            'product_name' => $inspection->product_name,
            'quantity' => $inspection->quantity,
            'passed_qty' => $inspection->passed_qty,
            'rejected_qty' => $inspection->rejected_qty,
            'remaining_qty' => $inspection->remainingQty(),
            'unit' => $inspection->unit,
            'status' => $inspection->status,
            'workflow_name' => $inspection->workflow_name,
            'pr_number' => $inspection->pr_number,
            'source_type' => $inspection->source_type,
            'item_name' => $inspection->item_name,
            'po_number' => $inspection->po_number,
            'mrn_number' => $inspection->mrn_number,
            'inventory_transaction_id' => $inspection->inventory_transaction_id,
            'inspected_by' => $inspection->inspected_by,
            'inspected_at' => $inspection->inspected_at?->format('Y-m-d H:i'),
            'failure_reason' => $inspection->failure_reason,
            'notes' => $inspection->notes,
            'created_at' => $inspection->created_at?->format('Y-m-d H:i'),
            'rejections' => $inspection->relationLoaded('rejections')
                ? $inspection->rejections->map(fn ($rejection) => [
                    'id' => $rejection->id,
                    'item_code' => $rejection->item_code,
                    'quantity' => $rejection->quantity,
                    'reason' => $rejection->reason,
                    'reported_by' => $rejection->reported_by,
                    'created_at' => $rejection->created_at?->format('Y-m-d H:i'),
                ])->values()->all()
                : [],
        ];
    }
}
