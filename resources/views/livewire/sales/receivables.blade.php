@php
    use App\Support\NumberFormatter as Num;
    $currentTenant = app(\App\Support\CurrentTenant::class)->get();
@endphp

<div>
@if ($currentTenant && ! $currentTenant->isPro())
    <x-pro-paywall
        title="Pencatatan Piutang & Kasbon Khusus Pro"
        feature="Piutang & Kasbon"
        description="Kelola transaksi tempo/bon, catat pelunasan bertahap, dan pantau tagihan pelanggan tanpa batasan dengan paket Pro."
        icon="hand-coins"
    />
@else
<div class="space-y-4 sm:space-y-6">
    <div class="grid grid-cols-2 gap-2.5 sm:gap-4 max-w-xl">
        <x-dashboard.stat title="Total kasbon belum lunas" :value="Num::currency($totalDue)" icon="hand-coins" :tone="$totalDue > 0 ? 'amber' : 'slate'" />
        <x-dashboard.stat title="Pelanggan berutang" :value="Num::quantity($customerCount)" icon="users" />
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <p class="text-xs text-slate-400">Transaksi yang dibayar sebagian. Urut dari yang paling lama.</p>
        <div class="flex flex-wrap sm:flex-nowrap items-center gap-2">
            <x-search-input class="basis-full sm:basis-auto sm:w-64" wire:model.live.debounce.400ms="search" placeholder="Cari pelanggan atau no. transaksi..." />
            <x-outlet-filter :choices="$outletChoices" />
            <x-table.export-button action="export" label="Export" />
        </div>
    </div>

    @if ($sales->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
            <x-empty-state icon="hand-coins"
                :title="$search ? 'Tidak ada kasbon yang cocok' : 'Tidak ada kasbon'"
                :description="$search ? 'Coba kata kunci lain.' : 'Semua transaksi sudah lunas. Kasbon dicatat dari layar kasir saat pembayaran kurang dan pelanggan dipilih.'" />
        </div>
    @else
        <x-table :pagination="$sales">
            <x-slot:header>
                <tr>
                    <x-table.th>Pelanggan</x-table.th>
                    <x-table.th>Transaksi</x-table.th>
                    <x-table.th align="right">Total</x-table.th>
                    <x-table.th align="right">Sisa</x-table.th>
                    <x-table.th align="right"><span class="sr-only">Aksi</span></x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($sales as $sale)
                    @php $age = (int) $sale->sold_at->copy()->startOfDay()->diffInDays(now()->startOfDay()); @endphp
                    <x-table.tr wire:key="receivable-{{ $sale->id }}">
                        <x-table.td>
                            @if ($sale->customer)
                                <x-feature-link :href="route('master-data.customers.show', $sale->customer)" wire:navigate class="font-semibold text-slate-100 hover:text-emerald-400">{{ $sale->customer->name }}</x-feature-link>
                                <div class="text-[11px] text-slate-400 font-mono">{{ $sale->customer->phone ?: '-' }}</div>
                            @else
                                <span class="text-slate-400">-</span>
                            @endif
                        </x-table.td>
                        <x-table.td>
                            <a href="{{ route('sales.show', $sale) }}" wire:navigate class="font-mono text-emerald-400 hover:text-emerald-300">{{ $sale->number }}</a>
                            <div @class(['text-[11px]', 'text-amber-400' => $age > 30, 'text-slate-400' => $age <= 30])>{{ $sale->sold_at->translatedFormat('d M Y') }} · {{ $age === 0 ? 'hari ini' : $age.' hari lalu' }}</div>
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-400">
                            {{ Num::currency($sale->total) }}
                            <div class="text-[11px]">dibayar {{ Num::currency($sale->paid_amount) }}</div>
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums font-bold text-amber-400">{{ Num::currency($sale->due_amount) }}</x-table.td>
                        <x-table.td align="right">
                            <x-secondary-button size="xs" tone="emerald" wire:click="openCollect({{ $sale->id }})">
                                <i data-lucide="hand-coins" class="w-3.5 h-3.5"></i> Terima
                            </x-secondary-button>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-modal name="collect-payment" max-width="md" focusable>
        @if ($collecting)
            <form wire:submit="collect" class="p-5 sm:p-6 space-y-4">
                <x-modal-header title="Terima pelunasan kasbon" icon="hand-coins" closeable>
                    {{ $collecting->customer?->name }} · {{ $collecting->number }} · sisa {{ Num::currency($collecting->due_amount) }}
                </x-modal-header>
                @include('livewire.sales.partials.collect-fields', ['methods' => $methods])
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="collect" loading="Menyimpan...">Simpan Pembayaran</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        @endif
    </x-modal>
</div>
@endif
</div>
