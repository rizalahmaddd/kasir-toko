<?php

use App\Http\Middleware\EnsureTenantAccess;
use App\Livewire\Actions\Logout;
use App\Support\CurrentTenant;
use App\Support\NumberFormatter;
use App\Support\SaasSettings;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public function with(): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $reason = $tenant?->blockedReason();

        return [
            'tenant' => $tenant,
            'message' => $reason ? EnsureTenantAccess::MESSAGES[$reason] : null,
            'renewal' => SaasSettings::renewalInfo(),
        ];
    }

    public function mount(): void
    {
        if (app(CurrentTenant::class)->get()?->blockedReason() === null) {
            $this->redirect(route('dashboard', absolute: false), navigate: true);
        }
    }

    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">{{ $tenant?->name ?? 'Toko' }} belum bisa dipakai</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">{{ $message }}</p>
        @if ($endsAt = $tenant?->accessEndsAt())
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Masa aktif berakhir {{ $endsAt->translatedFormat('d F Y') }}. Data toko tetap tersimpan.</p>
        @endif
    </div>

    @if ($renewal['plans'] || $renewal['payment_instructions'] || $renewal['contact'])
        <div class="mb-6 space-y-3 text-sm">
            @if ($renewal['plans'])
                <ul class="divide-y divide-slate-200 dark:divide-slate-800 rounded-lg border border-slate-200 dark:border-slate-800">
                    @foreach ($renewal['plans'] as $plan)
                        <li class="px-3 py-2 flex items-center justify-between gap-3">
                            <span class="font-medium text-slate-900 dark:text-slate-100">{{ $plan['label'] }}</span>
                            <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ NumberFormatter::currency($plan['price']) }}/bulan</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($renewal['payment_instructions'])
                <p class="text-slate-600 dark:text-slate-300 whitespace-pre-line">{{ $renewal['payment_instructions'] }}</p>
            @endif
            @if ($renewal['contact'])
                <p class="text-slate-600 dark:text-slate-300">Hubungi admin layanan: <span class="font-semibold text-slate-900 dark:text-white">{{ $renewal['contact'] }}</span></p>
            @endif
        </div>
    @endif

    <div class="space-y-2">
        @if (auth()->user()?->isSuperAdmin())
            <a href="{{ route('settings.subscription') }}" wire:navigate class="block w-full">
                <x-primary-button class="w-full justify-center">
                    <i data-lucide="qr-code" class="w-4 h-4 mr-2"></i>
                    Perpanjang / Bayar Sekarang (QRIS)
                </x-primary-button>
            </a>
        @endif
        <x-secondary-button wire:click="logout" class="w-full justify-center">Keluar</x-secondary-button>
    </div>
</div>
