@props([
    'title' => 'Fitur Ini Khusus Paket Pro',
    'feature' => 'Fitur Pro',
    'description' => 'Tingkatkan paket toko Anda ke Pro untuk membuka fitur ini beserta laporan lengkap, customer display, dan pencatatan piutang kasbon.',
    'icon' => 'sparkles',
])

<div class="max-w-2xl mx-auto my-6 sm:my-12">
    <div class="relative overflow-hidden rounded-3xl border border-amber-500/30 bg-gradient-to-b from-slate-900 via-slate-900/95 to-slate-950 p-6 sm:p-10 text-center shadow-2xl">
        {{-- Background Glow --}}
        <div class="absolute -top-24 left-1/2 -translate-x-1/2 w-96 h-96 bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 space-y-6">
            {{-- Icon Badge --}}
            <div class="inline-flex p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 shadow-inner">
                <i data-lucide="{{ $icon }}" class="w-8 h-8"></i>
            </div>

            <div class="space-y-2">
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs font-bold uppercase tracking-wider">
                    <i data-lucide="crown" class="w-3.5 h-3.5"></i>
                    <span>Eksklusif Paket Pro</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">{{ $title }}</h2>
                <p class="text-sm sm:text-base text-slate-300 max-w-lg mx-auto leading-relaxed">
                    {{ $description }}
                </p>
            </div>

            {{-- Feature Highlights --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-left max-w-lg mx-auto py-2">
                <div class="flex items-center gap-2 text-xs text-slate-300 bg-slate-800/40 border border-slate-800 rounded-xl px-3 py-2">
                    <i data-lucide="check" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                    <span>Customer Display QRIS Dinamis</span>
                </div>
                <div class="flex items-center gap-2 text-xs text-slate-300 bg-slate-800/40 border border-slate-800 rounded-xl px-3 py-2">
                    <i data-lucide="check" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                    <span>Catatan Piutang &amp; Kasbon Pembeli</span>
                </div>
                <div class="flex items-center gap-2 text-xs text-slate-300 bg-slate-800/40 border border-slate-800 rounded-xl px-3 py-2">
                    <i data-lucide="check" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                    <span>Laporan Analisis Laba / Rugi</span>
                </div>
                <div class="flex items-center gap-2 text-xs text-slate-300 bg-slate-800/40 border border-slate-800 rounded-xl px-3 py-2">
                    <i data-lucide="check" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                    <span>Ekspor Salinan Data ke Excel/CSV</span>
                </div>
            </div>

            {{-- Price Banner & Action Buttons --}}
            @php
                $proMonthly = \App\Support\SaasPlans::effectiveMonthlyPrice('pro') ?? (int) (config('saas.plans.pro.price') ?? 20000);
                $proYearly = \App\Support\SaasPlans::effectiveYearlyPrice('pro') ?? (int) (config('saas.plans.pro.yearly_price') ?? 199000);
            @endphp
            <div class="pt-2 space-y-4">
                <div class="text-xs text-slate-400">
                    Mulai dari <strong class="text-white text-sm font-bold">{{ \App\Support\NumberFormatter::currency($proMonthly) }}</strong> / bulan atau <strong class="text-emerald-400 text-sm font-bold">{{ \App\Support\NumberFormatter::currency($proYearly) }}</strong> / tahun
                </div>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                    <a href="{{ route('settings.subscription') }}" wire:navigate class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-slate-950 font-bold text-sm shadow-lg transition-all transform hover:-translate-y-0.5">
                        <i data-lucide="sparkles" class="w-4 h-4"></i>
                        <span>Upgrade ke Pro Sekarang</span>
                    </a>
                    <a href="{{ route('dashboard') }}" wire:navigate class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl border border-slate-800 bg-slate-900/60 hover:bg-slate-800 text-slate-300 text-sm font-semibold transition">
                        <span>Kembali ke Beranda</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
