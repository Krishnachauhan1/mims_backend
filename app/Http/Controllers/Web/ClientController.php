<?php

namespace App\Http\Controllers\Web;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission('clients.view');

        $search = trim((string) $request->query('q', ''));
        $query = Client::query()->latest();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('gst_number', 'like', "%{$search}%");
            });
        }

        $clients = $query->paginate(20)->withQueryString();

        return view('clients.index', compact('clients', 'search'));
    }

    public function create(): View
    {
        $this->authorizePermission('clients.manage');

        return view('clients.form', ['client' => new Client(['status' => 'active'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('clients.manage');
        Client::create($this->validated($request));

        return redirect()
            ->route('web.clients.index')
            ->with('success', 'Client added successfully.');
    }

    public function edit(Client $client): View
    {
        $this->authorizePermission('clients.manage');

        return view('clients.form', compact('client'));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorizePermission('clients.manage');
        $client->update($this->validated($request, true));

        return redirect()
            ->route('web.clients.index')
            ->with('success', 'Client updated successfully.');
    }

    public function toggleStatus(Client $client): RedirectResponse
    {
        $this->authorizePermission('clients.manage');
        $client->update([
            'status' => $client->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Client status updated.');
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
