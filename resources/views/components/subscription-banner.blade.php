@php
    $currentTenant = app(\App\Support\CurrentTenant::class)->get();
@endphp

@if ($currentTenant)
    @if ($currentTenant->isInGracePeriod())
        <div class="mb-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-sm">
            <div class="flex items-center gap-2.5">
                <i data-lucide="alert-triangle" class="w-5 h-5 text-rose-400 shrink-0"></i>
                <div>
                    <span class="font-bold">Masa aktif tokomu telah habis!</span>
                    <span>Toko sedang dalam masa tenggang tersisa {{ $currentTenant->daysLeftInGrace() }} hari sebelum operasional dihentikan.</span>
                </div>
            </div>
            @if (auth()->user()?->isSuperAdmin())
                <a href="{{ route('settings.subscription') }}" wire:navigate class="shrink-0">
                    <x-primary-button size="xs" class="!bg-rose-600 hover:!bg-rose-500">
                        <i data-lucide="qr-code" class="w-3.5 h-3.5 mr-1"></i>
                        Perpanjang Sekarang (QRIS)
                    </x-primary-button>
                </a>
            @endif
        </div>
    @elseif ($currentTenant->isOnTrial() && auth()->user()?->isSuperAdmin())
        @php
            $proMonthly = \App\Support\SaasPlans::effectiveMonthlyPrice('pro') ?? (int) (config('saas.plans.pro.price') ?? 20000);
        @endphp
        <div class="mb-4 rounded-xl border border-amber-500/30 bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-slate-900/40 p-3 text-sm text-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-sm">
            <div class="flex items-center gap-2.5">
                <div class="p-1.5 rounded-lg bg-amber-500/20 text-amber-400 shrink-0">
                    <i data-lucide="sparkles" class="w-4 h-4"></i>
                </div>
                <div>
                    <span class="font-bold text-amber-300">Uji Coba Pro Gratis (Tersisa {{ $currentTenant->daysLeftInTrial() }} hari)</span>
                    <span class="text-xs text-slate-300 block sm:inline sm:ml-1">Semua fitur Pro terbuka penuh. Kunci paket Pro tokomu mulai {{ \App\Support\NumberFormatter::currency($proMonthly) }}/bln.</span>
                </div>
            </div>
            <a href="{{ route('settings.subscription') }}" wire:navigate class="shrink-0">
                <x-primary-button size="xs" class="!bg-gradient-to-r !from-amber-500 !to-amber-600 !text-slate-950 hover:!from-amber-400 hover:!to-amber-500 font-bold shadow-sm">
                    <i data-lucide="crown" class="w-3.5 h-3.5 mr-1"></i>
                    Upgrade Pro Sekarang
                </x-primary-button>
            </a>
        </div>
    @elseif ($currentTenant->isFree() && auth()->user()?->isSuperAdmin())
        <div class="mb-4 rounded-xl border border-emerald-500/30 bg-gradient-to-r from-emerald-500/10 via-emerald-500/5 to-slate-900/40 p-3 text-sm text-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-sm">
            <div class="flex items-center gap-2.5">
                <div class="p-1.5 rounded-lg bg-emerald-500/20 text-emerald-400 shrink-0">
                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                </div>
                <div>
                    <span class="font-bold text-emerald-400">Paket Gratis (Esensial)</span>
                    <span class="text-xs text-slate-300 block sm:inline sm:ml-1">Fitur kasir POS tetap aktif selamanya. Butuh fitur kasbon, laporan lengkap, dan layar pelanggan?</span>
                </div>
            </div>
            <a href="{{ route('settings.subscription') }}" wire:navigate class="shrink-0">
                <x-primary-button size="xs" class="!bg-emerald-600 hover:!bg-emerald-500 font-bold shadow-sm">
                    <i data-lucide="zap" class="w-3.5 h-3.5 mr-1"></i>
                    Upgrade ke Pro
                </x-primary-button>
            </a>
        </div>
    @elseif ($currentTenant->accessEndsAt() && $currentTenant->accessEndsAt()->isFuture() && now()->diffInDays($currentTenant->accessEndsAt()) <= 7 && ! $currentTenant->hasExpired())
        <div class="mb-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-sm">
            <div class="flex items-center gap-2.5">
                <i data-lucide="clock" class="w-5 h-5 text-amber-400 shrink-0"></i>
                <div>
                    <span class="font-semibold">Masa aktif paket {{ $currentTenant->planLabel() }} tersisa {{ max(1, (int) ceil(now()->diffInHours($currentTenant->accessEndsAt()) / 24)) }} hari lagi (s/d {{ $currentTenant->accessEndsAt()->format('d M Y') }}).</span>
                </div>
            </div>
            @if (auth()->user()?->isSuperAdmin())
                <a href="{{ route('settings.subscription') }}" wire:navigate class="shrink-0">
                    <x-secondary-button size="xs" class="border-amber-500/30 text-amber-200 hover:bg-amber-500/20 font-bold">
                        <i data-lucide="plus" class="w-3.5 h-3.5 mr-1"></i>
                        Tambah Masa Aktif
                    </x-secondary-button>
                </a>
            @endif
        </div>
    @endif
@endif
