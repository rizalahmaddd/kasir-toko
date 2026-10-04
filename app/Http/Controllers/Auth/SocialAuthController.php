<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SocialAuthService;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    /**
     * Redirect ke halaman autentikasi Google.
     */
    public function redirectGoogle(SocialAuthService $socialAuth): RedirectResponse
    {
        abort_unless($socialAuth->googleConfigured(), 404);

        return Socialite::driver('google')->redirect();
    }

    /**
     * Callback dari Google.
     */
    public function callbackGoogle(Request $request, SocialAuthService $socialAuth): RedirectResponse
    {
        abort_unless($socialAuth->googleConfigured(), 404);

        if ($request->has('error')) {
            return redirect()->route('login')->with('status', 'Login dengan Google dibatalkan.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            return redirect()->route('login')->withErrors(['login' => 'Gagal menghubungkan akun Google. Silakan coba lagi.']);
        }

        $payload = [
            'sub' => (string) $googleUser->getId(),
            'email' => strtolower(trim((string) $googleUser->getEmail())),
            'name' => $googleUser->getName(),
            'picture' => $googleUser->getAvatar(),
        ];

        $user = $socialAuth->findOrCreateUser('google', $payload);

        app(CurrentTenant::class)->set($user->tenant_id);
        Auth::login($user, remember: true);
        Session::regenerate();

        if ($user->tenant?->needsOnboarding()) {
            return redirect()->route('onboarding');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Hapus akun pengguna untuk Web (App Store Compliance & Privacy).
     */
    public function deleteAccount(Request $request, SocialAuthService $socialAuth): RedirectResponse
    {
        $user = $request->user();
        Auth::logout();
        $socialAuth->deleteAccount($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Akun Anda berhasil dihapus.');
    }
}
