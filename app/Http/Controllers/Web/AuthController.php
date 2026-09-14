<?php

namespace App\Http\Controllers\Web;

use App\Models\User;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('web.dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Invalid email or password.']);
        }

        if (! $user->is_active) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'This account has been deactivated.']);
        }

        Auth::login($user, $request->boolean('remember'));
        $user->update(['last_login_at' => now()]);

        AuditService::log(
            action: 'User login',
            module: 'Auth',
            description: 'Signed in via web',
            type: 'login',
        );

        $request->session()->regenerate();

        return redirect()->intended(route('web.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($request->user()) {
            AuditService::log(
                action: 'User logout',
                module: 'Auth',
                description: 'Signed out via web',
                type: 'login',
            );
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('web.login');
    }
}
