<?php

namespace App\Http\Controllers\Web;

use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission('vendors.view');

        $search = trim((string) $request->query('q', ''));
        $query = Vendor::query()->latest();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('gst_number', 'like', "%{$search}%");
            });
        }

        $vendors = $query->paginate(20)->withQueryString();

        return view('vendors.index', compact('vendors', 'search'));
    }

    public function create(): View
    {
        $this->authorizePermission('vendors.manage');

        return view('vendors.form', ['vendor' => new Vendor(['status' => 'active'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('vendors.manage');
        $data = $this->validated($request);
        Vendor::create($data);

        return redirect()
            ->route('web.vendors.index')
            ->with('success', 'Vendor added successfully.');
    }

    public function edit(Vendor $vendor): View
    {
        $this->authorizePermission('vendors.manage');

        return view('vendors.form', compact('vendor'));
    }

    public function update(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorizePermission('vendors.manage');
        $vendor->update($this->validated($request, true));

        return redirect()
            ->route('web.vendors.index')
            ->with('success', 'Vendor updated successfully.');
    }

    public function toggleStatus(Vendor $vendor): RedirectResponse
    {
        $this->authorizePermission('vendors.manage');
        $vendor->update([
            'status' => $vendor->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Vendor status updated.');
    }

    private function validated(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'gst_number' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
        ];

        $data = $request->validate($rules);
        $data['status'] ??= 'active';

        return $data;
    }
}
