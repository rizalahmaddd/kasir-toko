@php
    use App\Enums\PaymentMethod;
    use App\Support\NumberFormatter as Num;
    $nonCashTotal = array_sum($summary['non_cash']);
@endphp

{{-- Panel fokal rekap shift: uang yang seharusnya ada di laci. --}}
<div class="rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 border border-slate-700/60 shadow-lg shadow-slate-950/30 p-4 sm:p-6">
    <div class="grid md:grid-cols-[1fr_1fr] gap-5 md:gap-8">
        <div>
            <p class="text-xs text-slate-400">{{ $shift->isOpen() ? 'Uang tunai yang seharusnya ada di laci' : 'Uang laci saat ditutup' }}</p>
            <p class="text-3xl sm:text-4xl font-extrabold text-slate-50 tabular-nums tracking-tight mt-1">{{ Num::currency($shift->isOpen() ? $summary['expected'] : $shift->expected_cash) }}</p>

            @unless ($shift->isOpen())
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-slate-400">Uang fisik {{ Num::currency($shift->counted_cash) }}</span>
                    @if ($shift->cash_difference === 0)
                        <x-badge color="emerald">COCOK</x-badge>
                    @elseif ($shift->cash_difference > 0)
                        <x-badge color="amber">LEBIH {{ Num::currency($shift->cash_difference) }}</x-badge>
                    @else
                        <x-badge color="rose">KURANG {{ Num::currency(abs($shift->cash_difference)) }}</x-badge>
                    @endif
                </div>
            @endunless

            <dl class="mt-4 space-y-1.5 text-xs">
                <div class="flex justify-between text-slate-400"><dt>Modal awal</dt><dd class="tabular-nums text-slate-200">{{ Num::currency($summary['opening']) }}</dd></div>
                <div class="flex justify-between text-slate-400"><dt>Penjualan tunai</dt><dd class="tabular-nums text-slate-200">+{{ Num::currency($summary['cash_sales']) }}</dd></div>
                @if ($summary['cash_receivables'] > 0)
                    <div class="flex justify-between text-slate-400"><dt>Pelunasan kasbon tunai</dt><dd class="tabular-nums text-slate-200">+{{ Num::currency($summary['cash_receivables']) }}</dd></div>
                @endif
                <div class="flex justify-between text-slate-400"><dt>Kas masuk</dt><dd class="tabular-nums text-slate-200">+{{ Num::currency($summary['cash_in']) }}</dd></div>
                <div class="flex justify-between text-slate-400"><dt>Kas keluar</dt><dd class="tabular-nums text-slate-200">-{{ Num::currency($summary['cash_out']) }}</dd></div>
            </dl>
        </div>

        <div class="md:border-l md:border-slate-700/60 md:pl-8 pt-4 md:pt-0 border-t md:border-t-0 border-slate-700/60">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <p class="text-[11px] text-slate-400">Transaksi</p>
                    <p class="text-xl font-bold text-slate-100 tabular-nums">{{ Num::quantity($summary['sales_count']) }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-slate-400">Total penjualan</p>
                    <p class="text-xl font-bold text-emerald-400 tabular-nums">{{ Num::currency($summary['sales_total']) }}</p>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 mt-4 mb-1.5">Non-tunai (tidak masuk laci)</p>
            <dl class="space-y-1.5 text-xs">
                @foreach ($summary['non_cash'] as $method => $amount)
                    <div class="flex justify-between text-slate-400"><dt>{{ PaymentMethod::from($method)->label() }}</dt><dd class="tabular-nums text-slate-200">{{ Num::currency($amount) }}</dd></div>
                @endforeach
                <div class="flex justify-between pt-1.5 border-t border-slate-700/60 text-slate-300 font-semibold"><dt>Total non-tunai</dt><dd class="tabular-nums">{{ Num::currency($nonCashTotal) }}</dd></div>
            </dl>
            @if ($summary['voided_count'] > 0)
                <p class="text-[11px] text-rose-400 mt-3">{{ $summary['voided_count'] }} transaksi dibatalkan di shift ini (tidak dihitung).</p>
            @endif
        </div>
    </div>
</div>
