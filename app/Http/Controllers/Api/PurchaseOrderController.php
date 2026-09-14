<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Services\PermissionService;
use App\Services\PoCheckinItemService;
use App\Services\PurchaseOrderCheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private PurchaseOrderCheckinService $purchaseOrderCheckin,
        private PoCheckinItemService $poCheckinItems,
        private PermissionService $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()->latest();

        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('po_number', 'like', $term)
                    ->orWhere('pr_number', 'like', $term)
                    ->orWhere('vendor_name', 'like', $term);
            });
        }

        if (! $request->has('page') && ! $request->has('per_page')) {
            return response()->json(
                $query->get()->map(fn (PurchaseOrder $po) => $this->format($po)),
            );
        }

        $perPage = min(100, max(5, (int) $request->integer('per_page', 20)));
        $page = max(1, (int) $request->integer('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (PurchaseOrder $po) => $this->format($po))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'status_counts' => $this->poStatusCounts(),
            ],
        ]);
    }

    public function approvedPrs(): JsonResponse
    {
        $prs = PurchaseRequisition::query()
            ->approvedWithoutPo()
            ->latest()
            ->get()
            ->map(fn (PurchaseRequisition $pr) => $this->formatRequisition($pr))
            ->values();

        return response()->json($prs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);

        if (! empty($data['pr_number']) || ! empty($data['pr_id'])) {
            $activePoExists = PurchaseOrder::query()
                ->whereNotIn('status', ['rejected'])
                ->where(function ($q) use ($data) {
                    if (! empty($data['pr_id'])) {
                        $q->where('pr_id', $data['pr_id']);
                    }
                    if (! empty($data['pr_number'])) {
                        $q->{! empty($data['pr_id']) ? 'orWhere' : 'where'}(
                            function ($sub) use ($data) {
                                $sub->where('pr_number', $data['pr_number'])
                                    ->whereNull('pr_id');
                            },
                        );
                    }
                })
                ->exists();

            if ($activePoExists) {
                return response()->json([
                    'message' => 'Purchase order already exists for this PR',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $prQuery = PurchaseRequisition::query();
            if (! empty($data['pr_number'])) {
                $prQuery->where('pr_number', $data['pr_number']);
            } elseif (! empty($data['pr_id'])) {
                $prQuery->where('id', $data['pr_id']);
            }
            $pr = $prQuery->first();

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

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if (! $this->userCanManagePo($request)) {
            return response()->json([
                'message' => 'You do not have permission to delete purchase orders',
            ], Response::HTTP_FORBIDDEN);
        }

        $checkins = InventoryTransaction::query()
            ->where('type', 'checkin')
            ->where('po_number', $purchaseOrder->po_number)
            ->where(function ($query): void {
                $query->whereNull('flow_source')
                    ->orWhere('flow_source', 'purchase_order');
            })
            ->get();

        foreach ($checkins as $checkin) {
            $items = is_array($checkin->items) ? $checkin->items : [];
            $received = $this->poCheckinItems->totalReceivedQuantity($items);
            $phase = (string) ($checkin->receipt_phase ?? $checkin->status ?? 'order_placed');

            if ($received > 0 || in_array($phase, ['partial', 'checked_in', 'order_received'], true)) {
                return response()->json([
                    'message' => 'Cannot delete PO — materials have already been received in Check-In',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $checkin->delete();
        }

        $this->deleteLinkedRequisition($purchaseOrder);

        $purchaseOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Purchase order deleted',
        ]);
    }

    private function deleteLinkedRequisition(PurchaseOrder $purchaseOrder): void
    {
        $pr = null;

        if ($purchaseOrder->pr_id) {
            $pr = PurchaseRequisition::query()->find($purchaseOrder->pr_id);
        } elseif ($purchaseOrder->pr_number) {
            $pr = PurchaseRequisition::query()
                ->where('pr_number', $purchaseOrder->pr_number)
                ->first();
        }

        if ($pr === null) {
            return;
        }

        $otherActivePo = PurchaseOrder::query()
            ->where('id', '!=', $purchaseOrder->id)
            ->whereNotIn('status', ['rejected'])
            ->where(function ($query) use ($pr) {
                $query->where('pr_id', $pr->id)
                    ->orWhere(function ($sub) use ($pr) {
                        $sub->where('pr_number', $pr->pr_number)
                            ->whereNull('pr_id');
                    });
            })
            ->exists();

        if ($otherActivePo) {
            return;
        }

        $pr->delete();
    }

    private function userCanManagePo(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        if (strcasecmp((string) $user->role, 'admin') === 0) {
            return true;
        }

        $permissions = $this->permissions->permissionsForRole($user->role);

        return in_array('*', $permissions, true)
            || in_array('purchase_order.approve', $permissions, true)
            || in_array('purchase_order.manage', $permissions, true);
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

    private function poStatusCounts(): array
    {
        $rows = PurchaseOrder::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) ($rows['pending'] ?? 0),
            'approved' => (int) ($rows['approved'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
            'all' => PurchaseOrder::query()->count(),
        ];
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
