@php
    use App\Enums\StockCountReason;
    use App\Livewire\Reports\StockVarianceReport;
    use App\Support\NumberFormatter as Num;
@endphp

<div>
@if (! $pro)
    <x-pro-paywall
        title="Laporan Selisih Stok Khusus Pro"
        feature="Laporan Selisih Stok"
        description="Lihat barang yang paling sering kurang, nilai kehilangan per periode, outlet, kategori, dan alasan selisih dari semua opname dengan paket Pro."
        icon="clipboard-check"
    />
@else
<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <x-date-range-filter />
        <x-table.export-button action="export" label="Ekspor" />
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @if ($outletChoices->count() > 1)
            <div class="w-full sm:w-44"><x-outlet-filter :choices="$outletChoices" class="w-full" /></div>
        @endif
        <div class="w-full sm:w-44">
            <x-select variant="filter" wire:model.live="categoryId" aria-label="Saring kategori" class="w-full">
                <option value="">Semua kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-select>
        </div>
        <div class="w-full sm:w-44">
            <x-select variant="filter" wire:model.live="reason" aria-label="Saring alasan" class="w-full" :searchable="false">
                <option value="">Semua alasan</option>
                @foreach (StockCountReason::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
                <option value="{{ StockVarianceReport::QUICK }}">Opname cepat</option>
            </x-select>
        </div>
        <div class="w-full sm:w-44">
            <x-select variant="filter" wire:model.live="counterId" aria-label="Saring penghitung" class="w-full">
                <option value="">Semua penghitung</option>
                @foreach ($counters as $counter)
                    <option value="{{ $counter->id }}">{{ $counter->name }}</option>
                @endforeach
            </x-select>
        </div>
        <x-segmented aria-label="Arah selisih">
            <x-tab-button size="sm" :active="$direction === ''" wire:click="$set('direction', '')">Semua</x-tab-button>
            <x-tab-button size="sm" :active="$direction === 'shortage'" wire:click="$set('direction', 'shortage')">Kurang</x-tab-button>
            <x-tab-button size="sm" :active="$direction === 'surplus'" wire:click="$set('direction', 'surplus')">Lebih</x-tab-button>
        </x-segmented>
    </div>

    <div class="rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-5 shadow-lg grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <p class="text-[11px] text-slate-400">Selisih bersih</p>
            <p @class(['text-2xl font-bold tabular-nums', 'text-rose-400' => $totals['net_value'] < 0, 'text-emerald-400' => $totals['net_value'] > 0, 'text-slate-100' => $totals['net_value'] === 0])>{{ $totals['net_value'] > 0 ? '+' : '' }}{{ Num::currency($totals['net_value']) }}</p>
        </div>
        <div>
            <p class="text-[11px] text-slate-400">Kurang</p>
            <p class="text-xl font-bold tabular-nums text-rose-400">{{ Num::currency($totals['shortage_value']) }}</p>
            <p class="text-[11px] text-slate-400 tabular-nums">{{ Num::quantity($totals['shortage_qty']) }} unit</p>
        </div>
        <div>
            <p class="text-[11px] text-slate-400">Lebih</p>
            <p class="text-xl font-bold tabular-nums text-emerald-400">{{ Num::currency($totals['surplus_value']) }}</p>
            <p class="text-[11px] text-slate-400 tabular-nums">{{ Num::quantity($totals['surplus_qty']) }} unit</p>
        </div>
        <div>
            <p class="text-[11px] text-slate-400">Baris selisih</p>
            <p class="text-xl font-bold tabular-nums text-slate-100">{{ Num::quantity($totals['rows']) }}</p>
        </div>
    </div>

    @if ($totals['rows'] > 0)
        <div class="grid lg:grid-cols-2 gap-4">
            <div class="rounded-xl border border-slate-800 bg-slate-900/60 p-4">
                <h3 class="text-sm font-semibold text-slate-100 mb-2">Per alasan</h3>
                <ul class="divide-y divide-slate-800/60 text-xs">
                    @foreach ($reasons as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="text-slate-300">{{ StockVarianceReport::reasonLabel($row->reason_key) }} <span class="text-slate-500">· {{ $row->rows_count }} baris</span></span>
                            <span @class(['tabular-nums font-semibold', 'text-rose-400' => $row->value < 0, 'text-emerald-400' => $row->value > 0, 'text-slate-300' => (int) $row->value === 0])>{{ Num::currency((int) $row->value) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900/60 p-4">
                <h3 class="text-sm font-semibold text-slate-100 mb-2">Kategori dengan kurang terbesar</h3>
                <ul class="divide-y divide-slate-800/60 text-xs">
                    @foreach ($categoriesBreakdown as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="text-slate-300">{{ $row->category_name ?: 'Tanpa kategori' }} <span class="text-slate-500">· {{ $row->rows_count }} baris</span></span>
                            <span @class(['tabular-nums font-semibold', 'text-rose-400' => $row->value < 0, 'text-emerald-400' => $row->value > 0, 'text-slate-300' => (int) $row->value === 0])>{{ Num::currency((int) $row->value) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($details->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
            <x-empty-state icon="clipboard-check" title="Tidak ada selisih di periode ini" description="Selisih muncul di sini setelah opname diselesaikan atau setelah opname cepat di halaman Stok." />
        </div>
    @else
        <x-table :pagination="$details">
            <x-slot:header>
                <tr>
                    <x-table.th>Tanggal</x-table.th>
                    <x-table.th>Sumber</x-table.th>
                    <x-table.th>Barang</x-table.th>
                    <x-table.th align="right">Selisih</x-table.th>
                    <x-table.th align="right">Nilai</x-table.th>
                    <x-table.th>Alasan</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($details as $row)
                    <x-table.tr wire:key="variance-{{ $loop->index }}-{{ $row->product_id }}-{{ $row->stock_count_id }}">
                        <x-table.td class="text-slate-300 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row->happened_at)->translatedFormat('d M Y H:i') }}</x-table.td>
                        <x-table.td data-label="Sumber">
                            @if ($row->stock_count_id)
                                <x-feature-link :href="route('inventory.opname.show', $row->stock_count_id)" wire:navigate class="font-mono text-emerald-400 hover:underline">{{ $row->source }}</x-feature-link>
                            @else
                                <span class="text-slate-300">{{ $row->source }}</span>
                            @endif
                            @if ($outletChoices->count() > 1)
                                <div class="text-[11px] text-slate-400">{{ $row->outlet_name }}</div>
                            @endif
                        </x-table.td>
                        <x-table.td data-label="Barang">
                            <div class="font-semibold text-slate-100">{{ $row->product_name }}</div>
                            <div class="text-[11px] text-slate-400"><span class="font-mono">{{ $row->sku }}</span>{{ $row->category_name ? ' · '.$row->category_name : '' }}</div>
                        </x-table.td>
                        <x-table.td data-label="Selisih" align="right" @class(['tabular-nums font-semibold', 'text-rose-400' => $row->quantity < 0, 'text-emerald-400' => $row->quantity > 0])>{{ $row->quantity > 0 ? '+' : '' }}{{ Num::quantity((float) $row->quantity) }} <span class="font-normal text-slate-400">{{ $row->unit }}</span></x-table.td>
                        <x-table.td data-label="Nilai" align="right" class="tabular-nums text-slate-200">{{ Num::currency((int) $row->value) }}</x-table.td>
                        <x-table.td data-label="Alasan" class="text-slate-300">{{ $row->stock_count_id ? StockVarianceReport::reasonLabel($row->reason) : '-' }}</x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif
</div>
@endif
</div>
