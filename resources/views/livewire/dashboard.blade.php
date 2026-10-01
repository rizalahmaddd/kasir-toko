@php use App\Support\NumberFormatter as Num; @endphp

<div class="space-y-4 sm:space-y-6">
    {{-- Satu titik fokus di layar ini (DESIGN.md): penjualan hari ini + jalan pintas ke kasir. --}}
    <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-gradient-to-r dark:from-slate-900 dark:via-slate-900 dark:to-slate-800/90 border border-slate-200 dark:border-slate-800 p-4 sm:p-6 md:p-8 shadow-sm dark:shadow-xl">
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-5">
            <div class="space-y-2 max-w-2xl">
                <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">{{ now()->isoFormat('dddd, D MMMM Y') }}</p>
                <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                    {{ __('Selamat datang, :name', ['name' => auth()->user()->name]) }}
                </h2>
                @if (auth()->user()->getRoleNames()->isEmpty())
                    <p class="text-xs md:text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                        {{ __('Akun Anda belum punya peran. Menu kerja muncul setelah Superadmin menetapkan peran untuk akun ini.') }}
                    </p>
                @elseif ($today !== null)
                    <div class="pt-2">
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()->can('sales.view') || auth()->user()->can('reports.sales.view') ? 'Penjualan hari ini' : 'Penjualan Anda hari ini' }}</p>
                        <p class="text-3xl sm:text-4xl font-extrabold text-slate-900 dark:text-white tabular-nums tracking-tight">{{ Num::currency($today['revenue']) }}</p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                            {{ Num::quantity($today['count']) }} transaksi · kemarin {{ Num::currency($today['yesterday']) }}
                        </p>
                    </div>
                @endif
            </div>

            @if ($canSell)
                <div class="flex flex-col sm:flex-row md:flex-col items-stretch sm:items-center md:items-end gap-2">
                    <x-feature-link :href="route('pos.cashier')" wire:navigate hide-when-disabled
                        class="inline-flex items-center justify-center gap-2.5 min-h-[52px] px-6 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-slate-950 font-bold text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">
                        <i data-lucide="shopping-cart" class="w-5 h-5"></i>
                        Buka Kasir
                    </x-feature-link>
                    <p class="text-[11px] text-center md:text-right {{ $shift ? 'text-slate-500 dark:text-slate-400' : 'text-amber-600 dark:text-amber-400' }}">
                        @if ($shift)
                            Shift {{ $shift->number }} buka sejak {{ $shift->opened_at->format($shift->opened_at->isToday() ? 'H:i' : 'd/m H:i') }}
                        @else
                            Shift belum dibuka hari ini
                        @endif
                    </p>
                </div>
            @endif
        </div>
    </div>

    @if ($stats !== [])
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            @foreach ($stats as $stat)
                <x-dashboard.stat :title="$stat['title']" :value="$stat['value']" :icon="$stat['icon']" :tone="$stat['tone']"
                    :subtitle="$stat['subtitle']" :href="$stat['href']" />
            @endforeach
        </div>
    @endif

    @if ($weekChart !== null || $lowStock !== null)
        <div class="grid lg:grid-cols-3 gap-4 sm:gap-6 items-start">
            @if ($weekChart !== null)
                <x-dashboard.panel title="Omzet 7 hari terakhir" icon="bar-chart-3" :href="route('reports.sales')" link-label="Laporan" @class(['lg:col-span-2' => $lowStock !== null, 'lg:col-span-3' => $lowStock === null])>
                    <x-bar-chart :data="$weekChart" type="currency" />
                </x-dashboard.panel>
            @endif

            @if ($lowStock !== null)
                <x-dashboard.panel title="Stok menipis" icon="package-x" :tone="$lowStock->isNotEmpty() ? 'amber' : 'slate'" :href="route('inventory.stock', ['level' => 'low'])" @class(['lg:col-span-3' => $weekChart === null])>
                    @if ($lowStock->isEmpty())
                        <x-dashboard.empty icon="package-check" message="Semua stok di atas batas minimum." />
                    @else
                        <ul class="divide-y divide-slate-200 dark:divide-slate-800/60">
                            @foreach ($lowStock as $product)
                                <x-dashboard.row
                                    :href="route('inventory.stock', ['product' => $product->id])"
                                    :title="$product->name"
                                    :meta="'Minimum '.Num::quantity($product->min_stock).' '.$product->unit"
                                    :value="Num::quantity($product->stock).' '.$product->unit"
                                    :value-tone="(float) $product->stock <= 0 ? 'rose' : 'amber'" />
                            @endforeach
                        </ul>
                    @endif
                </x-dashboard.panel>
            @endif
        </div>
    @endif

    @if ($openShifts->count() > 0)
        <x-dashboard.panel title="Shift yang sedang buka" icon="wallet" :count="$openShifts->count()" :href="route('shifts.index', ['status' => 'open'])">
            <ul class="divide-y divide-slate-200 dark:divide-slate-800/60">
                @foreach ($openShifts as $openShift)
                    <x-dashboard.row
                        :href="route('shifts.show', $openShift)"
                        :title="$openShift->user->name"
                        :meta="$openShift->number.' · sejak '.$openShift->opened_at->translatedFormat('d M H:i')"
                        :badge="$openShift->opened_at->isToday() ? null : 'BELUM DITUTUP'"
                        badge-color="amber" />
                @endforeach
            </ul>
        </x-dashboard.panel>
    @endif

    @if ($recentSales !== null)
        <x-dashboard.panel title="Transaksi terbaru" icon="receipt" :href="route('sales.index')">
            @if ($recentSales->isEmpty())
                <x-dashboard.empty icon="receipt" message="Belum ada transaksi. Mulai dari tombol Buka Kasir." />
            @else
                <ul class="divide-y divide-slate-200 dark:divide-slate-800/60">
                    @foreach ($recentSales as $sale)
                        <x-dashboard.row
                            :href="route('sales.show', $sale)"
                            :title="$sale->number"
                            :meta="$sale->sold_at->translatedFormat($sale->sold_at->isToday() ? 'H:i' : 'd M H:i').' · '.($sale->customer?->name ?? 'Umum')"
                            :value="Num::currency($sale->total)"
                            :badge="$sale->due_amount > 0 ? 'KASBON' : null"
                            badge-color="amber" />
                    @endforeach
                </ul>
            @endif
        </x-dashboard.panel>
    @endif

    <x-dashboard.shortcuts :links="$shortcuts" />

    @if ($activities !== null)
        <x-dashboard.activity-feed :activities="$activities" />
    @endif
</div>
