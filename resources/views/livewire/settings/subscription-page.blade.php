<div class="space-y-8">
    @if (session('pro_required'))
        <div class="rounded-xl border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 px-4 py-3 text-sm text-amber-900 dark:text-amber-200 flex items-center gap-3">
            <i data-lucide="lock" class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0"></i>
            <span>Fitur yang Anda akses <strong>khusus untuk paket Pro</strong>. Upgrade atau perpanjang paket toko untuk menggunakannya.</span>
        </div>
    @endif

    {{-- Header & Status Langganan Saat Ini (Focal Panel) --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/90 p-6 sm:p-7 shadow-sm">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 dark:bg-emerald-500/10 border border-emerald-300 dark:border-emerald-500/20 text-emerald-700 dark:text-emerald-400">
                            <i data-lucide="store" class="h-5 w-5"></i>
                        </div>
                        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $tenant?->name }}</h1>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold {{ $tenant?->isLifetime() ? 'bg-indigo-100 dark:bg-indigo-950/60 text-indigo-800 dark:text-indigo-300 border border-indigo-300 dark:border-indigo-700' : ($tenant?->isPro() ? 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700') }}">
                        <i data-lucide="{{ $tenant?->isLifetime() ? 'crown' : ($tenant?->isPro() ? 'sparkles' : 'shield') }}" class="w-3.5 h-3.5 {{ $tenant?->isLifetime() ? 'text-amber-500 dark:text-amber-400' : ($tenant?->isPro() ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400') }}"></i>
                        <span>Paket {{ $tenant?->planLabel() }}</span>
                    </span>
                </div>

                <div class="flex items-start gap-2.5 text-xs sm:text-sm text-slate-600 dark:text-slate-400">
                    <i data-lucide="calendar" class="w-4 h-4 text-slate-400 dark:text-slate-500 shrink-0 mt-0.5"></i>
                    <span class="leading-relaxed">
                        @if ($tenant?->isOnTrial())
                            Masa uji coba gratis berakhir pada <span class="font-semibold text-slate-900 dark:text-slate-200">{{ $tenant->accessEndsAt()?->translatedFormat('d F Y') }}</span>
                            @php $sisaHari = $tenant->daysLeftInTrial(); @endphp
                            <span class="text-amber-600 dark:text-amber-400 font-semibold">({{ $sisaHari > 0 ? 'Tersisa '.$sisaHari.' hari' : 'Sudah berakhir' }})</span>.
                        @elseif ($endsAt = $tenant?->accessEndsAt())
                            Langganan aktif sampai <span class="font-semibold text-slate-900 dark:text-slate-200">{{ $endsAt->translatedFormat('d F Y') }}</span>
                            @php $sisaHariAktif = (int) now()->diffInDays($endsAt, false); @endphp
                            <span class="text-emerald-700 dark:text-emerald-400 font-semibold">({{ $sisaHariAktif > 0 ? 'Tersisa '.$sisaHariAktif.' hari' : 'Sudah berakhir' }})</span>.
                        @else
                            Langganan tanpa batas waktu (Permanen Seumur Hidup).
                        @endif
                    </span>
                </div>
            </div>

            {{-- Kuota Pemakaian --}}
            <div class="flex flex-wrap sm:flex-nowrap gap-3 border-t border-slate-200 dark:border-slate-800/80 pt-4 lg:border-t-0 lg:pt-0">
                <div class="flex-1 sm:w-48 rounded-xl border border-slate-200 dark:border-slate-800/80 bg-slate-50 dark:bg-slate-950/50 p-3 sm:p-3.5">
                    <div class="flex items-center justify-between gap-1.5 text-xs text-slate-600 dark:text-slate-400 mb-1.5">
                        <span class="inline-flex items-center gap-1.5 font-medium truncate">
                            <i data-lucide="users" class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 shrink-0"></i>
                            Pengguna
                        </span>
                        <span class="font-mono text-xs font-semibold text-slate-900 dark:text-slate-200 shrink-0">
                            {{ $usage['users']['current'] }}
                            @if ($usage['users']['limit'])
                                <span class="text-slate-500 dark:text-slate-400 font-normal">/ {{ $usage['users']['limit'] }}</span>
                            @endif
                        </span>
                    </div>
                    @if ($usage['users']['limit'])
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                            <div class="h-full bg-emerald-600 dark:bg-emerald-500 rounded-full transition-all" style="width: {{ $usage['users']['percent'] }}%"></div>
                        </div>
                    @else
                        <div class="flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400 mt-2">
                            <span>Kapasitas</span>
                            <span class="text-emerald-700 dark:text-emerald-400 font-semibold whitespace-nowrap text-[10px] sm:text-[11px]">Tanpa Batas</span>
                        </div>
                    @endif
                </div>

                <div class="flex-1 sm:w-48 rounded-xl border border-slate-200 dark:border-slate-800/80 bg-slate-50 dark:bg-slate-950/50 p-3 sm:p-3.5">
                    <div class="flex items-center justify-between gap-1.5 text-xs text-slate-600 dark:text-slate-400 mb-1.5">
                        <span class="inline-flex items-center gap-1.5 font-medium truncate">
                            <i data-lucide="package" class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 shrink-0"></i>
                            Katalog Produk
                        </span>
                        <span class="font-mono text-xs font-semibold text-slate-900 dark:text-slate-200 shrink-0">
                            {{ $usage['products']['current'] }}
                            @if ($usage['products']['limit'])
                                <span class="text-slate-500 dark:text-slate-400 font-normal">/ {{ $usage['products']['limit'] }}</span>
                            @endif
                        </span>
                    </div>
                    @if ($usage['products']['limit'])
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                            <div class="h-full bg-emerald-600 dark:bg-emerald-500 rounded-full transition-all" style="width: {{ $usage['products']['percent'] }}%"></div>
                        </div>
                    @else
                        <div class="flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400 mt-2">
                            <span>Kapasitas</span>
                            <span class="text-emerald-700 dark:text-emerald-400 font-semibold whitespace-nowrap text-[10px] sm:text-[11px]">Tanpa Batas</span>
                        </div>
                    @endif
                </div>

                <div class="flex-1 sm:w-48 rounded-xl border border-slate-200 dark:border-slate-800/80 bg-slate-50 dark:bg-slate-950/50 p-3 sm:p-3.5">
                    <div class="flex items-center justify-between gap-1.5 text-xs text-slate-600 dark:text-slate-400 mb-1.5">
                        <span class="inline-flex items-center gap-1.5 font-medium truncate">
                            <i data-lucide="store" class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 shrink-0"></i>
                            Outlet
                        </span>
                        <span class="font-mono text-xs font-semibold text-slate-900 dark:text-slate-200 shrink-0">
                            {{ $usage['outlets']['current'] }}
                            <span class="text-slate-500 dark:text-slate-400 font-normal">/ {{ $usage['outlets']['limit'] }}</span>
                        </span>
                    </div>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                        <div class="h-full {{ $usage['outlets']['current'] > $usage['outlets']['limit'] ? 'bg-amber-500' : 'bg-emerald-600 dark:bg-emerald-500' }} rounded-full transition-all" style="width: {{ $usage['outlets']['percent'] }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($tenant?->isInGracePeriod())
        <div class="rounded-xl border border-amber-300 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 p-4 text-xs text-amber-900 dark:text-amber-200 flex items-start gap-3">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5"></i>
            <div>
                <p class="font-bold text-sm text-amber-800 dark:text-amber-300">Masa tenggang aktif (sisa {{ $tenant->daysLeftInGrace() }} hari)</p>
                <p class="text-amber-700 dark:text-amber-200/80 mt-0.5">Langganan tokomu telah berakhir. Segera perpanjang agar akses fitur Pro tetap berjalan normal.</p>
            </div>
        </div>
    @endif

    {{-- Pemilihan Siklus Tagihan (Bulanan vs Tahunan) --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white tracking-tight">Pilih Paket Langganan</h2>
            <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 mt-0.5">Pembayaran instan diproses otomatis melalui QRIS (aktivasi seketika).</p>
        </div>

        <div class="inline-flex items-center rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-900 p-1 self-start sm:self-auto shadow-sm">
            <button type="button" wire:click="$set('billingCycle', 'monthly')"
                class="rounded-lg px-4 py-2 text-xs font-semibold transition-all duration-150 {{ $billingCycle === 'monthly' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' }}">
                Bulanan
            </button>
            <button type="button" wire:click="$set('billingCycle', 'yearly')"
                class="rounded-lg px-4 py-2 text-xs font-semibold transition-all duration-150 flex items-center gap-1.5 {{ $billingCycle === 'yearly' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' }}">
                Tahunan
                <span class="rounded-full bg-emerald-100 dark:bg-emerald-950/80 border border-emerald-300 dark:border-emerald-700 px-2 py-0.5 text-[10px] font-bold text-emerald-800 dark:text-emerald-300">Hemat 2 Bln</span>
            </button>
        </div>
    </div>

    {{-- Kartu Pilihan Paket (Free, Pro, Lifetime) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
        @foreach ($plans as $plan)
            @php
                $isFree = $plan['key'] === 'free';
                $isPro = $plan['key'] === 'pro';
                $isLifetime = $plan['key'] === 'lifetime';

                // Status paket berbayar Pro aktif (bukan trial & bukan lifetime)
                $isPaidPro = $tenant?->isPro() && ! $tenant?->isOnTrial() && ! $tenant?->isLifetime();

                // Status paket saat ini
                $isCurrent = match(true) {
                    $isLifetime => $tenant?->isLifetime() ?? false,
                    $isPro => $isPaidPro,
                    default => ($tenant?->isFree() && ! $tenant?->isOnTrial()),
                };

                $isYearly = $billingCycle === 'yearly';
                $rawPrice = ($isLifetime || ! $isYearly) ? $plan['raw_monthly_price'] : $plan['raw_yearly_price'];
                $discount = ($isLifetime || ! $isYearly) ? $plan['monthly_discount'] : $plan['yearly_discount'];
                $price = ($isLifetime || ! $isYearly) ? $plan['monthly_price'] : $plan['yearly_price'];

                // Desain kartu harmonis untuk Light Mode & Dark Mode
                $cardBorderClass = match(true) {
                    $isLifetime => 'border-2 border-indigo-500/80 bg-gradient-to-b from-indigo-50/60 via-white to-white dark:from-indigo-950/25 dark:via-slate-900/90 dark:to-slate-900 shadow-md shadow-indigo-500/5 dark:shadow-indigo-950/20 ring-1 ring-indigo-500/20',
                    $isPro => 'border-2 border-emerald-500 bg-gradient-to-b from-emerald-50/60 via-white to-white dark:from-emerald-950/25 dark:via-slate-900/90 dark:to-slate-900 shadow-md shadow-emerald-500/5 dark:shadow-emerald-950/20 ring-1 ring-emerald-500/20',
                    default => 'border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/60 shadow-sm',
                };
            @endphp
            <div class="relative flex flex-col justify-between rounded-2xl transition-all duration-200 p-6 sm:p-7 {{ $cardBorderClass }}">
                <div>
                    {{-- Header Kartu & Status Badge (Satu Baris Rapi, No Wrap, Fixed Height) --}}
                    <div class="flex items-center justify-between gap-2 mb-3.5 h-7 shrink-0">
                        @if ($isLifetime)
                            @if ($isCurrent)
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] sm:text-[11px] font-bold tracking-wide uppercase bg-indigo-100 dark:bg-indigo-950/70 text-indigo-800 dark:text-indigo-300 border border-indigo-300 dark:border-indigo-700 whitespace-nowrap shrink-0">
                                    <i data-lucide="crown" class="w-3 h-3 text-amber-500 dark:text-amber-400 shrink-0"></i>
                                    <span>Paket Aktif</span>
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] sm:text-[11px] font-bold tracking-wide uppercase bg-indigo-100 dark:bg-indigo-950/70 text-indigo-800 dark:text-indigo-300 border border-indigo-300 dark:border-indigo-700 whitespace-nowrap shrink-0 shadow-sm">
                                    <i data-lucide="crown" class="w-3 h-3 text-amber-500 dark:text-amber-400 shrink-0"></i>
                                    <span>Sekali Bayar</span>
                                </span>
                            @endif
                        @elseif ($isPro)
                            @if ($isCurrent)
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] sm:text-[11px] font-bold tracking-wide uppercase bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700 whitespace-nowrap shrink-0">
                                    <i data-lucide="check-circle-2" class="w-3 h-3 text-emerald-600 dark:text-emerald-400 shrink-0"></i>
                                    <span>Paket Aktif</span>
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] sm:text-[11px] font-bold tracking-wide uppercase bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700 whitespace-nowrap shrink-0 shadow-sm">
                                    <i data-lucide="sparkles" class="w-3 h-3 text-emerald-600 dark:text-emerald-400 shrink-0"></i>
                                    <span>Paling Populer</span>
                                </span>
                            @endif
                        @else
                            @if ($isCurrent)
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] sm:text-[11px] font-bold tracking-wide uppercase bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 whitespace-nowrap shrink-0">
                                    <i data-lucide="check" class="w-3 h-3 text-slate-500 shrink-0"></i>
                                    <span>Paket Aktif</span>
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] sm:text-[11px] font-bold tracking-wide uppercase bg-slate-100 dark:bg-slate-800/80 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700 whitespace-nowrap shrink-0">
                                    <span>Paket Dasar</span>
                                </span>
                            @endif
                        @endif

                        @if ($discount > 0)
                            @php $discountPct = (int) round(($discount / max(1, $rawPrice)) * 100); @endphp
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] sm:text-[11px] font-bold bg-amber-100 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-300 whitespace-nowrap shrink-0">
                                <i data-lucide="tag" class="w-2.5 h-2.5 shrink-0"></i> Diskon {{ $discountPct }}%
                            </span>
                        @elseif ($isPro && $isYearly && ($plan['raw_monthly_price'] * 12 > $price))
                            @php $annualSave = ($plan['raw_monthly_price'] * 12) - $price; @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] sm:text-[11px] font-semibold bg-emerald-100 dark:bg-emerald-950/60 border border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300 whitespace-nowrap shrink-0">
                                Hemat 2 Bulan
                            </span>
                        @endif
                    </div>

                    {{-- Nama Paket (Tinggi Seragam h-7) --}}
                    <div class="h-7 flex items-center">
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight flex items-center gap-1.5 truncate">
                            <span>{{ $plan['label'] }}</span>
                            @if ($isLifetime)
                                <i data-lucide="sparkles" class="w-4 h-4 text-amber-500 dark:text-amber-400 shrink-0"></i>
                            @endif
                        </h3>
                    </div>

                    {{-- Deskripsi Singkat (Tinggi Seragam h-9) --}}
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400 h-9 flex items-start leading-relaxed overflow-hidden">
                        @if ($isFree)
                            Fitur kasir esensial dan cetak struk untuk operasional harian tokomu.
                        @elseif ($isLifetime)
                            Investasi sekali bayar seumur hidup: semua fitur Pro tanpa biaya langganan.
                        @else
                            Solusi lengkap tanpa batasan: layar QRIS dinamis, piutang, dan laba rugi.
                        @endif
                    </p>

                    {{-- Area Harga Paket (Tinggi & Posisi Sejajar Sempurna) --}}
                    <div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-800/80">
                        {{-- Baris Diskon / Harga Coret (Tinggi Seragam h-6) --}}
                        <div class="h-6 flex items-center gap-2 mb-1">
                            @if ($discount > 0)
                                @php $discountPct = (int) round(($discount / max(1, $rawPrice)) * 100); @endphp
                                <span class="text-xs font-semibold text-slate-400 dark:text-slate-500 line-through tabular-nums">{{ \App\Support\NumberFormatter::currency($rawPrice) }}</span>
                                <span class="text-[10px] font-bold text-amber-900 dark:text-amber-300 bg-amber-100 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-700 px-1.5 py-0.5 rounded whitespace-nowrap">
                                    Hemat {{ \App\Support\NumberFormatter::currency($discount) }}
                                </span>
                            @elseif ($isPro && $isYearly && ($plan['raw_monthly_price'] * 12 > $price))
                                @php $annualSave = ($plan['raw_monthly_price'] * 12) - $price; @endphp
                                <span class="text-[10px] font-semibold text-emerald-800 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-950/60 border border-emerald-300 dark:border-emerald-700 px-1.5 py-0.5 rounded whitespace-nowrap">
                                    Hemat {{ \App\Support\NumberFormatter::currency($annualSave) }} vs bulanan
                                </span>
                            @else
                                <span class="text-xs text-transparent select-none">-</span>
                            @endif
                        </div>

                        {{-- Baris Nominal Utama (Tinggi Seragam h-9) --}}
                        <div class="h-9 flex items-baseline gap-1.5">
                            @if ($isFree)
                                <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Gratis</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">/ selamanya</span>
                            @else
                                <span class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight tabular-nums">{{ \App\Support\NumberFormatter::currency($price) }}</span>
                                <span class="text-xs text-slate-600 dark:text-slate-400 font-medium">/ {{ $isLifetime ? 'sekali bayar' : ($billingCycle === 'yearly' ? 'tahun' : 'bulan') }}</span>
                            @endif
                        </div>

                        {{-- Baris Keterangan Tambahan (Tinggi Seragam h-5) --}}
                        <div class="h-5 flex items-center text-[11px] mt-0.5">
                            @if (! $isLifetime && $isPro && $billingCycle === 'yearly' && $price > 0)
                                <span class="text-emerald-700 dark:text-emerald-400 font-semibold tabular-nums">(~{{ \App\Support\NumberFormatter::currency((int) round($price / 12)) }} / bln)</span>
                            @elseif ($isLifetime)
                                <span class="text-indigo-700 dark:text-indigo-400 font-medium">Akses Pro permanen seumur hidup</span>
                            @else
                                <span class="text-slate-400 dark:text-slate-500">Operasional kasir dasar</span>
                            @endif
                        </div>
                    </div>

                    {{-- Daftar Fitur --}}
                    <div class="mt-5 mb-2.5">
                        <p class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                            FITUR YANG DISERTAKAN:
                        </p>
                    </div>

                    <ul class="space-y-2.5 text-xs text-slate-700 dark:text-slate-300">
                        @foreach ($plan['features'] ?? [] as $feat)
                            <li class="flex items-start gap-2.5">
                                <i data-lucide="check" class="w-4 h-4 {{ $isLifetime ? 'text-indigo-600 dark:text-indigo-400' : ($isPro ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500') }} shrink-0 mt-0.5"></i>
                                <span class="leading-relaxed {{ $isLifetime ? 'text-slate-900 dark:text-slate-200 font-medium' : ($isPro ? 'text-slate-900 dark:text-slate-200 font-medium' : 'text-slate-600 dark:text-slate-400') }}">{{ $feat }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Tombol Aksi di Bawah Kartu --}}
                <div class="mt-7 pt-5 border-t border-slate-200 dark:border-slate-800/80">
                    @if ($isFree)
                        {{-- Paket Free: Selalu pasif / dasar --}}
                        <button type="button" disabled class="w-full h-11 inline-flex items-center justify-center gap-2 px-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 text-xs sm:text-sm font-semibold cursor-default transition-none">
                            @if ($isCurrent)
                                <i data-lucide="check" class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0"></i>
                                <span>Sedang Digunakan</span>
                            @else
                                <span>Paket Dasar (Gratis)</span>
                            @endif
                        </button>
                        <div class="h-5 mt-2 flex items-center justify-center text-[11px] text-transparent select-none">-</div>

                    @elseif ($isPro)
                        {{-- Paket Pro:
                             1. Jika sudah Lifetime: dinonaktifkan karena Pro sudah otomatis termasuk selamanya.
                             2. Jika saat ini Pro: BISA DIKLIK untuk PERPANJANG (Masa aktif akumulasi terus).
                             3. Jika belum Pro: BISA DIKLIK untuk UPGRADE.
                        --}}
                        @if ($tenant?->isLifetime())
                            <button type="button" disabled class="w-full h-11 inline-flex items-center justify-center gap-2 px-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs sm:text-sm font-semibold cursor-default transition-none">
                                <i data-lucide="check-circle-2" class="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0"></i>
                                <span>Termasuk di Paket Lifetime</span>
                            </button>
                            <div class="h-5 mt-2 flex items-center justify-center text-[11px] text-transparent select-none">-</div>
                        @elseif ($isCurrent)
                            <button wire:click="checkout('pro')" wire:loading.attr="disabled" type="button" class="w-full h-11 inline-flex items-center justify-center gap-2 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-bold text-xs sm:text-sm shadow-md shadow-emerald-600/20 transition-all">
                                <x-loading-label target="checkout('pro')" class="inline-flex items-center justify-center gap-2 whitespace-nowrap">
                                    <i data-lucide="refresh-cw" class="w-4 h-4 shrink-0 inline-block"></i>
                                    <span>Perpanjang Pro (QRIS) +{{ $billingCycle === 'yearly' ? '1 Thn' : '1 Bln' }}</span>
                                </x-loading-label>
                            </button>
                            <div class="h-5 mt-2 flex items-center justify-center text-[11px] text-slate-500 dark:text-slate-400 text-center">
                                <span class="flex items-center gap-1 whitespace-nowrap"><i data-lucide="layers" class="w-3 h-3 text-emerald-600 dark:text-emerald-400 shrink-0"></i> Masa aktif diakumulasi otomatis.</span>
                            </div>
                        @elseif ($tenant?->isOnTrial())
                            <button wire:click="checkout('pro')" wire:loading.attr="disabled" type="button" class="w-full h-11 inline-flex items-center justify-center gap-2 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-bold text-xs sm:text-sm shadow-md shadow-emerald-600/20 transition-all">
                                <x-loading-label target="checkout('pro')" class="inline-flex items-center justify-center gap-2 whitespace-nowrap">
                                    <i data-lucide="zap" class="w-4 h-4 shrink-0 inline-block"></i>
                                    <span>Upgrade ke Pro (QRIS)</span>
                                </x-loading-label>
                            </button>
                            <div class="h-5 mt-2 flex items-center justify-center text-[11px] text-center">
                                @php $trialDays = $tenant->daysLeftInTrial(); @endphp
                                @if ($trialDays > 0)
                                    <span class="flex items-center gap-1 text-emerald-700 dark:text-emerald-400 font-medium whitespace-nowrap" title="Tenang, sisa masa uji coba ({{ $trialDays }} hari) otomatis ditambahkan ke masa aktif Pro (+{{ $billingCycle === 'yearly' ? '1 Thn' : '1 Bln' }})">
                                        <i data-lucide="layers" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0"></i>
                                        <span>Sisa trial ({{ $trialDays }} hari) otomatis ditambahkan.</span>
                                    </span>
                                @else
                                    <span class="text-slate-500 dark:text-slate-400">Aktivasi otomatis instan via QRIS.</span>
                                @endif
                            </div>
                        @else
                            <button wire:click="checkout('pro')" wire:loading.attr="disabled" type="button" class="w-full h-11 inline-flex items-center justify-center gap-2 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-bold text-xs sm:text-sm shadow-md shadow-emerald-600/20 transition-all">
                                <x-loading-label target="checkout('pro')" class="inline-flex items-center justify-center gap-2 whitespace-nowrap">
                                    <i data-lucide="zap" class="w-4 h-4 shrink-0 inline-block"></i>
                                    <span>Upgrade ke Pro (QRIS)</span>
                                </x-loading-label>
                            </button>
                            <div class="h-5 mt-2 flex items-center justify-center text-[11px] text-slate-500 dark:text-slate-400 text-center">
                                <span>Aktivasi otomatis instan via QRIS.</span>
                            </div>
                        @endif

                    @elseif ($isLifetime)
                        {{-- Paket Lifetime:
                             1. Jika sudah Lifetime: dinonaktifkan (Paket Aktif Permanen).
                             2. Jika belum Lifetime: BISA DIKLIK untuk Beli / Upgrade Lifetime.
                        --}}
                        @if ($tenant?->isLifetime())
                            <button type="button" disabled class="w-full h-11 inline-flex items-center justify-center gap-2 px-4 rounded-xl border border-indigo-300 dark:border-indigo-700/60 bg-indigo-50 dark:bg-indigo-950/40 text-indigo-800 dark:text-indigo-300 text-xs sm:text-sm font-bold cursor-default transition-none">
                                <i data-lucide="check-circle-2" class="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0"></i>
                                <span>Paket Aktif Permanen</span>
                            </button>
                            <div class="h-5 mt-2 flex items-center justify-center text-[11px] text-transparent select-none">-</div>
                        @else
                            <button wire:click="checkout('lifetime')" wire:loading.attr="disabled" type="button" class="w-full h-11 inline-flex items-center justify-center gap-2 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-md shadow-indigo-600/20 transition-all">
                                <x-loading-label target="checkout('lifetime')" class="inline-flex items-center justify-center gap-2 whitespace-nowrap">
                                    <i data-lucide="crown" class="w-4 h-4 text-amber-300 shrink-0 inline-block"></i>
                                    <span>{{ $tenant?->isPro() ? 'Upgrade ke Lifetime (QRIS)' : 'Beli Paket Lifetime (QRIS)' }}</span>
                                </x-loading-label>
                            </button>
                            <div class="h-5 mt-2 flex items-center justify-center text-[11px] text-slate-500 dark:text-slate-400 text-center">
                                <span class="flex items-center gap-1 whitespace-nowrap"><i data-lucide="shield-check" class="w-3 h-3 text-indigo-600 dark:text-indigo-400 shrink-0"></i> Sekali bayar, Pro aktif selamanya.</span>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Riwayat Tagihan / Invoice --}}
    <div class="space-y-4">
        <div>
            <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white tracking-tight">Riwayat Tagihan Langganan</h3>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">Daftar transaksi dan faktur pembayaran langganan tokomu.</p>
        </div>

        @if ($invoices->isEmpty())
            <div class="rounded-xl border border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/60 p-8 shadow-sm">
                <x-empty-state icon="receipt" title="Belum Ada Tagihan" description="Riwayat pembayaran paket tokomu akan muncul di sini." />
            </div>
        @else
            <x-table :pagination="$invoices">
                <x-slot:header>
                    <tr>
                        <x-table.th>Nomor Invoice</x-table.th>
                        <x-table.th>Paket</x-table.th>
                        <x-table.th>Durasi</x-table.th>
                        <x-table.th>Total Tagihan</x-table.th>
                        <x-table.th>Status</x-table.th>
                        <x-table.th align="right">Aksi</x-table.th>
                    </tr>
                </x-slot:header>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60">
                    @foreach ($invoices as $inv)
                        <x-table.tr wire:key="inv-{{ $inv->id }}">
                            <x-table.td data-label="Nomor Invoice">
                                <div class="font-mono text-xs font-semibold text-slate-900 dark:text-white">
                                    {{ $inv->invoice_number }}
                                </div>
                                <div class="text-[11px] font-normal text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $inv->created_at?->translatedFormat('d M Y, H:i') }}
                                </div>
                            </x-table.td>
                            <x-table.td data-label="Paket" class="font-medium text-slate-800 dark:text-slate-200">
                                {{ $inv->planLabel() }}
                            </x-table.td>
                            <x-table.td data-label="Durasi" class="text-slate-700 dark:text-slate-300">
                                {{ $inv->period_months > 0 ? $inv->period_months.' Bulan' : 'Permanen (Lifetime)' }}
                            </x-table.td>
                            <x-table.td data-label="Total Tagihan" class="font-mono text-slate-900 dark:text-slate-100 font-semibold">
                                {{ \App\Support\NumberFormatter::currency($inv->amount) }}
                            </x-table.td>
                            <x-table.td data-label="Status">
                                <x-badge :color="$inv->statusBadgeColor()" size="sm">
                                    {{ $inv->statusLabel() }}
                                </x-badge>
                            </x-table.td>
                            <x-table.td data-label="Aksi" align="right">
                                @if ($inv->isPending())
                                    <div class="flex items-center justify-end gap-2">
                                        <x-primary-button wire:click="payInvoice({{ $inv->id }})" size="xs" class="!rounded-lg font-semibold inline-flex items-center gap-1.5 whitespace-nowrap">
                                            <i data-lucide="qr-code" class="w-3.5 h-3.5 shrink-0 inline-block"></i>
                                            <span>Bayar QRIS</span>
                                        </x-primary-button>
                                        <button wire:click="confirmCancel({{ $inv->id }})" type="button" class="inline-flex items-center gap-1 sm:min-h-[30px] px-2.5 py-1 text-[11px] rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:text-rose-600 dark:hover:text-rose-400 hover:border-rose-300 dark:hover:border-rose-800 hover:bg-rose-50 dark:hover:bg-rose-950/30 font-semibold transition-all whitespace-nowrap shadow-sm">
                                            <i data-lucide="x" class="w-3.5 h-3.5 shrink-0 inline-block"></i>
                                            <span>Batalkan</span>
                                        </button>
                                    </div>
                                @elseif ($inv->isPaid())
                                    <span class="inline-flex items-center justify-end gap-1.5 text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                                        <i data-lucide="check-circle-2" class="w-3.5 h-3.5 shrink-0"></i>
                                        <span>Lunas</span>
                                    </span>
                                @elseif ($inv->isCancelled())
                                    <span class="inline-flex items-center justify-end gap-1.5 text-xs text-slate-400 dark:text-slate-500 font-medium">
                                        <i data-lucide="x-circle" class="w-3.5 h-3.5 shrink-0"></i>
                                        <span>Dibatalkan</span>
                                    </span>
                                @else
                                    <span class="text-xs text-slate-400 dark:text-slate-500">-</span>
                                @endif
                            </x-table.td>
                        </x-table.tr>
                    @endforeach
                </tbody>
            </x-table>
        @endif
    </div>

    {{-- Modal Konfirmasi Batalkan Tagihan --}}
    <x-modal name="confirm-cancel-invoice-modal" max-width="md">
        <div class="p-6">
            <x-modal-header title="Batalkan Tagihan?" icon="alert-triangle" tone="rose" closeable>
                Tautan QRIS untuk tagihan ini akan dinonaktifkan.
            </x-modal-header>

            @if ($this->invoiceToCancel)
                <div class="mt-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 p-4 space-y-2 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Nomor Tagihan:</span>
                        <span class="font-mono font-bold text-slate-900 dark:text-white">{{ $this->invoiceToCancel->invoice_number }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Paket:</span>
                        <span class="font-semibold text-slate-900 dark:text-white">{{ $this->invoiceToCancel->planLabel() }} ({{ $this->invoiceToCancel->period_months > 0 ? $this->invoiceToCancel->period_months.' Bulan' : 'Lifetime' }})</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-200 dark:border-slate-800 pt-2 font-semibold">
                        <span class="text-slate-700 dark:text-slate-300">Total Tagihan:</span>
                        <span class="font-mono text-emerald-600 dark:text-emerald-400 font-bold">{{ \App\Support\NumberFormatter::currency($this->invoiceToCancel->amount) }}</span>
                    </div>
                </div>
            @endif

            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                Apakah Anda yakin ingin membatalkan tagihan ini? Anda dapat membuat tagihan baru kapan saja setelah tagihan ini dibatalkan.
            </p>

            <x-modal-actions class="mt-6">
                <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-cancel-invoice-modal')">
                    Kembali
                </x-secondary-button>
                <x-danger-button wire:click="cancelConfirmedInvoice" wire:loading.attr="disabled">
                    <x-loading-label target="cancelConfirmedInvoice" loading="Membatalkan...">
                        Ya, Batalkan Tagihan
                    </x-loading-label>
                </x-danger-button>
            </x-modal-actions>
        </div>
    </x-modal>

    {{-- Modal Peringatan Tagihan Masih Aktif --}}
    <x-modal name="pending-invoice-exists-modal" max-width="lg">
        <div class="p-6">
            <x-modal-header title="Tagihan Masih Aktif" icon="receipt" tone="amber" closeable>
                Anda masih memiliki tagihan yang belum dibayar untuk paket ini.
            </x-modal-header>

            @if ($this->pendingInvoice)
                <div class="mt-4 rounded-xl border border-amber-300/60 dark:border-amber-500/30 bg-amber-50/60 dark:bg-amber-950/20 p-4 space-y-2.5 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Nomor Tagihan:</span>
                        <span class="font-mono font-bold text-slate-900 dark:text-white">{{ $this->pendingInvoice->invoice_number }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Paket:</span>
                        <span class="font-semibold text-slate-900 dark:text-white">{{ $this->pendingInvoice->planLabel() }} ({{ $this->pendingInvoice->period_months > 0 ? $this->pendingInvoice->period_months.' Bulan' : 'Lifetime' }})</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Tanggal Dibuat:</span>
                        <span class="text-slate-800 dark:text-slate-200">{{ $this->pendingInvoice->created_at?->translatedFormat('d M Y, H:i') }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-amber-200 dark:border-amber-900/60 pt-2 font-semibold">
                        <span class="text-slate-700 dark:text-slate-300">Total Pembayaran:</span>
                        <span class="font-mono text-emerald-600 dark:text-emerald-400 font-bold text-sm">{{ \App\Support\NumberFormatter::currency($this->pendingInvoice->amount) }}</span>
                    </div>
                </div>

                <p class="mt-3.5 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                    Untuk keamanan transaksi dan mencegah pembayaran ganda, silakan <strong>batalkan tagihan sebelumnya</strong> jika ingin membuat tagihan baru, atau langsung lanjutkan pembayaran tagihan yang ada.
                </p>
            @endif

            <x-modal-actions class="mt-6">
                <x-secondary-button x-on:click="$dispatch('close')">
                    Tutup
                </x-secondary-button>
                @if ($this->pendingInvoice)
                    <x-danger-button wire:click="cancelInvoiceFromPendingModal" wire:loading.attr="disabled">
                        <x-loading-label target="cancelInvoiceFromPendingModal" loading="Membatalkan...">
                            Batalkan Tagihan
                        </x-loading-label>
                    </x-danger-button>
                    <x-primary-button wire:click="payInvoice({{ $this->pendingInvoice->id }})" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 whitespace-nowrap">
                        <i data-lucide="qr-code" class="w-4 h-4 shrink-0 inline-block"></i>
                        <span>Bayar QRIS</span>
                    </x-primary-button>
                @endif
            </x-modal-actions>
        </div>
    </x-modal>

    {{-- Modal Error Checkout / Payment Gateway Maintenance --}}
    <x-modal name="checkout-error-modal" max-width="md">
        <div class="p-6">
            <x-modal-header title="Pemberitahuan Pembayaran" icon="alert-circle" />
            <div class="mt-4 text-sm text-slate-600 dark:text-slate-300">
                <p>{{ $checkoutError }}</p>
            </div>
            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close-modal', 'checkout-error-modal')">
                    Tutup
                </x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
