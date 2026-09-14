<?php

namespace App\Http\Controllers\Web;

use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission('items.view');

        $search = trim((string) $request->query('q', ''));
        $query = Item::query()->latest();

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $items = $query->paginate(25)->withQueryString();

        return view('items.index', compact('items', 'search'));
    }

    public function create(): View
    {
        $this->authorizePermission('items.manage');

        return view('items.form', ['item' => new Item(['status' => 'active', 'unit' => 'pcs'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('items.manage');
        Item::create($this->validated($request));

        return redirect()->route('web.items.index')->with('success', 'Item added.');
    }

    public function edit(Item $item): View
    {
        $this->authorizePermission('items.manage');

        return view('items.form', compact('item'));
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $this->authorizePermission('items.manage');
        $item->update($this->validated($request, $item));

        return redirect()->route('web.items.index')->with('success', 'Item updated.');
    }

    public function toggleStatus(Item $item): RedirectResponse
    {
        $this->authorizePermission('items.manage');
        $item->update([
            'status' => $item->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Item status updated.');
    }

    private function validated(Request $request, ?Item $item = null): array
    {
        return $request->validate([
            'code' => 'required|string|max:100|unique:items,code,'.($item?->id ?? 'NULL'),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'grade' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'purchase_price' => 'nullable|numeric|min:0',
            'min_stock_alert' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:active,inactive',
        ]);
    }
}
