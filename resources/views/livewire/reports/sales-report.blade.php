@php
    use App\Support\NumberFormatter as Num;
    $currentTenant = app(\App\Support\CurrentTenant::class)->get();
@endphp

<div>
@if ($currentTenant && ! $currentTenant->isPro())
    <x-pro-paywall
        title="Laporan Analisis Penjualan & Laba Rugi Khusus Pro"
        feature="Laporan Penjualan & Laba Rugi"
        description="Analisis performa omzet, estimasi laba kotor, produk terlaris, rekap metode pembayaran, dan ekspor data komprehensif dengan paket Pro."
        icon="trending-up"
    />
@else
<div class="space-y-5 sm:space-y-6">
    {{-- Baris Filter & Preset Waktu --}}
    <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-3 bg-slate-900/60 p-3 sm:p-4 rounded-xl border border-slate-800">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center bg-slate-950 border border-slate-800 rounded-lg h-10 sm:h-[38px] px-2 shadow-inner">
                <i data-lucide="calendar" class="w-4 h-4 text-slate-400 me-2 shrink-0"></i>
                <input
                    type="date"
                    wire:model.live="from"
                    aria-label="Dari tanggal"
                    class="bg-transparent border-0 text-slate-200 text-xs focus:ring-1 focus:ring-emerald-500 rounded w-[8.25rem] sm:w-[8.75rem] px-1 py-1"
                >
                <span class="text-slate-500 text-xs px-1 shrink-0">–</span>
                <input
                    type="date"
                    wire:model.live="to"
                    aria-label="Sampai tanggal"
                    class="bg-transparent border-0 text-slate-200 text-xs focus:ring-1 focus:ring-emerald-500 rounded w-[8.25rem] sm:w-[8.75rem] px-1 py-1"
                >
            </div>

            {{-- Preset Cepat Rentang Waktu --}}
            <x-segmented class="overflow-x-auto max-w-full">
                <x-tab-button size="sm" :active="$from === now()->toDateString() && $to === $from" wire:click="presetToday">Hari Ini</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->subDay()->toDateString() && $to === $from" wire:click="presetYesterday">Kemarin</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->subDays(6)->toDateString() && $to === now()->toDateString()" wire:click="presetLast7Days">7 Hari</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->subDays(29)->toDateString() && $to === now()->toDateString()" wire:click="presetLast30Days">30 Hari</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->startOfMonth()->toDateString() && $to === now()->toDateString()" wire:click="presetThisMonth">Bulan Ini</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->subMonthNoOverflow()->startOfMonth()->toDateString() && $to === now()->subMonthNoOverflow()->endOfMonth()->toDateString()" wire:click="presetLastMonth">Bulan Lalu</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->startOfYear()->toDateString() && $to === now()->toDateString()" wire:click="presetThisYear">Tahun Ini</x-tab-button>
            </x-segmented>
        </div>

        {{-- Aksi Ekspor & Cetak --}}
        <div class="flex items-center gap-2 self-end xl:self-auto shrink-0">
            <x-table.export-button action="export" label="Ekspor Data" />
            <button
                type="button"
                x-on:click="window.print()"
                class="inline-flex items-center gap-1.5 h-10 sm:h-[38px] px-3 text-xs font-semibold rounded-lg border border-slate-700/80 hover:border-slate-600 bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-white transition shadow-sm cursor-pointer"
                title="Cetak ringkasan halaman laporan ini"
            >
                <i data-lucide="printer" class="w-3.5 h-3.5 text-slate-400"></i>
                <span class="hidden sm:inline">Cetak</span>
            </button>
        </div>
    </div>

    {{-- Filter Dimensi Tambahan (Kasir, Metode Pembayaran, Status) --}}
    <div class="flex flex-wrap items-center gap-2.5 pt-0.5">
        <div class="w-full sm:w-44">
            <x-select variant="filter" wire:model.live="cashierId" aria-label="Filter kasir" class="w-full">
                <option value="">Semua Kasir</option>
                @foreach ($cashiers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </x-select>
        </div>

        <div class="w-full sm:w-44">
            <x-select variant="filter" wire:model.live="paymentMethod" aria-label="Filter metode pembayaran" class="w-full">
                <option value="">Semua Metode</option>
                @foreach (\App\Enums\PaymentMethod::cases() as $pm)
                    <option value="{{ $pm->value }}">{{ $pm->label() }}</option>
                @endforeach
            </x-select>
        </div>

        <div class="w-full sm:w-44">
            <x-select variant="filter" wire:model.live="status" aria-label="Filter status transaksi" class="w-full">
                <option value="">Semua Status</option>
                <option value="completed">Lunas / Selesai</option>
                <option value="due">Kasbon (Ada Piutang)</option>
                <option value="voided">Dibatalkan (Void)</option>
            </x-select>
        </div>

        @if ($cashierId !== '' || $paymentMethod !== '' || $status !== '')
            <button
                type="button"
                wire:click="resetFilters"
                class="inline-flex items-center gap-1 text-xs text-amber-400 hover:text-amber-300 px-2.5 py-1.5 rounded-lg border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 transition cursor-pointer"
            >
                <i data-lucide="filter-x" class="w-3.5 h-3.5"></i>
                <span>Reset Filter</span>
            </button>
        @endif
    </div>

    {{-- Panel Fokal Eksekutif: Diagnosis Finansial Bisnis untuk Owner --}}
    <div class="rounded-2xl bg-gradient-to-br from-slate-900 via-slate-900 to-slate-800 border border-slate-700/60 shadow-xl shadow-slate-950/40 p-4 sm:p-6 space-y-4">
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 divide-y sm:divide-y-0 divide-slate-800/80">
            {{-- KPI 1: Total Omzet --}}
            <div class="pt-3 sm:pt-0">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Omzet</p>
                    <x-growth-badge :value="$totals['revenue_growth']" title="Dibandingkan rentang hari sebelumnya" />
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-50 tabular-nums tracking-tight mt-1.5">
                    {{ Num::currency($totals['revenue']) }}
                </p>
                <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                    <strong class="text-slate-200">{{ Num::quantity($totals['count']) }}</strong> transaksi · <strong class="text-slate-200">{{ Num::quantity($totals['items']) }}</strong> barang terjual
                </p>
            </div>

            {{-- KPI 2: Laba Kotor & Margin --}}
            <div class="pt-3 sm:pt-0">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Laba Kotor (Gross Profit)</p>
                    <x-growth-badge :value="$totals['profit_growth']" title="Dibandingkan rentang hari sebelumnya" />
                </div>
                <p @class([
                    'text-2xl sm:text-3xl font-extrabold tabular-nums tracking-tight mt-1.5',
                    'text-emerald-600 dark:text-emerald-400' => $totals['profit'] >= 0,
                    'text-rose-600 dark:text-rose-400' => $totals['profit'] < 0,
                ])>
                    {{ Num::currency($totals['profit']) }}
                </p>
                <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                    Margin <strong class="text-emerald-600 dark:text-emerald-400">{{ Num::quantity($totals['margin']) }}%</strong> · HPP Modal <strong class="text-slate-300">{{ Num::currency($totals['cogs']) }}</strong>
                </p>
            </div>

            {{-- KPI 3: Realisasi Kas Masuk vs Kasbon --}}
            <div class="pt-3 sm:pt-0">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Realisasi Kas Diterima</p>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">Uang Masuk</span>
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-100 tabular-nums tracking-tight mt-1.5">
                    {{ Num::currency($totals['paid']) }}
                </p>
                <p class="text-xs mt-1.5 leading-relaxed {{ $totals['due'] > 0 ? 'text-amber-400' : 'text-slate-400' }}">
                    @if ($totals['due'] > 0)
                        <span>Tertahan kasbon: <strong>{{ Num::currency($totals['due']) }}</strong> ({{ $totals['due_count'] }} nota)</span>
                    @else
                        <span>Seluruh transaksi periode ini lunas</span>
                    @endif
                </p>
            </div>

            {{-- KPI 4: Rata-rata Keranjang (Basket AOV) & Diskon --}}
            <div class="pt-3 sm:pt-0">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Rata-rata Transaksi (AOV)</p>
                    <x-growth-badge :value="$totals['count_growth']" title="Pertumbuhan frekuensi transaksi" />
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-100 tabular-nums tracking-tight mt-1.5">
                    {{ Num::currency($totals['average']) }}
                </p>
                <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                    Diskon <strong class="text-slate-300">{{ Num::currency($totals['discount']) }}</strong> ({{ $totals['discount_rate'] }}%) · Rata-rata <strong class="text-slate-300">{{ $totals['items_per_transaction'] }}</strong> item/nota
                </p>
            </div>
        </div>

        {{-- Strip Informasi Pajak & Peringatan Risiko Transaksi Dibatalkan --}}
        <div class="pt-3 border-t border-slate-700/60 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 text-xs text-slate-400">
            <div>
                @if ($totals['tax'] > 0)
                    <span>Omzet sudah termasuk penerimaan pajak sebesar <strong class="text-slate-200">{{ Num::currency($totals['tax']) }}</strong> (laba kotor dihitung tanpa pajak).</span>
                @else
                    <span>Laba kotor dihitung dari penjualan bersih dikurangi harga pokok modal barang terjual (HPP).</span>
                @endif
            </div>

            @if ($totals['voided'] > 0)
                <div class="flex items-center gap-2 bg-rose-950/40 border border-rose-500/30 text-rose-300 px-3 py-1.5 rounded-lg text-xs">
                    <i data-lucide="shield-alert" class="w-4 h-4 text-rose-400 shrink-0"></i>
                    <span>Terdapat <strong>{{ $totals['voided'] }} transaksi dibatalkan</strong> ({{ Num::currency($totals['voided_total']) }} · Void Rate {{ $totals['void_rate'] }}%)</span>
                    <button
                        type="button"
                        wire:click="$set('activeTab', 'transactions'); $set('status', 'voided')"
                        class="text-xs font-semibold text-rose-300 hover:text-white underline underline-offset-2 ml-1"
                    >
                        Audit
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- Navigasi Tab Komprehensif --}}
    <div class="border-b border-slate-800 pb-1">
        <div class="flex items-center gap-1.5 overflow-x-auto custom-scrollbar pb-1">
            <x-tab-button size="md" icon="bar-chart-3" :active="$activeTab === 'overview'" wire:click="setTab('overview')">
                Ikhtisar &amp; Tren
            </x-tab-button>
            <x-tab-button size="md" icon="calendar" :active="$activeTab === 'daily'" wire:click="setTab('daily')">
                Rekap Harian
            </x-tab-button>
            <x-tab-button size="md" icon="receipt" :active="$activeTab === 'transactions'" wire:click="setTab('transactions')" :count="$totals['count']">
                Rincian Transaksi
            </x-tab-button>
            <x-tab-button size="md" icon="package" :active="$activeTab === 'products'" wire:click="setTab('products')">
                Analisis Produk
            </x-tab-button>
        </div>
    </div>

    {{-- TAB 1: IKHTISAR & TREN (OVERVIEW) --}}
    @if ($activeTab === 'overview')
        <div class="grid lg:grid-cols-12 gap-4 sm:gap-6 items-start">
            {{-- ==================== KOLOM KIRI (7/12 di Desktop, 8/12 di XL) ==================== --}}
            <div class="lg:col-span-7 xl:col-span-8 space-y-4 sm:space-y-6">
                {{-- 1. Grafik Tren Penjualan & Laba --}}
                <x-dashboard.panel icon="trending-up">
                    <x-slot:title>
                        <div class="flex flex-wrap items-center justify-between gap-2 w-full">
                            <span class="text-sm font-bold text-slate-100">
                                {{ $chartDaily ? 'Tren Penjualan Harian' : 'Tren Penjualan Bulanan' }}
                            </span>
                            <div class="flex items-center gap-1">
                                <x-segmented>
                                    <x-tab-button size="sm" :active="$chartMetric === 'revenue'" wire:click="setChartMetric('revenue')">Omzet</x-tab-button>
                                    <x-tab-button size="sm" :active="$chartMetric === 'profit'" wire:click="setChartMetric('profit')">Laba Kotor</x-tab-button>
                                    <x-tab-button size="sm" :active="$chartMetric === 'count'" wire:click="setChartMetric('count')">Transaksi</x-tab-button>
                                </x-segmented>
                            </div>
                        </div>
                    </x-slot:title>

                    @if ($totals['count'] === 0)
                        <x-empty-state icon="bar-chart-3" title="Belum ada penjualan di periode ini" description="Pilih rentang tanggal lain untuk memuat grafik." />
                    @else
                        <div class="overflow-x-auto custom-scrollbar">
                            <div @class(['min-w-[34rem]' => count($chart) > 12])>
                                <x-bar-chart
                                    :data="$chart"
                                    :type="$chartMetric === 'count' ? 'number' : 'currency'"
                                    :color="$chartMetric === 'profit' ? 'sky' : 'emerald'"
                                />
                            </div>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between text-[11px] text-slate-400 pt-3 border-t border-slate-800/80 mt-2 gap-1">
                            <span>Menampilkan grafik berdasarkan <strong>{{ $chartMetric === 'profit' ? 'Laba Kotor' : ($chartMetric === 'count' ? 'Jumlah Transaksi' : 'Total Omzet') }}</strong>.</span>
                            <span>Rentang: {{ \Carbon\Carbon::parse($from)->translatedFormat('d M Y') }} s/d {{ \Carbon\Carbon::parse($to)->translatedFormat('d M Y') }}</span>
                        </div>
                    @endif
                </x-dashboard.panel>

                {{-- 2. Top Produk Unggulan (Leaderboard Rank Card yang padat & estetik saat data sedikit maupun banyak) --}}
                <x-dashboard.panel icon="award">
                    <x-slot:title>
                        <div class="flex flex-wrap items-center justify-between gap-2 w-full">
                            <div>
                                <span class="text-sm font-bold text-slate-100">Top Produk Unggulan</span>
                            </div>
                            <x-segmented>
                                <x-tab-button size="sm" :active="$topProductMetric === 'revenue'" wire:click="setTopProductMetric('revenue')">Omzet</x-tab-button>
                                <x-tab-button size="sm" :active="$topProductMetric === 'qty'" wire:click="setTopProductMetric('qty')">Volume</x-tab-button>
                                <x-tab-button size="sm" :active="$topProductMetric === 'profit'" wire:click="setTopProductMetric('profit')">Laba</x-tab-button>
                            </x-segmented>
                        </div>
                    </x-slot:title>

                    @if ($topProducts->isEmpty())
                        <x-empty-state icon="package" title="Belum ada produk terjual" description="Produk yang terjual di periode ini akan diperingkatkan di sini." />
                    @else
                        @php
                            $maxTopVal = max(1, match($topProductMetric) {
                                'profit' => $topProducts->max('profit'),
                                'qty' => $topProducts->max('qty'),
                                default => $topProducts->max('revenue'),
                            });
                        @endphp
                        <div class="divide-y divide-slate-800/60">
                            @foreach ($topProducts as $index => $row)
                                @php
                                    $currVal = match($topProductMetric) {
                                        'profit' => $row->profit,
                                        'qty' => $row->qty,
                                        default => $row->revenue,
                                    };
                                    $percent = $maxTopVal > 0 ? min(100, max(6, round(($currVal / $maxTopVal) * 100))) : 0;
                                    $rank = $index + 1;
                                @endphp
                                <div class="py-3.5 first:pt-1 last:pb-1 group transition">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            {{-- Rank Badge --}}
                                            <span @class([
                                                'w-6 h-6 rounded-lg text-xs font-black flex items-center justify-center shrink-0 tabular-nums shadow-xs',
                                                'bg-amber-400/20 text-amber-300 border border-amber-400/30' => $rank === 1,
                                                'bg-slate-300/20 text-slate-200 border border-slate-300/30' => $rank === 2,
                                                'bg-amber-700/25 text-amber-500 border border-amber-600/30' => $rank === 3,
                                                'bg-slate-800/80 text-slate-400 border border-slate-700/60' => $rank > 3,
                                            ])>
                                                {{ $rank }}
                                            </span>

                                            <div class="min-w-0">
                                                <p class="text-xs sm:text-sm font-semibold text-slate-100 truncate group-hover:text-emerald-400 transition">
                                                    {{ $row->product_name }}
                                                </p>
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-slate-400 mt-0.5">
                                                    <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-medium bg-slate-800 border border-slate-700/60 text-slate-300">
                                                        {{ $row->category_name }}
                                                    </span>
                                                    <span>{{ Num::quantity($row->qty) }} {{ $row->unit }}</span>
                                                    <span>·</span>
                                                    <span class="text-emerald-400 font-medium">Laba {{ Num::currency($row->profit) }} ({{ Num::quantity($row->margin) }}%)</span>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Nilai Finansial --}}
                                        <div class="text-right shrink-0">
                                            <p class="text-xs sm:text-sm font-bold text-slate-100 tabular-nums">
                                                {{ Num::currency($row->revenue) }}
                                            </p>
                                            <p class="text-[10px] text-slate-400 tabular-nums mt-0.5">
                                                HPP {{ Num::currency($row->cogs) }}
                                            </p>
                                        </div>
                                    </div>

                                    {{-- Visual Bar Kontribusi --}}
                                    <div class="mt-2.5 h-1.5 rounded-full bg-slate-800/90 overflow-hidden">
                                        <div
                                            @class([
                                                'h-full rounded-full transition-all duration-300',
                                                'bg-gradient-to-r from-amber-500 via-emerald-500 to-emerald-400' => $rank === 1,
                                                'bg-gradient-to-r from-emerald-600 to-emerald-400' => $rank > 1,
                                            ])
                                            style="width: {{ $percent }}%;"
                                        ></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center justify-between text-[11px] text-slate-400 pt-3 border-t border-slate-800/80 mt-3 gap-2">
                            <span>Diurutkan berdasarkan <strong>{{ $topProductMetric === 'profit' ? 'Laba Kotor Terbesar' : ($topProductMetric === 'qty' ? 'Kuantitas Terjual Terbanyak' : 'Omzet Terbesar') }}</strong>.</span>
                            <button type="button" wire:click="setTab('products')" class="text-emerald-400 hover:text-emerald-300 font-semibold hover:underline inline-flex items-center gap-1 self-start sm:self-auto cursor-pointer">
                                <span>Lihat analisis lengkap &amp; HPP seluruh produk</span>
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    @endif
                </x-dashboard.panel>
            </div>

            {{-- ==================== KOLOM KANAN (5/12 di Desktop, 4/12 di XL) ==================== --}}
            <div class="lg:col-span-5 xl:col-span-4 space-y-4 sm:space-y-6">
                {{-- 1. Pola Jam Ramai (Peak Hours) --}}
                <x-dashboard.panel title="Pola Jam Ramai (Peak Hours)" icon="clock">
                    @if ($totals['count'] === 0)
                        <p class="text-xs text-slate-400 py-8 text-center">Belum ada data transaksi.</p>
                    @else
                        <div class="space-y-4">
                            @if ($peakHour && $peakHour['count'] > 0)
                                <div class="p-3 bg-emerald-500/10 border border-emerald-500/25 rounded-xl flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                                            <i data-lucide="flame" class="w-4 h-4"></i>
                                        </div>
                                        <div>
                                            <p class="text-[11px] text-emerald-700 dark:text-emerald-300 font-semibold uppercase tracking-wider">Jam Tersibuk</p>
                                            <p class="text-sm font-bold text-slate-100">{{ $peakHour['label'] }} – {{ sprintf('%02d:00', (int)$peakHour['hour'] + 1) }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">{{ $peakHour['count'] }} transaksi</p>
                                        <p class="text-[10px] text-slate-400 tabular-nums">{{ Num::currency($peakHour['revenue']) }}</p>
                                    </div>
                                </div>
                            @endif

                            {{-- Grafik Mini Sebaran Transaksi per Jam --}}
                            <div class="space-y-1.5">
                                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Sebaran Transaksi (07:00 – 22:00)</p>
                                @php
                                    $maxHourlyCount = max(1, $hourlySales->max('count'));
                                @endphp
                                <div class="grid grid-cols-8 gap-1 pt-1">
                                    @foreach ($hourlySales as $h)
                                        @php
                                            $hHeight = $h['count'] > 0 ? max(12, round(($h['count'] / $maxHourlyCount) * 100)) : 4;
                                        @endphp
                                        <div class="flex flex-col items-center gap-1 group relative cursor-default" title="{{ $h['label'] }}: {{ $h['count'] }} transaksi ({{ Num::currency($h['revenue']) }})">
                                            <div class="w-full bg-slate-800/80 rounded h-14 flex items-end p-0.5 overflow-hidden">
                                                <div
                                                    @class([
                                                        'w-full rounded transition-all duration-200',
                                                        'bg-emerald-500 group-hover:bg-emerald-400' => $h['is_peak'],
                                                        'bg-slate-600 group-hover:bg-slate-500' => ! $h['is_peak'] && $h['count'] > 0,
                                                        'bg-slate-800' => $h['count'] === 0,
                                                    ])
                                                    style="height: {{ $hHeight }}%;"
                                                ></div>
                                            </div>
                                            <span class="text-[9px] text-slate-400 tabular-nums font-mono">{{ $h['hour'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <p class="text-[11px] text-slate-400 leading-relaxed pt-1 border-t border-slate-800/60">
                                Pola jam ramai membantu owner mengatur jadwal pergantian shift kasir dan pengisian stok etalase sebelum waktu puncak.
                            </p>
                        </div>
                    @endif
                </x-dashboard.panel>

                {{-- 2. Metode Pembayaran Diterima --}}
                <x-dashboard.panel title="Metode Pembayaran Diterima" icon="wallet">
                    @if ($payments->isEmpty())
                        <p class="text-xs text-slate-400 py-6 text-center">Belum ada pembayaran.</p>
                    @else
                        @php $paymentTotal = max(1, $payments->sum('total')); @endphp
                        <ul class="space-y-3">
                            @foreach ($payments as $row)
                                @php $sharePercent = round(($row['total'] / $paymentTotal) * 100, 1); @endphp
                                <li class="space-y-1">
                                    <div class="flex items-center justify-between text-xs gap-2">
                                        <span class="flex items-center gap-2 text-slate-200 font-medium">
                                            <i data-lucide="{{ $row['method']->icon() }}" class="w-3.5 h-3.5 text-emerald-400"></i>
                                            {{ $row['method']->label() }}
                                        </span>
                                        <span class="tabular-nums font-bold text-slate-100">{{ Num::currency($row['total']) }}</span>
                                    </div>
                                    <div class="h-1.5 rounded-full bg-slate-800 overflow-hidden">
                                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ $sharePercent }}%"></div>
                                    </div>
                                    <div class="flex items-center justify-between text-[10px] text-slate-400">
                                        <span>{{ $row['count'] }} kali transaksi</span>
                                        <span class="font-semibold text-slate-300">{{ $sharePercent }}%</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <p class="text-[10px] text-slate-400 pt-2 border-t border-slate-800/80 mt-2">
                            Mencakup seluruh pembayaran tunai, QRIS, transfer, kartu, serta pelunasan kasbon di periode ini.
                        </p>
                    @endif
                </x-dashboard.panel>

                {{-- 3. Kontribusi Kategori Produk --}}
                <x-dashboard.panel title="Kontribusi Kategori Produk" icon="tags">
                    @if ($byCategory->isEmpty())
                        <p class="text-xs text-slate-400 py-6 text-center">Belum ada data kategori.</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($byCategory as $row)
                                @php $catShare = round(($row->revenue / $categoryTotal) * 100, 1); @endphp
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between text-xs gap-2">
                                        <span class="text-slate-200 font-medium truncate">{{ $row->name }}</span>
                                        <span class="tabular-nums text-slate-100 font-bold">{{ Num::currency($row->revenue) }}</span>
                                    </div>
                                    <div class="h-1.5 rounded-full bg-slate-800 overflow-hidden">
                                        <div class="h-full rounded-full bg-sky-500" style="width: {{ $catShare }}%"></div>
                                    </div>
                                    <div class="flex items-center justify-between text-[10px] text-slate-400">
                                        <span>{{ Num::quantity($row->qty) }} barang terjual</span>
                                        <span class="text-slate-300 font-semibold">{{ $catShare }}% omzet</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.panel>

                {{-- 4. Kasir & Loyalitas Pelanggan --}}
                <x-dashboard.panel title="Kasir & Loyalitas Pelanggan" icon="users">
                    <div class="space-y-4">
                        {{-- Kinerja Kasir --}}
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-2">Penjualan per Kasir</p>
                            @forelse ($byCashier as $row)
                                <div class="flex items-center justify-between text-xs py-1.5 border-b border-slate-800/60 last:border-0">
                                    <div class="min-w-0 pr-2">
                                        <p class="font-medium text-slate-200 truncate">{{ $row->name }}</p>
                                        <p class="text-[10px] text-slate-400">{{ $row->count }} nota · avg {{ Num::currency($row->average) }}</p>
                                    </div>
                                    <span class="tabular-nums text-slate-100 font-bold shrink-0">{{ Num::currency($row->revenue) }}</span>
                                </div>
                            @empty
                                <p class="text-xs text-slate-400 py-2">Belum ada data kasir.</p>
                            @endforelse
                        </div>

                        {{-- Member vs Pelanggan Umum --}}
                        <div class="pt-2 border-t border-slate-800/80 space-y-1.5">
                            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Kontribusi Pelanggan</p>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-slate-300">Member Terdaftar ({{ $customerSegments['member_count'] }} trx)</span>
                                <span class="tabular-nums font-bold text-emerald-400">{{ Num::currency($customerSegments['member_revenue']) }} ({{ $customerSegments['member_percent'] }}%)</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-slate-800 overflow-hidden flex">
                                <div class="h-full bg-emerald-500" style="width: {{ $customerSegments['member_percent'] }}%"></div>
                                <div class="h-full bg-slate-600" style="width: {{ $customerSegments['general_percent'] }}%"></div>
                            </div>
                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                <span>Pembeli Umum (Walk-in): {{ Num::currency($customerSegments['general_revenue']) }}</span>
                                <span>{{ $customerSegments['general_percent'] }}%</span>
                            </div>
                        </div>
                    </div>
                </x-dashboard.panel>
            </div>
        </div>
    @endif

    {{-- TAB 2: REKAP HARIAN (DAILY SALES LOG) --}}
    @if ($activeTab === 'daily')
        <div class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-slate-100">Buku Rekap Penjualan Harian</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Ringkasan transaksi, HPP modal, laba kotor, dan margin tanggal per tanggal</p>
                </div>
                <x-table.export-button action="export" label="Unduh Rekap Harian" />
            </div>

            @if ($dailyTotals->isEmpty())
                <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
                    <x-empty-state icon="calendar" title="Belum ada transaksi harian" description="Pilih rentang tanggal lain." />
                </div>
            @else
                <x-table>
                    <x-slot:header>
                        <tr>
                            <x-table.th>Tanggal &amp; Hari</x-table.th>
                            <x-table.th align="right">Transaksi</x-table.th>
                            <x-table.th align="right">Barang Terjual</x-table.th>
                            <x-table.th align="right">Diskon</x-table.th>
                            <x-table.th align="right">Pajak</x-table.th>
                            <x-table.th align="right">HPP Modal</x-table.th>
                            <x-table.th align="right">Laba Kotor</x-table.th>
                            <x-table.th align="right">Margin</x-table.th>
                            <x-table.th align="right">Omzet</x-table.th>
                        </tr>
                    </x-slot:header>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($dailyTotals as $day)
                            <x-table.tr wire:key="daily-{{ $day->day }}">
                                <x-table.td class="font-medium text-slate-100">
                                    <div>{{ \Carbon\Carbon::parse($day->day)->translatedFormat('d M Y') }}</div>
                                    <div class="text-[11px] text-slate-400 font-normal">{{ \Carbon\Carbon::parse($day->day)->translatedFormat('l') }}</div>
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums font-semibold text-slate-200">
                                    {{ Num::quantity($day->count) }}
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-slate-300">
                                    {{ Num::quantity($day->qty) }}
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-slate-400 text-xs">
                                    {{ Num::currency($day->discount) }}
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-slate-400 text-xs">
                                    {{ Num::currency($day->tax) }}
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-slate-300 text-xs">
                                    {{ Num::currency($day->cogs) }}
                                </x-table.td>
                                <x-table.td align="right" @class([
                                    'tabular-nums font-bold',
                                    'text-emerald-400' => $day->profit >= 0,
                                    'text-rose-400' => $day->profit < 0,
                                ])>
                                    {{ Num::currency($day->profit) }}
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-xs font-semibold text-slate-300">
                                    {{ Num::quantity($day->margin) }}%
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums font-bold text-slate-100">
                                    {{ Num::currency($day->total) }}
                                </x-table.td>
                            </x-table.tr>
                        @endforeach
                    </tbody>
                    {{-- Baris Total Keseluruhan --}}
                    <tfoot>
                        <tr class="bg-slate-900 border-t-2 border-slate-700/80 font-bold text-xs text-slate-100">
                            <td class="px-4 py-3.5 text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">TOTAL KESELURUHAN</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-100">{{ Num::quantity($dailySummary['count']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-100">{{ Num::quantity($dailySummary['qty']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-300">{{ Num::currency($dailySummary['discount']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-300">{{ Num::currency($dailySummary['tax']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-300">{{ Num::currency($dailySummary['cogs']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400 text-sm font-extrabold">{{ Num::currency($dailySummary['profit']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ Num::quantity($dailySummary['margin']) }}%</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400 text-sm font-extrabold">{{ Num::currency($dailySummary['total']) }}</td>
                        </tr>
                    </tfoot>
                </x-table>
            @endif
        </div>
    @endif

    {{-- TAB 3: RINCIAN TRANSAKSI (TRANSACTIONS AUDIT) --}}
    @if ($activeTab === 'transactions')
        <div class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex-1 max-w-md">
                    <x-search-input wire:model.live.debounce.400ms="search" placeholder="Cari no. nota, pelanggan, atau nama produk..." />
                </div>
                <div class="flex items-center gap-2">
                    <x-table.export-button action="export" label="Unduh Transaksi" />
                </div>
            </div>

            @if (! $transactions || $transactions->isEmpty())
                <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
                    <x-empty-state
                        icon="receipt"
                        :title="$search || $status || $cashierId || $paymentMethod ? 'Tidak ada transaksi yang cocok' : 'Belum ada transaksi di periode ini'"
                        description="Ubah kata kunci pencarian atau sesuaikan filter di atas."
                    />
                </div>
            @else
                <x-table :pagination="$transactions">
                    <x-slot:header>
                        <tr>
                            <x-table.th>No. Nota</x-table.th>
                            <x-table.th>Waktu &amp; Kasir</x-table.th>
                            <x-table.th>Pelanggan</x-table.th>
                            <x-table.th>Metode Bayar</x-table.th>
                            <x-table.th align="right">Total Transaksi</x-table.th>
                            <x-table.th align="right">Estimasi Laba</x-table.th>
                            <x-table.th>Status</x-table.th>
                            <x-table.th align="right">Aksi</x-table.th>
                        </tr>
                    </x-slot:header>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($transactions as $sale)
                            @php
                                $saleCogs = (int) $sale->items->sum(fn ($i) => (float) $i->cost_price * (float) $i->quantity);
                                $saleProfit = ($sale->total - $sale->tax_amount) - $saleCogs;
                            @endphp
                            <x-table.tr wire:key="trx-{{ $sale->id }}">
                                <x-table.td>
                                    <button
                                        type="button"
                                        wire:click="openSaleModal({{ $sale->id }})"
                                        class="font-mono font-bold text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 hover:underline cursor-pointer"
                                        title="Buka rincian lengkap transaksi"
                                    >
                                        {{ $sale->number }}
                                    </button>
                                </x-table.td>
                                <x-table.td>
                                    <div class="text-xs font-medium text-slate-200">{{ $sale->sold_at->translatedFormat('d M Y, H:i') }}</div>
                                    <div class="text-[11px] text-slate-400">Kasir: {{ $sale->cashier?->name ?? '-' }}</div>
                                </x-table.td>
                                <x-table.td class="text-slate-300">
                                    <div class="font-medium text-slate-200">{{ $sale->customer?->name ?? 'Pelanggan Umum' }}</div>
                                    <div class="text-[11px] text-slate-400">{{ $sale->items->count() }} jenis barang</div>
                                </x-table.td>
                                <x-table.td>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($sale->payments->pluck('method')->unique() as $m)
                                            <x-badge color="slate">{{ strtoupper($m->shortLabel()) }}</x-badge>
                                        @empty
                                            <span class="text-slate-400 text-xs">-</span>
                                        @endforelse
                                    </div>
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums">
                                    <span @class(['font-bold', 'text-slate-100' => ! $sale->isVoided(), 'text-slate-500 line-through' => $sale->isVoided()])>
                                        {{ Num::currency($sale->total) }}
                                    </span>
                                    @if ($sale->due_amount > 0 && ! $sale->isVoided())
                                        <div class="text-[10px] text-amber-400 font-medium">Sisa kasbon: {{ Num::currency($sale->due_amount) }}</div>
                                    @endif
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums">
                                    @if ($sale->isVoided())
                                        <span class="text-slate-500 text-xs">-</span>
                                    @else
                                        <span @class(['font-semibold text-xs', 'text-emerald-600 dark:text-emerald-400' => $saleProfit >= 0, 'text-rose-600 dark:text-rose-400' => $saleProfit < 0])>
                                            {{ Num::currency($saleProfit) }}
                                        </span>
                                    @endif
                                </x-table.td>
                                <x-table.td>
                                    @if ($sale->isVoided())
                                        <x-badge color="rose">DIBATALKAN</x-badge>
                                    @elseif ($sale->due_amount > 0)
                                        <x-badge color="amber">KASBON</x-badge>
                                    @else
                                        <x-badge color="emerald">LUNAS</x-badge>
                                    @endif
                                </x-table.td>
                                <x-table.td align="right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            type="button"
                                            wire:click="openSaleModal({{ $sale->id }})"
                                            title="Lihat rincian transaksi"
                                            aria-label="Lihat rincian transaksi {{ $sale->number }}"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800 transition"
                                        >
                                            <i data-lucide="eye" class="w-4 h-4"></i>
                                        </button>
                                        <a
                                            href="{{ route('pos.receipt', $sale) }}"
                                            target="_blank"
                                            x-on:click.prevent="window.open($el.href, '_blank', 'width=420,height=600')"
                                            title="Cetak struk nota"
                                            aria-label="Cetak struk nota {{ $sale->number }}"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800 transition"
                                        >
                                            <i data-lucide="printer" class="w-4 h-4"></i>
                                        </a>
                                    </div>
                                </x-table.td>
                            </x-table.tr>
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </div>
    @endif

    {{-- TAB 4: ANALISIS PRODUK (PRODUCT PERFORMANCE) --}}
    @if ($activeTab === 'products')
        <div class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex-1 max-w-md">
                    <x-search-input wire:model.live.debounce.400ms="productSearch" placeholder="Cari nama produk, SKU, atau kategori..." />
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5 text-xs text-slate-400">
                        <span>Urutkan:</span>
                        <x-select variant="filter" wire:model.live="productSort" aria-label="Urutkan produk" class="w-36">
                            <option value="revenue">Omzet Terbesar</option>
                            <option value="qty">Kuantitas Terjual</option>
                            <option value="profit">Laba Kotor Terbesar</option>
                            <option value="margin">Margin Tertinggi</option>
                            <option value="name">Nama Produk</option>
                        </x-select>
                    </div>
                    <x-table.export-button action="export" label="Unduh Analisis" />
                </div>
            </div>

            @if (! $products || $products->isEmpty())
                <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
                    <x-empty-state
                        icon="package"
                        :title="$productSearch ? 'Tidak ada produk yang cocok' : 'Belum ada data produk terjual'"
                        description="Sesuaikan kata kunci pencarian atau rentang tanggal."
                    />
                </div>
            @else
                @php
                    $totalRevAll = max(1, $productsSummary['revenue']);
                @endphp
                <x-table>
                    <x-slot:header>
                        <tr>
                            <x-table.th>SKU</x-table.th>
                            <x-table.th>Nama Produk &amp; Kategori</x-table.th>
                            <x-table.th align="right">Terjual</x-table.th>
                            <x-table.th align="right">Harga Rata-rata</x-table.th>
                            <x-table.th align="right">Total Omzet</x-table.th>
                            <x-table.th align="right">HPP Modal</x-table.th>
                            <x-table.th align="right">Laba Kotor</x-table.th>
                            <x-table.th align="right">Margin</x-table.th>
                            <x-table.th align="right">Pangsa Omzet</x-table.th>
                        </tr>
                    </x-slot:header>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($products as $p)
                            @php
                                $share = round(($p->revenue / $totalRevAll) * 100, 1);
                            @endphp
                            <x-table.tr wire:key="prod-{{ $p->product_id }}">
                                <x-table.td class="text-slate-400 font-mono text-xs">{{ $p->sku ?: '-' }}</x-table.td>
                                <x-table.td>
                                    <div class="font-medium text-slate-100">{{ $p->product_name }}</div>
                                    <div class="text-[11px] text-slate-400">{{ $p->category_name }}</div>
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums font-semibold text-slate-200">
                                    {{ Num::quantity($p->qty) }} <span class="text-slate-400 font-normal text-xs">{{ $p->unit }}</span>
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-slate-400 text-xs">
                                    {{ Num::currency($p->avg_price) }}
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums font-bold text-slate-100">
                                    {{ Num::currency($p->revenue) }}
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-slate-400 text-xs">
                                    {{ Num::currency($p->cogs) }}
                                </x-table.td>
                                <x-table.td align="right" @class([
                                    'tabular-nums font-bold',
                                    'text-emerald-400' => $p->profit >= 0,
                                    'text-rose-400' => $p->profit < 0,
                                ])>
                                    {{ Num::currency($p->profit) }}
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-xs font-semibold text-slate-300">
                                    {{ Num::quantity($p->margin) }}%
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-xs font-semibold text-slate-300">
                                    {{ $share }}%
                                </x-table.td>
                            </x-table.tr>
                        @endforeach
                    </tbody>
                    {{-- Footer Total Analisis Produk --}}
                    <tfoot>
                        <tr class="bg-slate-900 border-t-2 border-slate-700/80 font-bold text-xs text-slate-100">
                            <td colspan="2" class="px-4 py-3.5 text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">TOTAL SELURUH PRODUK</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-100">{{ Num::quantity($productsSummary['qty']) }}</td>
                            <td class="px-4 py-3.5 text-right text-slate-400">-</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400 text-sm font-extrabold">{{ Num::currency($productsSummary['revenue']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-300">{{ Num::currency($productsSummary['cogs']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400 text-sm font-extrabold">{{ Num::currency($productsSummary['profit']) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ Num::quantity($productsSummary['margin']) }}%</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-100">100%</td>
                        </tr>
                    </tfoot>
                </x-table>
            @endif
        </div>
    @endif

    {{-- MODAL QUICK-VIEW DETAIL TRANSAKSI STRUK --}}
    <x-modal name="sale-detail-modal" max-width="3xl">
        @if ($selectedSale)
            @php
                $modalCogs = (int) $selectedSale->items->sum(fn ($i) => (float) $i->cost_price * (float) $i->quantity);
                $modalNet = $selectedSale->total - $selectedSale->tax_amount;
                $modalProfit = $modalNet - $modalCogs;
                $modalMargin = $modalNet > 0 ? round(($modalProfit / $modalNet) * 100, 1) : 0.0;
            @endphp
            <div class="p-5 sm:p-6 space-y-5">
                {{-- Header Modal --}}
                <div class="flex items-start justify-between gap-3 border-b border-slate-800 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                            <i data-lucide="receipt-text" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-bold text-base text-slate-100 font-mono">{{ $selectedSale->number }}</h3>
                                @if ($selectedSale->isVoided())
                                    <x-badge color="rose">DIBATALKAN</x-badge>
                                @elseif ($selectedSale->due_amount > 0)
                                    <x-badge color="amber">KASBON</x-badge>
                                @else
                                    <x-badge color="emerald">LUNAS</x-badge>
                                @endif
                            </div>
                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ $selectedSale->sold_at->translatedFormat('d F Y, H:i') }} · Kasir: <strong class="text-slate-200">{{ $selectedSale->cashier?->name ?? '-' }}</strong>
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        x-on:click="$dispatch('close')"
                        class="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-800 transition"
                        title="Tutup dialog"
                    >
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>

                {{-- Detail Pelanggan & Catatan --}}
                <div class="grid sm:grid-cols-2 gap-3 text-xs bg-slate-950/60 p-3.5 rounded-xl border border-slate-800">
                    <div>
                        <span class="text-slate-400">Pelanggan:</span>
                        <p class="font-semibold text-slate-200 text-sm mt-0.5">{{ $selectedSale->customer?->name ?? 'Pelanggan Umum (Tanpa Identitas)' }}</p>
                        @if ($selectedSale->customer?->phone)
                            <p class="text-slate-400 mt-0.5">{{ $selectedSale->customer->phone }}</p>
                        @endif
                    </div>
                    <div>
                        <span class="text-slate-400">Catatan Transaksi:</span>
                        <p class="text-slate-300 mt-0.5 italic">{{ $selectedSale->note ?: 'Tidak ada catatan khusus.' }}</p>
                    </div>
                </div>

                {{-- Tabel Rincian Barang --}}
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-slate-300 uppercase tracking-wider">Item Barang yang Dibeli</p>
                    <div class="overflow-x-auto rounded-xl border border-slate-800">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-900 border-b border-slate-800 text-slate-400">
                                <tr>
                                    <th class="px-3 py-2.5">Produk</th>
                                    <th class="px-3 py-2.5 text-right">Qty</th>
                                    <th class="px-3 py-2.5 text-right">Harga</th>
                                    <th class="px-3 py-2.5 text-right">Diskon</th>
                                    <th class="px-3 py-2.5 text-right">Subtotal</th>
                                    <th class="px-3 py-2.5 text-right">HPP Modal</th>
                                    <th class="px-3 py-2.5 text-right">Laba</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 text-slate-200">
                                @foreach ($selectedSale->items as $item)
                                    @php
                                        $itemCogs = (int) round((float) $item->cost_price * (float) $item->quantity);
                                        $itemProfit = $item->total - $itemCogs;
                                    @endphp
                                    <tr class="hover:bg-slate-800/30">
                                        <td class="px-3 py-2.5">
                                            <p class="font-medium text-slate-100">{{ $item->product_name }}</p>
                                            @if ($item->sku)
                                                <p class="text-[10px] text-slate-400 font-mono">{{ $item->sku }}</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums font-semibold">{{ Num::quantity($item->quantity) }} {{ $item->unit }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-400">{{ Num::currency($item->price) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-400">{{ Num::currency($item->discount_amount) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums font-bold text-slate-100">{{ Num::currency($item->total) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-400 text-[11px]">{{ Num::currency($itemCogs) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums font-bold text-emerald-400">{{ Num::currency($itemProfit) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Ringkasan Finansial Nota & Pembayaran --}}
                <div class="grid sm:grid-cols-2 gap-4 pt-2">
                    {{-- Riwayat Pembayaran --}}
                    <div class="space-y-2">
                        <p class="text-xs font-semibold text-slate-300 uppercase tracking-wider">Riwayat Pembayaran</p>
                        <div class="bg-slate-950/60 rounded-xl border border-slate-800 p-3 space-y-2">
                            @forelse ($selectedSale->payments as $payment)
                                <div class="flex items-center justify-between text-xs py-1 border-b border-slate-800/60 last:border-0">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="{{ $payment->method->icon() }}" class="w-3.5 h-3.5 text-emerald-400"></i>
                                        <span class="text-slate-200 font-medium">{{ $payment->method->label() }}</span>
                                        @if ($payment->reference)
                                            <span class="text-[10px] text-slate-400 font-mono">({{ $payment->reference }})</span>
                                        @endif
                                    </div>
                                    <span class="tabular-nums font-bold text-slate-100">{{ Num::currency($payment->amount) }}</span>
                                </div>
                            @empty
                                <p class="text-xs text-slate-400">Belum ada catatan pembayaran.</p>
                            @endforelse
                        </div>
                    </div>

                    {{-- Rekap Total Transaksi --}}
                    <div class="bg-slate-950/60 rounded-xl border border-slate-800 p-3.5 space-y-1.5 text-xs">
                        <div class="flex justify-between text-slate-400">
                            <span>Subtotal Barang:</span>
                            <span class="tabular-nums text-slate-200">{{ Num::currency($selectedSale->subtotal) }}</span>
                        </div>
                        @if ($selectedSale->discount_amount > 0)
                            <div class="flex justify-between text-slate-400">
                                <span>Diskon Transaksi:</span>
                                <span class="tabular-nums text-rose-400">-{{ Num::currency($selectedSale->discount_amount) }}</span>
                            </div>
                        @endif
                        @if ($selectedSale->tax_amount > 0)
                            <div class="flex justify-between text-slate-400">
                                <span>Pajak ({{ $selectedSale->tax_rate }}%):</span>
                                <span class="tabular-nums text-slate-200">{{ Num::currency($selectedSale->tax_amount) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-sm font-bold text-slate-100 pt-1.5 border-t border-slate-800">
                            <span>Total Akhir:</span>
                            <span class="tabular-nums text-emerald-400">{{ Num::currency($selectedSale->total) }}</span>
                        </div>
                        <div class="flex justify-between text-slate-400 pt-1 border-t border-slate-800/60">
                            <span>Total Dibayar:</span>
                            <span class="tabular-nums text-slate-100 font-medium">{{ Num::currency($selectedSale->paid_amount) }}</span>
                        </div>
                        @if ($selectedSale->due_amount > 0)
                            <div class="flex justify-between text-amber-400 font-semibold">
                                <span>Sisa Kasbon:</span>
                                <span class="tabular-nums">{{ Num::currency($selectedSale->due_amount) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-slate-400 pt-1 border-t border-slate-800/60">
                            <span>Estimasi Laba Nota:</span>
                            <span class="tabular-nums font-bold text-emerald-400">{{ Num::currency($modalProfit) }} ({{ $modalMargin }}%)</span>
                        </div>
                    </div>
                </div>

                {{-- Alert jika voided --}}
                @if ($selectedSale->isVoided())
                    <div class="p-3 bg-rose-950/40 border border-rose-500/30 rounded-xl text-xs text-rose-300 space-y-1">
                        <div class="flex items-center gap-1.5 font-semibold text-rose-400">
                            <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                            <span>Transaksi Ini Telah Dibatalkan (Void)</span>
                        </div>
                        <p class="text-slate-300">
                            Dibatalkan pada {{ $selectedSale->voided_at?->translatedFormat('d M Y, H:i') }} oleh <strong>{{ $selectedSale->voider?->name ?? 'Sistem' }}</strong>.
                        </p>
                        @if ($selectedSale->void_reason)
                            <p class="text-rose-200">Alasan: <em>"{{ $selectedSale->void_reason }}"</em></p>
                        @endif
                    </div>
                @endif

                {{-- Actions --}}
                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2.5 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        x-on:click="$dispatch('close')"
                        class="inline-flex items-center justify-center h-10 px-4 text-xs font-semibold rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-200 transition"
                    >
                        Tutup
                    </button>
                    <a
                        href="{{ route('pos.receipt', $selectedSale) }}"
                        target="_blank"
                        x-on:click.prevent="window.open($el.href, '_blank', 'width=420,height=600')"
                        class="inline-flex items-center justify-center gap-2 h-10 px-4 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm transition"
                    >
                        <i data-lucide="printer" class="w-4 h-4"></i>
                        <span>Cetak Ulang Struk</span>
                    </a>
                </div>
            </div>
        @endif
    </x-modal>
</div>
@endif
</div>
