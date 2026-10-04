@php
    use App\Support\NumberFormatter as Num;
    use App\Support\SaasPlans;
@endphp

<div class="space-y-4 sm:space-y-6">
    <div class="bg-gradient-to-br from-slate-900 to-slate-800 border border-slate-800/80 rounded-2xl p-4 sm:p-6 shadow-lg">
        <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
            <div>
                <p class="text-xs text-slate-400">Total pembayaran tercatat</p>
                <p class="text-2xl sm:text-3xl font-bold text-white tabular-nums mt-1">{{ Num::currency($total) }}</p>
                <p class="text-xs text-slate-400 mt-1">{{ Num::quantity($count) }} pembayaran dari {{ Num::quantity($payingTenants) }} toko</p>
            </div>
            <x-date-range-filter />
        </div>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <x-search-input class="sm:w-72" wire:model.live.debounce.400ms="search" placeholder="Cari toko atau catatan..." />
        <x-table.export-button action="export" label="Export" />
    </div>

    @if ($payments->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state icon="receipt" title="Belum ada pembayaran di periode ini" description="Pembayaran muncul di sini saat nominal diisi ketika memperpanjang atau mengubah paket toko." />
        </div>
    @else
        <x-table :pagination="$payments">
            <x-slot:header>
                <tr>
                    <x-table.th sortable field="created_at">Tanggal</x-table.th>
                    <x-table.th>Toko</x-table.th>
                    <x-table.th>Keterangan</x-table.th>
                    <x-table.th>Dicatat Oleh</x-table.th>
                    <x-table.th sortable field="amount" align="right">Nominal</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($payments as $payment)
                    <x-table.tr wire:key="payment-{{ $payment->id }}">
                        <x-table.td class="text-slate-400 tabular-nums whitespace-nowrap">{{ $payment->created_at->translatedFormat('d M Y H:i') }}</x-table.td>
                        <x-table.td>
                            @if ($payment->tenant)
                                <a href="{{ route('platform.tenants.show', $payment->tenant) }}" wire:navigate class="font-medium text-slate-100 hover:text-emerald-400 hover:underline">{{ $payment->tenant->name }}</a>
                            @else
                                <span class="text-slate-400">-</span>
                            @endif
                        </x-table.td>
                        <x-table.td class="text-slate-300">
                            {{ $payment->actionLabel() }} · {{ SaasPlans::label($payment->to_plan) }}
                            <div class="text-[11px] text-slate-400">
                                Aktif sampai {{ $payment->to_ends_at?->translatedFormat('d M Y') ?? 'tanpa batas' }}@if ($payment->note) · {{ $payment->note }}@endif
                            </div>
                        </x-table.td>
                        <x-table.td class="text-slate-300">{{ $payment->user?->name ?? 'Sistem' }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums font-semibold text-emerald-400">{{ Num::currency($payment->amount) }}</x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif
</div>
