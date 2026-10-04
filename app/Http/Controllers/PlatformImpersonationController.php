<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlatformImpersonationController extends Controller
{
    /**
     * Mengakhiri sesi impersonasi toko dan mengembalikan sesi ke akun admin platform.
     */
    public function leave(Request $request): RedirectResponse
    {
        $adminId = session('impersonator_id');
        abort_unless($adminId, 403, 'Tidak sedang dalam sesi impersonasi.');

        $admin = User::withoutGlobalScopes()->whereNull('tenant_id')->findOrFail($adminId);
        abort_unless($admin->can('manage-platform'), 403);

        session()->forget('impersonator_id');

        activity('platform')
            ->performedOn($admin)
            ->event('impersonate_stop')
            ->log("Admin platform {$admin->name} keluar dari sesi impersonasi toko.");

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('platform.tenants');
    }
}
