@php
    use App\Support\NumberFormatter as Num;

    $planTotal = max(1, array_sum($plans));
    $storeTypeTotal = max(1, array_sum($storeTypes));
@endphp

<div class="space-y-4 sm:space-y-6">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <x-dashboard.stat title="Toko Aktif" :value="Num::quantity($counts['active'])" icon="store" tone="emerald" :subtitle="'dari '.Num::quantity($counts['total']).' toko terdaftar'" :href="route('platform.tenants', ['status' => 'usable'])" />
        <x-dashboard.stat title="Sedang Uji Coba" :value="Num::quantity($counts['trial'])" icon="hourglass" :subtitle="Num::quantity($counts['new_30d']).' daftar 30 hari terakhir'" />
        <x-dashboard.stat title="Masa Aktif Habis" :value="Num::quantity($counts['expired'])" icon="calendar-x" :tone="$counts['expired'] > 0 ? 'amber' : 'slate'" :subtitle="Num::quantity($counts['suspended']).' toko dinonaktifkan'" :href="route('platform.tenants', ['status' => 'expired'])" />
        <x-dashboard.stat title="Pendapatan Bulan Ini" :value="Num::currency($revenue['this_month'])" icon="wallet" :subtitle="Num::currency($revenue['last_30d']).' dalam 30 hari'" :href="route('platform.payments')" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
        <x-dashboard.panel title="Habis dalam 7 hari" icon="alarm-clock" :tone="$endingSoon->isNotEmpty() ? 'amber' : 'slate'" :count="$endingSoon->count() ?: null" class="lg:col-span-2">
            @if ($endingSoon->isEmpty())
                <x-dashboard.empty icon="calendar-check" message="Tidak ada masa aktif yang habis minggu ini." />
            @else
                <ul class="divide-y divide-slate-200 dark:divide-slate-800/60">
                    @foreach ($endingSoon as $tenant)
                        <x-dashboard.row
                            :href="route('platform.tenants.show', $tenant)"
                            :title="$tenant->name"
                            :meta="$tenant->planLabel().' · berakhir '.$tenant->accessEndsAt()->translatedFormat('d M Y')"
                            :value="$tenant->accessEndsAt()->diffForHumans()"
                            value-tone="amber"
                        />
                    @endforeach
                </ul>
            @endif
        </x-dashboard.panel>

        <x-dashboard.panel title="Paket" icon="layers">
            <ul class="space-y-3 text-xs">
                @foreach ($plans as $label => $total)
                    <li>
                        <div class="flex items-center justify-between gap-3 mb-1">
                            <span class="text-slate-600 dark:text-slate-300">{{ $label }}</span>
                            <span class="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{{ Num::quantity($total) }}</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                            <div class="h-full bg-emerald-500" style="width: {{ round($total / $planTotal * 100) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($counts['pending_onboarding'] > 0)
                <p class="pt-3 text-[11px] text-slate-500 dark:text-slate-400">{{ Num::quantity($counts['pending_onboarding']) }} toko belum menyelesaikan persiapan toko.</p>
            @endif
        </x-dashboard.panel>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
        <x-dashboard.panel title="Toko paling aktif" subtitle="Transaksi 30 hari terakhir" icon="trending-up">
            @if ($mostActive->isEmpty())
                <x-dashboard.empty icon="receipt" message="Belum ada transaksi dalam 30 hari terakhir." />
            @else
                <ul class="divide-y divide-slate-200 dark:divide-slate-800/60">
                    @foreach ($mostActive as $tenant)
                        <x-dashboard.row
                            :href="route('platform.tenants.show', $tenant)"
                            :title="$tenant->name"
                            :meta="Num::quantity($tenant->sales_count).' transaksi'"
                            :value="Num::currency($tenant->revenue)"
                        />
                    @endforeach
                </ul>
            @endif
        </x-dashboard.panel>

        <x-dashboard.panel title="Jenis toko" icon="shapes">
            @if ($storeTypes === [])
                <x-dashboard.empty icon="shapes" message="Belum ada toko yang memilih jenis toko." />
            @else
                <ul class="space-y-3 text-xs">
                    @foreach ($storeTypes as $label => $total)
                        <li>
                            <div class="flex items-center justify-between gap-3 mb-1">
                                <span class="text-slate-600 dark:text-slate-300 truncate">{{ $label }}</span>
                                <span class="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{{ Num::quantity($total) }}</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full bg-sky-500" style="width: {{ round($total / $storeTypeTotal * 100) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dashboard.panel>

        <x-dashboard.panel title="Pembayaran terakhir" icon="receipt">
            @if ($recentPayments->isEmpty())
                <x-dashboard.empty icon="wallet" message="Belum ada pembayaran yang dicatat." />
            @else
                <ul class="divide-y divide-slate-200 dark:divide-slate-800/60">
                    @foreach ($recentPayments as $payment)
                        <x-dashboard.row
                            :href="route('platform.tenants.show', $payment->tenant_id)"
                            :title="$payment->tenant?->name ?? '-'"
                            :meta="$payment->actionLabel().' · '.$payment->created_at->translatedFormat('d M Y')"
                            :value="Num::currency($payment->amount)"
                            value-tone="emerald"
                        />
                    @endforeach
                </ul>
            @endif
        </x-dashboard.panel>
    </div>
</div>
