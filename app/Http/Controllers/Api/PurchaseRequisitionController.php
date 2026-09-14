<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\PurchaseRequisition;
use App\Services\AppNotificationService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseRequisitionController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseRequisition::query()->latest();

        if ($request->boolean('for_po')) {
            $items = $query->approvedWithoutPo()->get()->map(
                fn (PurchaseRequisition $pr) => $this->format($pr),
            )->values();

            return response()->json($items);
        }

        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('pr_number', 'like', $term)
                    ->orWhere('vendor_name', 'like', $term)
                    ->orWhere('contact_person', 'like', $term)
                    ->orWhere('contact_mobile', 'like', $term)
                    ->orWhere('item_name', 'like', $term);
            });
        }

        if (! $request->has('page') && ! $request->has('per_page')) {
            return response()->json(
                $query->get()->map(
                    fn (PurchaseRequisition $pr) => $this->format($pr),
                )->values(),
            );
        }

        $perPage = min(100, max(5, (int) $request->integer('per_page', 20)));
        $page = max(1, (int) $request->integer('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (PurchaseRequisition $pr) => $this->format($pr))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'status_counts' => $this->prStatusCounts(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $requested = $payload['pr_number'] ?? null;

        if (
            blank($requested) ||
            PurchaseRequisition::query()->where('pr_number', $requested)->exists()
        ) {
            $payload['pr_number'] = $this->nextPrNumber();
        }

        $pr = PurchaseRequisition::create($payload);

        if ($pr->status === 'submitted') {
            AppNotificationService::prSubmitted($pr);
        }

        return response()->json($this->format($pr), 201);
    }

    public function update(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        if (! $this->userCanEditPr($request, $purchaseRequisition)) {
            return response()->json([
                'message' => 'This requisition cannot be edited in its current status',
            ], Response::HTTP_FORBIDDEN);
        }

        $payload = $this->validatedPayload($request, $purchaseRequisition);

        if ($purchaseRequisition->status === 'revision') {
            $payload['status'] = 'submitted';
            $payload['review_notes'] = null;
            $payload['submitted_by_user_id'] = $request->user()?->id;
        }

        $purchaseRequisition->update($payload);

        if ($purchaseRequisition->wasChanged('status') && $purchaseRequisition->status === 'submitted') {
            AppNotificationService::prSubmitted($purchaseRequisition);
        }

        return response()->json($this->format($purchaseRequisition->fresh()));
    }

    public function updateStatus(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:submitted',
        ]);

        if (! in_array($purchaseRequisition->status, ['draft', 'revision'], true)) {
            return response()->json([
                'message' => 'Only draft or revision PRs can be submitted',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $purchaseRequisition->update([
            'status' => $data['status'],
            'review_notes' => null,
            'submitted_by_user_id' => $request->user()?->id,
        ]);

        AppNotificationService::prSubmitted($purchaseRequisition);

        return response()->json($this->format($purchaseRequisition->fresh()));
    }

    public function review(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        if (! $this->userCanApprovePr($request)) {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        if ($purchaseRequisition->status !== 'submitted') {
            return response()->json([
                'message' => 'Only submitted PRs can be reviewed',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'action' => 'required|string|in:approve,reject,revision',
            'notes' => 'nullable|string|max:2000',
        ]);

        $statusMap = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'revision' => 'revision',
        ];

        $purchaseRequisition->update([
            'status' => $statusMap[$data['action']],
            'review_notes' => $data['notes'] ?? null,
        ]);

        AppNotificationService::prReviewed($purchaseRequisition, $data['action'], $data['notes'] ?? null);

        return response()->json($this->format($purchaseRequisition->fresh()));
    }

    public function destroy(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        if (! $this->userCanApprovePr($request) && $purchaseRequisition->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft PRs can be deleted',
            ], Response::HTTP_FORBIDDEN);
        }

        $purchaseRequisition->delete();

        return response()->json(['message' => 'Purchase requisition deleted']);
    }

    private function validatedPayload(Request $request, ?PurchaseRequisition $pr = null): array
    {
        $allowedStatuses = $pr === null
            ? 'draft,submitted'
            : ($this->userCanApprovePr($request)
                ? 'draft,submitted,approved,rejected,revision'
                : 'draft,submitted');

        $prNumberRule = $pr === null
            ? 'nullable|string|max:255'
            : 'required|string|max:255|unique:purchase_requisitions,pr_number,'.$pr->id;

        $data = $request->validate([
            'pr_number' => $prNumberRule,
            'vendor_name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_mobile' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:'.$allowedStatuses,
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.grade' => 'nullable|string|max:50',
            'items.*.diameter_mm' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.purchase_price' => 'nullable|numeric|min:0',
            'created_at' => 'nullable|date',
        ]);

        $normalizedItems = $this->normalizeItems($data['items']);
        $firstItem = $normalizedItems[0];
        $status = $data['status'] ?? ($pr?->status ?? 'draft');

        $payload = [
            'pr_number' => $data['pr_number'] ?? $pr?->pr_number ?? $this->nextPrNumber(),
            'vendor_name' => $data['vendor_name'],
            'contact_person' => $data['contact_person'] ?? null,
            'contact_mobile' => $data['contact_mobile'] ?? null,
            'items' => $normalizedItems,
            'item_name' => $firstItem['item_name'],
            'quantity' => $firstItem['quantity'],
            'unit' => $firstItem['unit'] ?? 'kg',
            'status' => $status,
            'created_at' => $data['created_at'] ?? $pr?->created_at ?? now(),
            'updated_at' => now(),
        ];

        if ($status === 'submitted' && ($pr === null || $pr->status !== 'submitted')) {
            $payload['submitted_by_user_id'] = $request->user()?->id;
            $payload['review_notes'] = null;
        }

        return $payload;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function normalizeItems(array $items): array
    {
        return array_map(function (array $item): array {
            $itemId = $item['item_id'] ?? null;
            if (! is_numeric($itemId)) {
                return $item;
            }

            $catalog = Item::query()->find((int) $itemId);
            if ($catalog === null) {
                return $item;
            }

            $item['item_id'] = $catalog->id;
            $item['item_name'] = $catalog->name;
            $item['unit'] = $item['unit'] ?? $catalog->unit ?? 'kg';

            if (filled($catalog->grade)) {
                $item['grade'] = $catalog->grade;
                $item['diameter_mm'] = $catalog->diameter_mm;
            } else {
                unset($item['grade'], $item['diameter_mm']);
            }

            return $item;
        }, $items);
    }

    private function prStatusCounts(): array
    {
        $rows = PurchaseRequisition::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'draft' => (int) ($rows['draft'] ?? 0),
            'submitted' => (int) ($rows['submitted'] ?? 0),
            'approved' => (int) ($rows['approved'] ?? 0),
            'revision' => (int) ($rows['revision'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
            'all' => PurchaseRequisition::query()->count(),
        ];
    }

    private function format(PurchaseRequisition $pr): array
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

    private function userCanApprovePr(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        $permissions = $this->permissions->permissionsForRole($user->role);

        return in_array('*', $permissions, true)
            || in_array('purchase_requisition.approve', $permissions, true);
    }

    private function userCanEditPr(Request $request, PurchaseRequisition $pr): bool
    {
        if ($this->userCanApprovePr($request)) {
            return true;
        }

        return in_array($pr->status, ['draft', 'revision'], true);
    }

    private function nextPrNumber(): string
    {
        $year = now()->year;
        $prefix = "PR-{$year}-";

        $maxSeq = PurchaseRequisition::query()
            ->where('pr_number', 'like', $prefix.'%')
            ->pluck('pr_number')
            ->map(function (string $prNumber) use ($year) {
                if (preg_match('/^PR-'.$year.'-(\d+)$/', $prNumber, $matches) === 1) {
                    return (int) $matches[1];
                }

                return 0;
            })
            ->max() ?? 0;

        return $prefix.str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT);
    }
}
