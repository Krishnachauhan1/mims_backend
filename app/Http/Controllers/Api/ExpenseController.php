<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->userCanAccess($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $perPage = min(500, max(5, (int) $request->integer('per_page', 50)));
        $page = max(1, (int) $request->integer('page', 1));
        $search = trim((string) $request->string('search', ''));
        $type = trim((string) $request->string('type', ''));
        $dateFrom = trim((string) $request->string('date_from', ''));
        $dateTo = trim((string) $request->string('date_to', ''));
        $month = trim((string) $request->string('month', ''));

        $query = Expense::query()
            ->with(['creator', 'updater'])
            ->latest('expense_date')
            ->latest('id');

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            [$year, $mon] = array_map('intval', explode('-', $month));
            $start = Carbon::create($year, $mon, 1)->startOfDay();
            $end = (clone $start)->endOfMonth()->endOfDay();
            $query->whereDate('expense_date', '>=', $start->toDateString())
                ->whereDate('expense_date', '<=', $end->toDateString());
        } elseif ($dateFrom !== '' && $dateTo !== '') {
            $query->whereDate('expense_date', '>=', $dateFrom)
                ->whereDate('expense_date', '<=', $dateTo);
        }

        if (in_array($type, ['expense', 'income'], true)) {
            $query->where('type', $type);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('category_name', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('payment_method', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Expense $expense) => $this->format($expense))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        if (! $this->userCanAccess($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $month = trim((string) $request->string('month', now()->format('Y-m')));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['message' => 'Invalid month format. Use YYYY-MM'], 422);
        }

        [$year, $mon] = array_map('intval', explode('-', $month));
        $start = Carbon::create($year, $mon, 1)->startOfDay();
        $end = (clone $start)->endOfMonth()->endOfDay();

        $rows = Expense::query()
            ->select([
                'type',
                DB::raw('SUM(amount) as total'),
            ])
            ->whereDate('expense_date', '>=', $start->toDateString())
            ->whereDate('expense_date', '<=', $end->toDateString())
            ->groupBy('type')
            ->pluck('total', 'type');

        $categoryRows = Expense::query()
            ->select([
                'category_id',
                'category_name',
                DB::raw('SUM(amount) as total'),
            ])
            ->where('type', 'expense')
            ->whereDate('expense_date', '>=', $start->toDateString())
            ->whereDate('expense_date', '<=', $end->toDateString())
            ->groupBy('category_id', 'category_name')
            ->orderByDesc('total')
            ->get();

        $income = (float) ($rows['income'] ?? 0);
        $expense = (float) ($rows['expense'] ?? 0);

        return response()->json([
            'month' => $month,
            'income' => $income,
            'expense' => $expense,
            'balance' => $income - $expense,
            'categories' => $categoryRows->map(fn ($row) => [
                'category_id' => $row->category_id,
                'category_name' => $row->category_name,
                'total' => (float) $row->total,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->userCanManage($request)) {
            return response()->json(['message' => 'Forbidden — expense manage permission required'], 403);
        }

        $user = $request->user();
        $data = $this->validatedPayload($request);
        $data['created_by'] = $user?->id;
        $data['updated_by'] = $user?->id;

        $expense = Expense::create($data);

        return response()->json($this->format($expense->load(['creator', 'updater'])), 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        if (! $this->userCanManage($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $this->validatedPayload($request);
        $data['updated_by'] = $request->user()?->id;

        $expense->update($data);

        return response()->json($this->format($expense->fresh(['creator', 'updater'])));
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        if (! $this->userCanManage($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $expense->delete();

        return response()->json(['message' => 'Expense entry deleted']);
    }

    private function userCanAccess(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCanAny($user, [
            'expenses.view',
            'expenses.manage',
        ]) || in_array($user->role, ['admin', 'production_manager', 'store_manager'], true);
    }

    private function userCanManage(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $this->permissions->userCanAny($user, [
            'expenses.manage',
        ]) || in_array($user->role, ['admin', 'production_manager', 'store_manager'], true);
    }

    private function validatedPayload(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|string|in:expense,income',
            'category_id' => 'required|string|max:50',
            'category_name' => 'nullable|string|max:255',
            'expense_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data['expense_date'] = $data['expense_date'] ?? now()->toDateString();
        $data['payment_method'] = $data['payment_method'] ?? 'cash';

        if (empty($data['category_name'])) {
            $data['category_name'] = ucwords(str_replace('_', ' ', $data['category_id']));
        }

        return $data;
    }

    private function format(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'title' => $expense->title,
            'amount' => $expense->amount,
            'type' => $expense->type,
            'category_id' => $expense->category_id,
            'category_name' => $expense->category_name,
            'expense_date' => $expense->expense_date?->toDateString(),
            'payment_method' => $expense->payment_method,
            'notes' => $expense->notes,
            'created_by' => $expense->created_by,
            'created_by_name' => $expense->creator?->name,
            'updated_by' => $expense->updated_by,
            'updated_by_name' => $expense->updater?->name,
            'created_at' => $expense->created_at?->toIso8601String(),
            'updated_at' => $expense->updated_at?->toIso8601String(),
        ];
    }
}
