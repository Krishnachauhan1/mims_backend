<?php

namespace App\Http\Controllers\Web;

use App\Models\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        $this->authorizePermission('settings.view');
        $profile = CompanyProfile::query()->first();

        return view('settings.index', compact('profile'));
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePermission('settings.view');

        $data = $request->validate([
            'company_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'gst_number' => 'nullable|string|max:20',
        ]);

        $profile = CompanyProfile::query()->firstOrNew([]);
        $profile->fill($data);
        $profile->save();

        return back()->with('success', 'Company profile saved.');
    }
}
