<?php

use App\Http\Middleware\EnsureTenantAccess;
use App\Livewire\Actions\Logout;
use App\Support\CurrentTenant;
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

    <x-secondary-button wire:click="logout" class="w-full justify-center">Keluar</x-secondary-button>
</div>
