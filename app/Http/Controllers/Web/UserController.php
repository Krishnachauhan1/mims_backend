<?php

namespace App\Http\Controllers\Web;

use App\Models\Machinery;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function index(Request $request): View
    {
        $this->authorizePermission('users.view');

        $search = trim((string) $request->query('q', ''));
        $query = User::query()->with('machinery')->latest();

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(25)->withQueryString();

        return view('users.index', compact('users', 'search'));
    }

    public function create(): View
    {
        $this->authorizePermission('users.create');

        return view('users.form', [
            'user' => new User(['is_active' => true, 'role' => 'operator']),
            'roles' => $this->permissions->roles(),
            'machines' => Machinery::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('users.create');
        $data = $this->validated($request);
        User::create([
            ...$data,
            'password' => Hash::make($data['password']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('web.users.index')->with('success', 'User created.');
    }

    public function edit(User $user): View
    {
        $this->authorizePermission('users.update');

        return view('users.form', [
            'user' => $user,
            'roles' => $this->permissions->roles(),
            'machines' => Machinery::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizePermission('users.update');
        $data = $this->validated($request, $user);
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $user->update([
            ...$data,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('web.users.index')->with('success', 'User updated.');
    }

    public function toggleStatus(User $user): RedirectResponse
    {
        $this->authorizePermission('users.deactivate');
        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', 'User status updated.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $roles = implode(',', $this->permissions->roleKeys());

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.($user?->id ?? 'NULL'),
            'role' => 'required|string|in:'.$roles,
            'department' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'machinery_id' => 'nullable|integer|exists:machinery,id',
        ];

        if ($user) {
            $rules['password'] = 'nullable|string|min:6';
        } else {
            $rules['password'] = 'required|string|min:6';
        }

        return $request->validate($rules);
    }
}
