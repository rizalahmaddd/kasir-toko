<?php

use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\User;
use App\Services\TenantProvisioner;
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

    /**
     * Daftar toko baru beserta akun pemiliknya, lalu langsung masuk.
     */
    public function register(TenantProvisioner $provisioner): void
    {
        $throttleKey = 'register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages(['shop_name' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey), 'minutes' => ceil(RateLimiter::availableIn($throttleKey) / 60)])]);
        }

        $this->username = strtolower(trim($this->username));
        $this->email = strtolower(trim($this->email));
        $this->phone = filled($this->phone) ? User::normalizePhone($this->phone) : '';

        $validated = $this->validate(RegisterRequest::accountRules(), User::identityValidationMessages(), ['shop_name' => 'nama toko']);
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

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Daftar Toko Baru</h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
            Coba gratis {{ config('saas.trial_days') }} hari. Akun ini jadi pemilik toko dan bisa menambah kasir sendiri.
        </p>
    </div>

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

        <div class="pt-2">
            <x-primary-button class="w-full justify-center" wire:loading.attr="disabled">
                <x-loading-label target="register" loading="Menyiapkan toko...">Daftar &amp; Mulai</x-loading-label>
            </x-primary-button>
        </div>
    </form>

    <p class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
        Sudah punya akun?
        <a href="{{ route('login') }}" wire:navigate class="font-semibold text-emerald-600 dark:text-emerald-400 hover:underline">Masuk</a>
    </p>
</div>
