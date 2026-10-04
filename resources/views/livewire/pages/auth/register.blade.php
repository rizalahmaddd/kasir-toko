<?php

use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\SaasSettings;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $shop_name = '';

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $captchaToken = '';

    public bool $agree_terms = true;

    /**
     * Daftar toko baru beserta akun pemiliknya, lalu langsung masuk ke persiapan toko.
     */
    public function register(TenantProvisioner $provisioner): void
    {
        abort_unless(SaasSettings::registrationOpen(), 403, RegisterRequest::CLOSED_MESSAGE);

        $throttleKey = 'register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages(['shop_name' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey), 'minutes' => ceil(RateLimiter::availableIn($throttleKey) / 60)])]);
        }

        $this->username = strtolower(trim($this->username));
        $this->email = strtolower(trim($this->email));
        $this->phone = filled($this->phone) ? User::normalizePhone($this->phone) : '';

        $rules = [
            ...RegisterRequest::accountRules(),
            'agree_terms' => ['accepted'],
        ];
        $messages = [
            ...User::identityValidationMessages(),
            'agree_terms.accepted' => 'Anda harus menyetujui Ketentuan Layanan dan Kebijakan Privasi.',
        ];

        $validated = $this->validate($rules, $messages, ['shop_name' => 'nama toko', 'agree_terms' => 'persetujuan layanan']);

        // Token Turnstile hanya berlaku sekali, jadi widget diminta membuat token baru setelah dicek.
        $captchaPassed = Turnstile::verify($this->captchaToken, request()->ip());
        $this->captchaToken = '';
        $this->dispatch('turnstile-reset');

        if (! $captchaPassed) {
            throw ValidationException::withMessages(['captchaToken' => 'Selesaikan verifikasi keamanan dulu, lalu coba lagi.']);
        }

        RateLimiter::hit($throttleKey, 3600);

        ['owner' => $owner] = $provisioner->provision(trim($validated['shop_name']), [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'password' => $validated['password'],
        ]);

        Auth::login($owner);
        Session::regenerate();

        $this->redirect(route('onboarding', absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Daftar Toko Baru</h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
            Coba gratis {{ SaasSettings::trialDays() }} hari. Akun ini jadi pemilik toko dan bisa menambah kasir sendiri.
        </p>
    </div>

    @if (! SaasSettings::registrationOpen())
        <div class="rounded-xl border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-300">
            {{ RegisterRequest::CLOSED_MESSAGE }}
        </div>
    @else
        <form wire:submit="register" class="space-y-4">
            <div>
                <x-input-label for="shop_name" value="Nama toko" />
                <x-text-input wire:model="shop_name" id="shop_name" class="block mt-1 w-full" required autofocus placeholder="Contoh: Toko Sumber Rejeki" />
                <x-input-error :messages="$errors->get('shop_name')" class="mt-1.5" />
            </div>

            <div>
                <x-input-label for="name" value="Nama pemilik" />
                <x-text-input wire:model="name" id="name" class="block mt-1 w-full" required autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="username" value="Username" />
                    <x-text-input wire:model="username" id="username" class="block mt-1 w-full" required autocomplete="username" autocapitalize="none" />
                    <x-input-error :messages="$errors->get('username')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="phone" value="Nomor HP (opsional)" />
                    <x-text-input wire:model="phone" id="phone" type="tel" class="block mt-1 w-full" autocomplete="tel" placeholder="081234567890" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                </div>
            </div>

            <div>
                <x-input-label for="email" value="Email" />
                <x-text-input wire:model="email" id="email" type="email" class="block mt-1 w-full" required autocomplete="email" />
                <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="password" value="Password" />
                    <x-text-input wire:model="password" id="password" type="password" class="block mt-1 w-full" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="password_confirmation" value="Ulangi password" />
                    <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password" class="block mt-1 w-full" required autocomplete="new-password" />
                </div>
            </div>

            @if ($captchaSiteKey = Turnstile::siteKey())
                <div>
                    <div
                        wire:ignore
                        x-data="{ widgetId: null }"
                        x-init="
                            const render = () => widgetId = window.turnstile.render($el, {
                                sitekey: @js($captchaSiteKey),
                                language: 'id',
                                callback: (token) => $wire.set('captchaToken', token, false),
                                'expired-callback': () => $wire.set('captchaToken', '', false),
                            });
                            const wait = setInterval(() => { if (window.turnstile) { clearInterval(wait); render(); } }, 100);
                        "
                        x-on:turnstile-reset.window="widgetId !== null && window.turnstile.reset(widgetId)"
                    ></div>
                    <x-input-error :messages="$errors->get('captchaToken')" class="mt-1.5" />
                </div>
            @endif

            <div class="pt-1">
                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input wire:model="agree_terms" id="agree_terms" type="checkbox" required class="mt-0.5 rounded border-slate-300 dark:border-slate-700 text-emerald-600 focus:ring-emerald-500 dark:bg-slate-900" />
                    <span class="text-xs text-slate-600 dark:text-slate-400 select-none">
                        Saya menyetujui
                        <a href="{{ route('legal.terms') }}" target="_blank" class="font-medium text-emerald-600 dark:text-emerald-400 underline hover:text-emerald-700">Ketentuan Layanan</a>
                        &amp;
                        <a href="{{ route('legal.privacy') }}" target="_blank" class="font-medium text-emerald-600 dark:text-emerald-400 underline hover:text-emerald-700">Kebijakan Privasi</a>.
                    </span>
                </label>
                <x-input-error :messages="$errors->get('agree_terms')" class="mt-1.5" />
            </div>

            <div class="pt-2">
                <x-primary-button class="w-full justify-center" wire:loading.attr="disabled">
                    <x-loading-label target="register" loading="Menyiapkan toko...">Daftar &amp; Mulai</x-loading-label>
                </x-primary-button>
            </div>
        </form>
    @endif

        @if (app(\App\Services\SocialAuthService::class)->googleConfigured())
        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200 dark:border-slate-800"></div></div>
            <div class="relative flex justify-center text-xs uppercase"><span class="bg-white dark:bg-slate-900 px-3 text-slate-400 font-medium">atau daftar dengan</span></div>
        </div>

        <a href="{{ route('auth.google') }}" class="w-full min-h-[44px] flex items-center justify-center gap-2.5 py-2.5 px-4 rounded-xl border border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800/80 transition-all shadow-xs">
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24">
                <path fill="#EA4335" d="M12 5c1.6 0 3 .6 4.1 1.7l3.1-3.1C17.3 1.8 14.8 1 12 1 7.5 1 3.7 3.6 1.9 7.4l3.7 2.9C6.5 7.4 9 5 12 5z"/>
                <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5c-.3 1.5-1.1 2.8-2.4 3.7l3.7 2.9c2.2-2 3.7-5 3.7-8.8z"/>
                <path fill="#FBBC05" d="M5.6 14.7c-.2-.7-.4-1.5-.4-2.3 0-.8.2-1.6.4-2.3L1.9 7.2C.7 9.6 0 12.3 0 15.2s.7 5.6 1.9 8l3.7-2.9z"/>
                <path fill="#34A853" d="M12 23.5c3.2 0 6-1.1 8-3l-3.7-2.9c-1.1.7-2.5 1.2-4.3 1.2-3 0-5.5-2-6.4-4.8L1.9 16.9C3.7 20.7 7.5 23.5 12 23.5z"/>
            </svg>
            <span>Daftar dengan Google</span>
        </a>
    @endif

    <p class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
        Sudah punya akun?
        <a href="{{ route('login') }}" wire:navigate class="font-semibold text-emerald-600 dark:text-emerald-400 hover:underline">Masuk</a>
    </p>

    <p class="mt-4 text-center text-[11px] text-slate-400 dark:text-slate-500">
        Dengan mendaftar, Anda menyetujui
        <a href="{{ route('legal.terms') }}" target="_blank" class="underline hover:text-slate-600 dark:hover:text-slate-300">Ketentuan Layanan</a>
        &amp;
        <a href="{{ route('legal.privacy') }}" target="_blank" class="underline hover:text-slate-600 dark:hover:text-slate-300">Kebijakan Privasi</a>.
    </p>

</div>

@assets
    @if (Turnstile::enabled())
        <script src="{{ Turnstile::SCRIPT_URL }}" async defer></script>
    @endif
@endassets
