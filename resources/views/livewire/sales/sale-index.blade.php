@php use App\Support\NumberFormatter as Num; @endphp

<div class="space-y-4 sm:space-y-5">
    <div class="flex flex-col xl:flex-row xl:items-center gap-3 justify-between">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center bg-slate-900 border border-slate-800 rounded-lg h-11 sm:h-[38px] px-1">
                <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400 mx-2"></i>
                <input type="date" wire:model.live="from" aria-label="Dari tanggal" class="bg-transparent border-0 text-slate-200 text-xs focus:ring-1 focus:ring-emerald-500 rounded w-[8.5rem] px-1">
                <span class="text-slate-500 text-xs px-1">–</span>
                <input type="date" wire:model.live="to" aria-label="Sampai tanggal" class="bg-transparent border-0 text-slate-200 text-xs focus:ring-1 focus:ring-emerald-500 rounded w-[8.5rem] px-1">
            </div>
            <x-segmented>
                <x-tab-button size="sm" :active="$from === now()->toDateString() && $to === $from" wire:click="preset('today')">Hari ini</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->subDay()->toDateString() && $to === $from" wire:click="preset('yesterday')">Kemarin</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->subDays(6)->toDateString() && $to === now()->toDateString()" wire:click="preset('7days')">7 hari</x-tab-button>
                <x-tab-button size="sm" :active="$from === now()->startOfMonth()->toDateString() && $to === now()->toDateString() && now()->day > 7" wire:click="preset('month')">Bulan ini</x-tab-button>
            </x-segmented>
        </div>

        <div class="flex flex-wrap sm:flex-nowrap items-center gap-2">
            <x-search-input class="basis-full sm:basis-auto sm:w-60" wire:model.live.debounce.400ms="search" placeholder="No. transaksi, pelanggan, produk..." />
            <x-select variant="filter" wire:model.live="status" aria-label="Filter status" class="flex-1 sm:flex-none">
                <option value="">Semua status</option>
                <option value="completed">Selesai</option>
                <option value="credit">Belum lunas</option>
                <option value="voided">Dibatalkan</option>
            </x-select>
            <x-select variant="filter" wire:model.live="method" aria-label="Filter metode bayar" class="flex-1 sm:flex-none">
                <option value="">Semua metode</option>
                @foreach (\App\Enums\PaymentMethod::cases() as $paymentMethod)
                    <option value="{{ $paymentMethod->value }}">{{ $paymentMethod->label() }}</option>
                @endforeach
            </x-select>
            @if ($cashiers->count() > 1)
                <x-select variant="filter" wire:model.live="cashier" aria-label="Filter kasir" class="flex-1 sm:flex-none">
                    <option value="">Semua kasir</option>
                    @foreach ($cashiers as $cashierOption)
                        <option value="{{ $cashierOption->id }}">{{ $cashierOption->name }}</option>
                    @endforeach
                </x-select>
            @endif
            <x-table.export-button action="export" label="Export" />
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-slate-400">
        <span><strong class="text-slate-100 text-sm tabular-nums">{{ Num::quantity($summary['count']) }}</strong> transaksi selesai</span>
        <span>Total <strong class="text-emerald-400 text-sm tabular-nums">{{ Num::currency($summary['total']) }}</strong></span>
        @if ($summary['voided'] > 0)
            <span class="text-rose-400">{{ $summary['voided'] }} dibatalkan</span>
        @endif
        @unless ($this->canViewAll())
            <span>Hanya menampilkan transaksi Anda.</span>
        @endunless
    </div>

    @if ($sales->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
            <x-empty-state icon="receipt"
                :title="$search || $status || $method ? 'Tidak ada transaksi yang cocok' : 'Belum ada transaksi di periode ini'"
                :description="$search || $status || $method ? 'Ubah kata kunci atau filter.' : 'Transaksi dari layar kasir muncul di sini begitu dibayar.'">
                @if (! $search && auth()->user()->can('pos.sell'))
                    <x-feature-link :href="route('pos.cashier')" wire:navigate class="inline-flex text-xs font-semibold text-emerald-400 hover:underline">Buka kasir</x-feature-link>
                @endif
            </x-empty-state>
        </div>
    @else
        <x-table :pagination="$sales">
            <x-slot:header>
                <tr>
                    <x-table.th>Transaksi</x-table.th>
                    <x-table.th>Pelanggan</x-table.th>
                    <x-table.th>Pembayaran</x-table.th>
                    <x-table.th align="right">Total</x-table.th>
                    <x-table.th>Status</x-table.th>
                    <x-table.th align="right"><span class="sr-only">Aksi</span></x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($sales as $sale)
                    <x-table.tr wire:key="sale-{{ $sale->id }}">
                        <x-table.td>
                            <a href="{{ route('sales.show', $sale) }}" wire:navigate class="font-mono font-semibold text-emerald-400 hover:text-emerald-300">{{ $sale->number }}</a>
                            <div class="text-[11px] text-slate-400">{{ $sale->sold_at->translatedFormat($from === $to ? 'H:i' : 'd M, H:i') }} · {{ $sale->cashier->name }}</div>
                        </x-table.td>
                        <x-table.td class="text-slate-300">
                            {{ $sale->customer?->name ?? 'Umum' }}
                            <div class="text-[11px] text-slate-400">{{ $sale->items_count }} baris barang</div>
                        </x-table.td>
                        <x-table.td>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($sale->payments->pluck('method')->unique() as $paymentMethod)
                                    <x-badge>{{ strtoupper($paymentMethod->shortLabel()) }}</x-badge>
                                @empty
                                    <span class="text-slate-400">-</span>
                                @endforelse
                            </div>
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums">
                            <span @class(['font-semibold', 'text-slate-100' => ! $sale->isVoided(), 'text-slate-500 line-through' => $sale->isVoided()])>{{ Num::currency($sale->total) }}</span>
                            @if ($sale->due_amount > 0 && ! $sale->isVoided())
                                <div class="text-[11px] text-amber-400">sisa {{ Num::currency($sale->due_amount) }}</div>
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
                                <a href="{{ route('pos.receipt', $sale) }}" target="_blank" x-on:click.prevent="window.open($el.href, '_blank')" title="Cetak ulang struk" aria-label="Cetak ulang struk {{ $sale->number }}"
                                    class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800">
                                    <i data-lucide="printer" class="w-4 h-4"></i>
                                </a>
                                <a href="{{ route('sales.show', $sale) }}" wire:navigate aria-label="Detail {{ $sale->number }}"
                                    class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </a>
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif
</div>
