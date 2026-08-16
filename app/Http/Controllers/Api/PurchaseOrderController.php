<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Services\PurchaseOrderCheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderCheckinService $purchaseOrderCheckin) {}

    public function index(): JsonResponse
    {
        $orders = PurchaseOrder::latest()->get()->map(
            fn (PurchaseOrder $po) => $this->format($po),
        );

        return response()->json($orders);
    }

    public function approvedPrs(): JsonResponse
    {
        $prs = PurchaseRequisition::query()
            ->approvedWithoutPo()
            ->latest()
            ->get()
            ->map(fn (PurchaseRequisition $pr) => $this->formatRequisition($pr));

        return response()->json($prs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);

        if (! empty($data['pr_number'])) {
            if (PurchaseOrder::query()
                ->where('pr_number', $data['pr_number'])
                ->exists()) {
                return response()->json([
                    'message' => 'Purchase order already exists for this PR',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $pr = PurchaseRequisition::query()
                ->where('pr_number', $data['pr_number'])
                ->first();

            if ($pr === null) {
                return response()->json([
                    'message' => 'Purchase requisition not found',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($pr->status !== 'approved') {
                return response()->json([
                    'message' => 'Only approved purchase requisitions can be converted to PO',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $order = PurchaseOrder::create($data);

        return response()->json($this->format($order), 201);
    }

    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:pending,approved,rejected',
        ]);

        $purchaseOrder->update(['status' => $data['status']]);

        if ($data['status'] === 'approved') {
            $this->purchaseOrderCheckin->queueForCheckin($purchaseOrder->fresh());
        }

        return response()->json($this->format($purchaseOrder->fresh()));
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'po_number' => 'required|string|max:255|unique:purchase_orders,po_number',
            'pr_number' => 'nullable|string|max:255',
            'pr_id' => 'nullable|integer',
            'vendor_name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_mobile' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:pending,approved,rejected',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.grade' => 'nullable|string|max:50',
            'items.*.diameter_mm' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.purchase_price' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'created_at' => 'nullable|date',
        ]);
    }

    private function format(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'pr_number' => $po->pr_number,
            'pr_id' => $po->pr_id,
            'vendor_name' => $po->vendor_name,
            'contact_person' => $po->contact_person,
            'contact_mobile' => $po->contact_mobile,
            'status' => $po->status,
            'items' => $po->items ?? [],
            'total_amount' => $po->total_amount,
            'created_at' => $po->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
        ];
    }

    private function formatRequisition(PurchaseRequisition $pr): array
    {
        return [
            'id' => $pr->id,
            'pr_number' => $pr->pr_number,
            'vendor_name' => $pr->vendor_name,
            'contact_person' => $pr->contact_person,
            'contact_mobile' => $pr->contact_mobile,
            'status' => $pr->status,
            'review_notes' => $pr->review_notes,
            'submitted_by_user_id' => $pr->submitted_by_user_id,
            'items' => $pr->items ?? [],
            'created_at' => $pr->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
        ];
    }
}
