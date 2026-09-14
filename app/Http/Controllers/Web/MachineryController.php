<?php

namespace App\Http\Controllers\Web;

use App\Models\Machinery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MachineryController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission('machinery.view');

        $search = trim((string) $request->query('q', ''));
        $query = Machinery::query()->orderBy('sequence')->orderBy('name');

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('name', 'like', "%{$search}%")
                    ->orWhere('machine_code', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        $machines = $query->paginate(25)->withQueryString();

        return view('machinery.index', compact('machines', 'search'));
    }

    public function create(): View
    {
        $this->authorizePermission('machinery.manage');

        return view('machinery.form', [
            'machine' => new Machinery(['status' => 'active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('machinery.manage');
        Machinery::create($this->validated($request));

        return redirect()->route('web.machinery.index')->with('success', 'Machine added.');
    }

    public function edit(Machinery $machinery): View
    {
        $this->authorizePermission('machinery.manage');

        return view('machinery.form', ['machine' => $machinery]);
    }

    public function update(Request $request, Machinery $machinery): RedirectResponse
    {
        $this->authorizePermission('machinery.manage');
        $machinery->update($this->validated($request, $machinery));

        return redirect()->route('web.machinery.index')->with('success', 'Machine updated.');
    }

    public function destroy(Machinery $machinery): RedirectResponse
    {
        $this->authorizePermission('machinery.manage');
        $machinery->delete();

        return redirect()->route('web.machinery.index')->with('success', 'Machine deleted.');
    }

    private function validated(Request $request, ?Machinery $machine = null): array
    {
        $data = $request->validate([
            'sequence' => 'nullable|integer|min:0',
            'machine_code' => 'required|string|max:255|unique:machinery,machine_code,'.($machine?->id ?? 'NULL'),
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'capacity' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,maintenance,inactive',
            'default_stage' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);
        $data['status'] ??= 'active';

        return $data;
    }
}
