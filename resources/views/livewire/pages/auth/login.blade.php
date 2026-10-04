<?php

use App\Livewire\Forms\LoginForm;
use App\Services\WhatsAppOtpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Mode login: 'password' atau 'whatsapp'.
     */
    public string $loginMode = 'password';

    /**
     * Status formulir WhatsApp OTP.
     */
    public string $waIdentifier = '';

    public string $otp = '';

    public int $otpStep = 1; // 1: input identitas, 2: input 6-digit OTP

    #[Locked]
    public ?string $otpChallenge = null;

    public string $maskedPhone = '';

    public int $cooldownSeconds = 0;

    public bool $rememberOtp = false;

    public bool $otpEnabled = false;

    public function mount(): void
    {
        $this->otpEnabled = WhatsAppOtpService::isConfigured();
        if (! $this->otpEnabled) {
            $this->loginMode = 'password';
        }
    }

    /**
     * Ganti mode login (Password vs WhatsApp OTP).
     */
    public function setLoginMode(string $mode): void
    {
        if (! $this->otpEnabled && $mode === 'whatsapp') {
            return;
        }

        $this->loginMode = in_array($mode, ['password', 'whatsapp']) ? $mode : 'password';
        $this->resetErrorBag();
    }

    /**
     * Login konvensional dengan username/email/HP + password.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Kirim kode OTP ke nomor WhatsApp pengguna.
     */
    public function sendOtp(WhatsAppOtpService $otpService): void
    {
        $this->validate([
            'waIdentifier' => ['required', 'string', 'max:255'],
        ], [
            'waIdentifier.required' => 'Masukkan nomor WhatsApp, username, atau email Anda.',
        ]);

        $this->throttleOtp('send', 'waIdentifier', 5, 900);
        $result = $otpService->sendOtp($this->waIdentifier);

        $this->otpChallenge = $result['challenge'];
        $this->maskedPhone = $result['masked_phone'];
        $this->cooldownSeconds = $result['cooldown_seconds'];
        $this->otpStep = 2;
        $this->otp = '';
        $this->resetErrorBag();
    }

    /**
     * Kirim ulang kode OTP WhatsApp.
     */
    public function resendOtp(WhatsAppOtpService $otpService): void
    {
        if (! $this->otpChallenge) {
            $this->otpStep = 1;

            return;
        }

        $remaining = $otpService->getCooldownRemaining($this->otpChallenge);
        if ($remaining > 0) {
            $this->cooldownSeconds = $remaining;

            return;
        }

        $this->throttleOtp('send', 'otp', 5, 900);
        $result = $otpService->sendOtp($this->waIdentifier);
        $this->cooldownSeconds = $result['cooldown_seconds'];
        $this->resetErrorBag();
        session()->flash('status', 'Kode OTP baru berhasil dikirim ke WhatsApp Anda.');
    }

    /**
     * Verifikasi kode OTP dan selesaikan login.
     */
    public function verifyOtp(WhatsAppOtpService $otpService): void
    {
        $this->validate([
            'otp' => ['required', 'string', 'size:6'],
        ], [
            'otp.required' => 'Masukkan 6-digit kode OTP.',
            'otp.size' => 'Kode OTP harus tepat 6 angka.',
        ]);

        if (! $this->otpChallenge) {
            $this->otpStep = 1;

            return;
        }

        $this->throttleOtp('verify', 'otp', 10, 600);
        $user = $otpService->verifyOtp($this->otpChallenge, $this->otp);

        Auth::login($user, $this->rememberOtp);

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Batas per IP di atas cooldown per akun: tanpa ini satu IP bisa mengirim WhatsApp ke banyak
     * nomor sekaligus (biaya Fonnte) atau menebak OTP lintas akun.
     */
    private function throttleOtp(string $action, string $errorKey, int $maxAttempts, int $decaySeconds): void
    {
        $key = "otp-{$action}:".request()->ip();

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                $errorKey => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /**
     * Kembali ke langkah 1 untuk mengubah nomor WhatsApp / identitas.
     */
    public function backToOtpIdentifier(): void
    {
        $this->otpStep = 1;
        $this->otp = '';
        $this->resetErrorBag();
    }
}; ?>

<div>
    <!-- Heading -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
            {{ $otpEnabled && $loginMode === 'whatsapp' ? 'Masuk via WhatsApp' : 'Masuk ke Akun' }}
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
            {{ $otpEnabled && $loginMode === 'whatsapp'
                ? 'Masukkan nomor WhatsApp terdaftar untuk menerima OTP.'
                : 'Masukkan kredensial akun Anda untuk melanjutkan.' }}
        </p>
    </div>

    @if ($otpEnabled)
        <!-- Mode Switcher (Password vs WhatsApp OTP) -->
        <div class="grid grid-cols-2 p-1 bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl mb-6 text-xs font-semibold">
            <button type="button"
                    wire:click="setLoginMode('password')"
                    class="py-2.5 px-3 rounded-lg transition-all duration-150 flex items-center justify-center gap-1.5 cursor-pointer {{ $loginMode === 'password' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm border border-slate-200/90 dark:border-slate-700/60 font-semibold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' }}">
                <svg class="w-3.5 h-3.5 {{ $loginMode === 'password' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500' }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
                <span>Password</span>
            </button>

            <button type="button"
                    wire:click="setLoginMode('whatsapp')"
                    class="py-2.5 px-3 rounded-lg transition-all duration-150 flex items-center justify-center gap-1.5 cursor-pointer {{ $loginMode === 'whatsapp' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm border border-slate-200/90 dark:border-slate-700/60 font-semibold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' }}">
                <svg class="w-3.5 h-3.5 {{ $loginMode === 'whatsapp' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500' }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.145.14-.316.14-.49a4.52 4.52 0 00-.095-.733C4.095 16.29 3 14.26 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                </svg>
                <span>WhatsApp OTP</span>
            </button>
        </div>
    @endif

    <!-- Session Status Alert -->
    <x-auth-session-status class="mb-5" :status="session('status')" />

    @if (! $otpEnabled || $loginMode === 'password')
        <!-- ==================== FORM 1: LOGIN PASSWORD ==================== -->
        <form wire:submit="login" class="space-y-4">
            <!-- Identifier (Username / Email / Nomor HP) -->
            <div>
                <label for="login" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('Username, email, atau nomor HP') }}</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500">
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                    </div>
                    <input wire:model="form.login" id="login" type="text" name="login" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="username atau email..." class="w-full min-h-[46px] pl-10 pr-3.5 py-2.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 hover:border-slate-400 dark:hover:border-slate-700 focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 rounded-xl text-sm text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 transition outline-none shadow-xs">
                </div>
                <x-input-error :messages="$errors->get('form.login')" class="mt-1.5" />
            </div>

            <!-- Password with toggle visibility -->
            <div x-data="{ show: false }">
                <div class="flex items-center justify-between mb-1.5">
                    <label for="password" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Password') }}</label>
                    @if (Route::has('password.request'))
                        <a class="text-xs text-slate-500 dark:text-slate-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors focus:outline-none focus:underline font-medium" href="{{ route('password.request') }}" wire:navigate>
                            {{ __('Forgot your password?') }}
                        </a>
                    @endif
                </div>

                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500">
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <input wire:model="form.password" id="password" :type="show ? 'text' : 'password'" name="password" required autocomplete="current-password" placeholder="••••••••" class="w-full min-h-[46px] pl-10 pr-10 py-2.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 hover:border-slate-400 dark:hover:border-slate-700 focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 rounded-xl text-sm text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 transition outline-none shadow-xs">
                    <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 transition focus:outline-none" tabindex="-1" :title="show ? 'Sembunyikan password' : 'Lihat password'">
                        <svg x-show="!show" class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <svg x-show="show" x-cloak class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                        </svg>
                    </button>
                </div>
                <x-input-error :messages="$errors->get('form.password')" class="mt-1.5" />
            </div>

            <!-- Remember Me -->
            <div class="pt-1">
                <label for="remember" class="inline-flex items-center gap-2 cursor-pointer select-none">
                    <input wire:model="form.remember" id="remember" type="checkbox" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-emerald-600 focus:ring-emerald-500/30 focus:ring-offset-white dark:focus:ring-offset-slate-950 cursor-pointer" name="remember">
                    <span class="text-xs text-slate-600 dark:text-slate-400">{{ __('Remember me') }}</span>
                </label>
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <button type="submit" wire:loading.attr="disabled" class="group relative w-full min-h-[46px] px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-semibold text-sm rounded-xl shadow-md shadow-emerald-600/20 hover:shadow-emerald-600/30 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-slate-950 transition-all duration-150 inline-flex flex-row items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap">
                    <span wire:loading.remove wire:target="login" class="inline-flex flex-row items-center justify-center gap-2 whitespace-nowrap">
                        <span>{{ __('Log in') }}</span>
                        <svg class="w-4 h-4 shrink-0 transition-transform duration-150 group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </span>
                    <span wire:loading.inline-flex wire:target="login" class="flex-row items-center justify-center gap-2 whitespace-nowrap">
                        <svg class="animate-spin h-4 w-4 shrink-0 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="whitespace-nowrap">Memverifikasi...</span>
                    </span>
                </button>
            </div>
        </form>

    @else
        <!-- ==================== FORM 2: LOGIN WHATSAPP OTP ==================== -->
        @if ($otpStep === 1)
            <!-- Langkah 1: Input Identitas Pengguna -->
            <form wire:submit="sendOtp" class="space-y-4">
                <div>
                    <label for="waIdentifier" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('Nomor WhatsApp, Username, atau Email') }}</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.145.14-.316.14-.49a4.52 4.52 0 00-.095-.733C4.095 16.29 3 14.26 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                            </svg>
                        </div>
                        <input wire:model="waIdentifier" id="waIdentifier" type="text" required autofocus placeholder="Contoh: 081234567890" class="w-full min-h-[46px] pl-10 pr-3.5 py-2.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 hover:border-slate-400 dark:hover:border-slate-700 focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 rounded-xl text-sm text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 transition outline-none shadow-xs">
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5">Kode 6 angka akan dikirimkan langsung ke nomor WhatsApp Anda.</p>
                    <x-input-error :messages="$errors->get('waIdentifier')" class="mt-1.5" />
                </div>

                <div class="pt-2">
                    <button type="submit" wire:loading.attr="disabled" class="group relative w-full min-h-[46px] px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-semibold text-sm rounded-xl shadow-md shadow-emerald-600/20 hover:shadow-emerald-600/30 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-slate-950 transition-all duration-150 inline-flex flex-row items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap">
                        <span wire:loading.remove wire:target="sendOtp" class="inline-flex flex-row items-center justify-center gap-2 whitespace-nowrap">
                            <span>Kirim Kode OTP</span>
                            <svg class="w-4 h-4 shrink-0 transition-transform duration-150 group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        </span>
                        <span wire:loading.inline-flex wire:target="sendOtp" class="flex-row items-center justify-center gap-2 whitespace-nowrap">
                            <svg class="animate-spin h-4 w-4 shrink-0 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="whitespace-nowrap">Mengirim OTP...</span>
                        </span>
                    </button>
                </div>
            </form>

        @else
            <!-- Langkah 2: Input 6-Digit OTP -->
            <form wire:submit="verifyOtp" class="space-y-4">
                <!-- Banner Target WhatsApp -->
                <div class="p-3.5 rounded-xl bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2 text-slate-600 dark:text-slate-400">
                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>Kalau akun terdaftar, OTP dikirim ke <strong class="text-slate-900 dark:text-slate-100 font-bold">{{ $maskedPhone }}</strong></span>
                    </div>
                    <button type="button" wire:click="backToOtpIdentifier" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 font-semibold underline focus:outline-none cursor-pointer">
                        Ubah
                    </button>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label for="otp" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Kode Verifikasi (OTP)') }}</label>
                        <span class="text-xs text-slate-400 font-medium">6 digit angka</span>
                    </div>

                    <!-- Modern 6-Digit Segmented Box OTP Input -->
                    <div x-data="{
                        otp: @entangle('otp'),
                        digits: ['', '', '', '', '', ''],
                        submitting: false,
                        init() {
                            this.syncFromOtp();
                            this.$watch('otp', () => {
                                this.syncFromOtp();
                                if ((this.otp || '').length < 6) {
                                    this.submitting = false;
                                }
                            });
                            this.$nextTick(() => {
                                if (this.$refs.digit0) this.$refs.digit0.focus();
                            });
                        },
                        syncFromOtp() {
                            const raw = (this.otp || '').toString().slice(0, 6);
                            for (let i = 0; i < 6; i++) {
                                this.digits[i] = raw[i] || '';
                            }
                        },
                        syncToOtp() {
                            this.otp = this.digits.join('');
                            if (this.otp.length === 6 && !this.submitting) {
                                this.triggerSubmit();
                            } else if (this.otp.length < 6) {
                                this.submitting = false;
                            }
                        },
                        triggerSubmit() {
                            this.submitting = true;
                            this.$nextTick(() => {
                                const btn = document.getElementById('verify-otp-submit-btn');
                                if (btn && !btn.disabled) {
                                    btn.click();
                                } else if (this.$root && this.$root.closest('form')) {
                                    this.$root.closest('form').requestSubmit();
                                }
                            });
                            setTimeout(() => {
                                this.submitting = false;
                            }, 1500);
                        },
                        handleInput(i, e) {
                            let val = e.target.value.replace(/\D/g, '');
                            if (val.length > 1) {
                                this.handlePaste(val);
                                return;
                            }
                            this.digits[i] = val;
                            this.syncToOtp();
                            if (val && i < 5) {
                                this.$refs['digit' + (i + 1)].focus();
                            }
                        },
                        handleKeydown(i, e) {
                            if (e.key === 'Backspace') {
                                this.submitting = false;
                                if (!this.digits[i] && i > 0) {
                                    e.preventDefault();
                                    this.digits[i - 1] = '';
                                    this.syncToOtp();
                                    this.$refs['digit' + (i - 1)].focus();
                                } else {
                                    this.digits[i] = '';
                                    this.syncToOtp();
                                }
                            } else if (e.key === 'ArrowLeft' && i > 0) {
                                e.preventDefault();
                                this.$refs['digit' + (i - 1)].focus();
                            } else if (e.key === 'ArrowRight' && i < 5) {
                                e.preventDefault();
                                this.$refs['digit' + (i + 1)].focus();
                            }
                        },
                        handlePaste(text) {
                            const clean = (text || '').replace(/\D/g, '').slice(0, 6);
                            if (!clean) return;
                            for (let i = 0; i < 6; i++) {
                                this.digits[i] = clean[i] || '';
                            }
                            this.syncToOtp();
                            const target = Math.min(clean.length, 5);
                            if (this.$refs['digit' + target]) {
                                this.$refs['digit' + target].focus();
                            }
                        }
                    }" class="py-1">
                        <div class="flex items-center justify-center gap-1.5 sm:gap-2">
                            <!-- Digit 0 -->
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" x-ref="digit0"
                                   :value="digits[0]" @input="handleInput(0, $event)" @keydown="handleKeydown(0, $event)"
                                   @paste.prevent="handlePaste($event.clipboardData.getData('text'))"
                                   autocomplete="one-time-code"
                                   :class="digits[0] ? 'border-emerald-600 dark:border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 ring-2 ring-emerald-600/20 dark:ring-emerald-500/25 shadow-xs' : 'border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 hover:border-slate-400 dark:hover:border-slate-700'"
                                   class="w-10 h-13 sm:w-12 sm:h-14 text-center font-mono text-xl sm:text-2xl font-bold rounded-xl border focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/30 focus:scale-105 focus:outline-none transition-all duration-150 select-none outline-none">

                            <!-- Digit 1 -->
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" x-ref="digit1"
                                   :value="digits[1]" @input="handleInput(1, $event)" @keydown="handleKeydown(1, $event)"
                                   @paste.prevent="handlePaste($event.clipboardData.getData('text'))"
                                   :class="digits[1] ? 'border-emerald-600 dark:border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 ring-2 ring-emerald-600/20 dark:ring-emerald-500/25 shadow-xs' : 'border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 hover:border-slate-400 dark:hover:border-slate-700'"
                                   class="w-10 h-13 sm:w-12 sm:h-14 text-center font-mono text-xl sm:text-2xl font-bold rounded-xl border focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/30 focus:scale-105 focus:outline-none transition-all duration-150 select-none outline-none">

                            <!-- Digit 2 -->
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" x-ref="digit2"
                                   :value="digits[2]" @input="handleInput(2, $event)" @keydown="handleKeydown(2, $event)"
                                   @paste.prevent="handlePaste($event.clipboardData.getData('text'))"
                                   :class="digits[2] ? 'border-emerald-600 dark:border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 ring-2 ring-emerald-600/20 dark:ring-emerald-500/25 shadow-xs' : 'border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 hover:border-slate-400 dark:hover:border-slate-700'"
                                   class="w-10 h-13 sm:w-12 sm:h-14 text-center font-mono text-xl sm:text-2xl font-bold rounded-xl border focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/30 focus:scale-105 focus:outline-none transition-all duration-150 select-none outline-none">

                            <!-- Middle Divider Dot -->
                            <span class="text-slate-400 dark:text-slate-600 font-bold text-sm px-0.5 select-none">&bull;</span>

                            <!-- Digit 3 -->
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" x-ref="digit3"
                                   :value="digits[3]" @input="handleInput(3, $event)" @keydown="handleKeydown(3, $event)"
                                   @paste.prevent="handlePaste($event.clipboardData.getData('text'))"
                                   :class="digits[3] ? 'border-emerald-600 dark:border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 ring-2 ring-emerald-600/20 dark:ring-emerald-500/25 shadow-xs' : 'border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 hover:border-slate-400 dark:hover:border-slate-700'"
                                   class="w-10 h-13 sm:w-12 sm:h-14 text-center font-mono text-xl sm:text-2xl font-bold rounded-xl border focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/30 focus:scale-105 focus:outline-none transition-all duration-150 select-none outline-none">

                            <!-- Digit 4 -->
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" x-ref="digit4"
                                   :value="digits[4]" @input="handleInput(4, $event)" @keydown="handleKeydown(4, $event)"
                                   @paste.prevent="handlePaste($event.clipboardData.getData('text'))"
                                   :class="digits[4] ? 'border-emerald-600 dark:border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 ring-2 ring-emerald-600/20 dark:ring-emerald-500/25 shadow-xs' : 'border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 hover:border-slate-400 dark:hover:border-slate-700'"
                                   class="w-10 h-13 sm:w-12 sm:h-14 text-center font-mono text-xl sm:text-2xl font-bold rounded-xl border focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/30 focus:scale-105 focus:outline-none transition-all duration-150 select-none outline-none">

                            <!-- Digit 5 -->
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" x-ref="digit5"
                                   :value="digits[5]" @input="handleInput(5, $event)" @keydown="handleKeydown(5, $event)"
                                   @paste.prevent="handlePaste($event.clipboardData.getData('text'))"
                                   :class="digits[5] ? 'border-emerald-600 dark:border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 ring-2 ring-emerald-600/20 dark:ring-emerald-500/25 shadow-xs' : 'border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 hover:border-slate-400 dark:hover:border-slate-700'"
                                   class="w-10 h-13 sm:w-12 sm:h-14 text-center font-mono text-xl sm:text-2xl font-bold rounded-xl border focus:border-emerald-600 dark:focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/30 focus:scale-105 focus:outline-none transition-all duration-150 select-none outline-none">
                        </div>

                        <!-- Fallback / Entangle Hidden Input for Livewire and automated test suite -->
                        <input type="hidden" wire:model="otp" name="otp">
                    </div>

                    <x-input-error :messages="$errors->get('otp')" class="mt-2 text-center" />
                </div>

                <!-- Remember Me (OTP) -->
                <div class="pt-1">
                    <label for="rememberOtp" class="inline-flex items-center gap-2 cursor-pointer select-none">
                        <input wire:model="rememberOtp" id="rememberOtp" type="checkbox" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-emerald-600 focus:ring-emerald-500/30 focus:ring-offset-white dark:focus:ring-offset-slate-950 cursor-pointer" name="rememberOtp">
                        <span class="text-xs text-slate-600 dark:text-slate-400">{{ __('Remember me') }}</span>
                    </label>
                </div>

                <!-- Submit Button -->
                <div class="pt-2">
                    <button type="submit" id="verify-otp-submit-btn" wire:loading.attr="disabled" class="group relative w-full min-h-[46px] px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-semibold text-sm rounded-xl shadow-md shadow-emerald-600/20 hover:shadow-emerald-600/30 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-slate-950 transition-all duration-150 inline-flex flex-row items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap">
                        <span wire:loading.remove wire:target="verifyOtp" class="inline-flex flex-row items-center justify-center gap-2 whitespace-nowrap">
                            <span>Verifikasi &amp; Masuk</span>
                            <svg class="w-4 h-4 shrink-0 transition-transform duration-150 group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        </span>
                        <span wire:loading.inline-flex wire:target="verifyOtp" class="flex-row items-center justify-center gap-2 whitespace-nowrap">
                            <svg class="animate-spin h-4 w-4 shrink-0 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="whitespace-nowrap">Memverifikasi OTP...</span>
                        </span>
                    </button>
                </div>

                <!-- Resend OTP Countdown (Alpine.js) -->
                <div x-data="{
                    cooldown: @entangle('cooldownSeconds'),
                    timer: null,
                    init() {
                        if (this.cooldown > 0) {
                            this.start();
                        }
                        this.$watch('cooldown', (val) => {
                            if (val > 0) {
                                this.start();
                            }
                        });
                    },
                    start() {
                        clearInterval(this.timer);
                        this.timer = setInterval(() => {
                            if (this.cooldown > 0) {
                                this.cooldown--;
                            } else {
                                clearInterval(this.timer);
                            }
                        }, 1000);
                    }
                }" class="text-center pt-2">
                    <template x-if="cooldown > 0">
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Kirim ulang kode dalam <span class="font-mono text-emerald-600 dark:text-emerald-400 font-semibold" x-text="cooldown + 's'"></span>
                        </p>
                    </template>
                    <template x-if="cooldown <= 0">
                        <button type="button" wire:click="resendOtp" wire:loading.attr="disabled" class="text-xs text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 font-semibold underline focus:outline-none cursor-pointer inline-flex flex-row items-center justify-center gap-1.5 whitespace-nowrap disabled:opacity-60">
                            <span wire:loading.remove wire:target="resendOtp">
                                Kirim Ulang Kode OTP
                            </span>
                            <span wire:loading.inline-flex wire:target="resendOtp" class="items-center justify-center" title="Mengirim ulang...">
                                <svg class="animate-spin h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                        </button>
                    </template>
                </div>
            </form>
        @endif
    @endif

        @if (app(\App\Services\SocialAuthService::class)->googleConfigured())
        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200 dark:border-slate-800"></div></div>
            <div class="relative flex justify-center text-xs uppercase"><span class="bg-white dark:bg-slate-900 px-3 text-slate-400 font-medium">atau lanjutkan dengan</span></div>
        </div>

        <a href="{{ route('auth.google') }}" class="w-full min-h-[44px] flex items-center justify-center gap-2.5 py-2.5 px-4 rounded-xl border border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800/80 transition-all shadow-xs">
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24">
                <path fill="#EA4335" d="M12 5c1.6 0 3 .6 4.1 1.7l3.1-3.1C17.3 1.8 14.8 1 12 1 7.5 1 3.7 3.6 1.9 7.4l3.7 2.9C6.5 7.4 9 5 12 5z"/>
                <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5c-.3 1.5-1.1 2.8-2.4 3.7l3.7 2.9c2.2-2 3.7-5 3.7-8.8z"/>
                <path fill="#FBBC05" d="M5.6 14.7c-.2-.7-.4-1.5-.4-2.3 0-.8.2-1.6.4-2.3L1.9 7.2C.7 9.6 0 12.3 0 15.2s.7 5.6 1.9 8l3.7-2.9z"/>
                <path fill="#34A853" d="M12 23.5c3.2 0 6-1.1 8-3l-3.7-2.9c-1.1.7-2.5 1.2-4.3 1.2-3 0-5.5-2-6.4-4.8L1.9 16.9C3.7 20.7 7.5 23.5 12 23.5z"/>
            </svg>
            <span>Masuk dengan Google</span>
        </a>
    @endif

    @if (Route::has('register') && \App\Support\SaasSettings::registrationOpen())
        <p class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
            Belum punya toko?
            <a href="{{ route('register') }}" wire:navigate class="font-semibold text-emerald-600 dark:text-emerald-400 hover:underline">Daftar gratis</a>
        </p>
    @endif

    <div class="mt-8 pt-4 border-t border-slate-200/60 dark:border-slate-800/60 flex items-center justify-center gap-3 text-[11px] text-slate-400 dark:text-slate-500">
        <a href="{{ route('legal.privacy') }}" target="_blank" class="hover:underline">Kebijakan Privasi</a>
        <span>&bull;</span>
        <a href="{{ route('legal.terms') }}" target="_blank" class="hover:underline">Ketentuan Layanan</a>
    </div>
</div>
