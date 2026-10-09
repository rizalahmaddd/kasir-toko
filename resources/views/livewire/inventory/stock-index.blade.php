@php
    use App\Enums\StockMovementType;
    use App\Support\NumberFormatter as Num;
    $summary = $this->summary;
@endphp

<div class="space-y-4 sm:space-y-6">
    @if (app(\App\Support\CurrentOutlet::class)->isMultiOutlet() && $this->outlet)
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
            <p class="text-slate-400">Stok di <strong class="text-slate-100">{{ $this->outlet->name }}</strong>. Ganti outlet lewat pemilih outlet di bagian atas.</p>
            @can('inventory.transfer')
                <x-feature-link :href="route('inventory.transfers')" wire:navigate class="inline-flex items-center gap-1.5 text-emerald-400 hover:text-emerald-300 font-semibold min-h-[44px] sm:min-h-0">
                    <i data-lucide="arrow-left-right" class="w-3.5 h-3.5"></i> Transfer stok antar outlet
                </x-feature-link>
            @endcan
        </div>
    @endif

    @foreach ($this->openCounts as $openCount)
        <div class="rounded-xl border border-sky-500/30 bg-sky-500/5 p-3.5 text-xs text-sky-300 flex flex-wrap items-center gap-2" wire:key="open-count-{{ $openCount->id }}">
            <i data-lucide="clipboard-check" class="w-4 h-4 shrink-0"></i>
            <span>Opname <strong class="font-mono">{{ $openCount->number }}</strong> sedang berjalan ({{ $openCount->scope->label() }}).{{ $openCount->hold_adjustments ? ' Stok masuk/keluar untuk barangnya ditahan sampai selesai.' : '' }}</span>
            @can('inventory.opname.count')
                <x-feature-link :href="route('inventory.opname.show', $openCount)" wire:navigate class="font-semibold text-emerald-400 hover:text-emerald-300 min-h-[44px] sm:min-h-0 inline-flex items-center">Buka opname</x-feature-link>
            @endcan
        </div>
    @endforeach

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-4">
        <x-dashboard.stat title="Produk dilacak" :value="Num::quantity($summary['tracked'])" icon="package" />
        <x-dashboard.stat title="Stok menipis" :value="Num::quantity($summary['low'])" icon="triangle-alert" :tone="$summary['low'] > 0 ? 'amber' : 'slate'" />
        <x-dashboard.stat title="Stok habis" :value="Num::quantity($summary['out'])" icon="package-x" :tone="$summary['out'] > 0 ? 'rose' : 'slate'" />
        <x-dashboard.stat title="Nilai stok (HPP)" :value="Num::currency($summary['value'])" icon="coins" />
    </div>

    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <x-segmented class="self-start">
            <x-tab-button :active="$tab === 'stock'" wire:click="$set('tab', 'stock')" icon="warehouse">Posisi Stok</x-tab-button>
            <x-tab-button :active="$tab === 'movements'" wire:click="$set('tab', 'movements')" icon="history">Riwayat Mutasi</x-tab-button>
        </x-segmented>

        <div class="flex flex-wrap sm:flex-nowrap items-center gap-2">
            @if (! ($tab === 'movements' && $productFilter))
                <x-search-input class="basis-full sm:basis-auto sm:w-64" wire:model.live.debounce.400ms="search" placeholder="Cari produk, SKU, barcode..." />
            @endif
            @if ($tab === 'stock')
                <x-select variant="filter" wire:model.live="level" aria-label="Filter stok" class="flex-1 sm:flex-none">
                    <option value="">Semua produk aktif</option>
                    <option value="low">Stok menipis</option>
                    <option value="out">Stok habis / minus</option>
                </x-select>
                <x-table.export-button action="export" label="Export" />
            @else
                <x-select variant="filter" wire:model.live="typeFilter" aria-label="Filter jenis mutasi" class="flex-1 sm:flex-none">
                    <option value="">Semua jenis</option>
                    @foreach (StockMovementType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </x-select>
            @endif
        </div>
    </div>

    @if ($tab === 'stock')
        @if ($products->isEmpty())
            <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
                <x-empty-state icon="warehouse"
                    :title="$search || $level ? 'Tidak ada produk yang cocok' : 'Belum ada produk yang stoknya dilacak'"
                    :description="$search || $level ? 'Ubah kata kunci atau filter.' : 'Aktifkan Lacak stok di data produk supaya stoknya dihitung otomatis setiap ada penjualan.'" />
            </div>
        @else
            <x-table :pagination="$products">
                <x-slot:header>
                    <tr>
                        <x-table.th sortable field="name">Produk</x-table.th>
                        <x-table.th sortable field="stock" align="right">Stok</x-table.th>
                        <x-table.th sortable field="min_stock" align="right">Minimum</x-table.th>
                        <x-table.th align="right">Aksi</x-table.th>
                    </tr>
                </x-slot:header>
                <tbody class="divide-y divide-slate-800/60">
                    @php $counting = $this->countingDocuments($products->items()); @endphp
                    @foreach ($products as $product)
                        @php $stock = $product->outletStock(); @endphp
                        <x-table.tr wire:key="stock-{{ $product->id }}">
                            <x-table.td>
                                <div class="font-semibold text-slate-100">{{ $product->name }}</div>
                                <div class="text-[11px] text-slate-400 font-mono">{{ $product->sku }}{{ $product->category ? ' · '.$product->category->name : '' }}</div>
                                @if (isset($counting[$product->id]))
                                    <x-badge color="sky" class="mt-1">Sedang dihitung · {{ $counting[$product->id]->number }}</x-badge>
                                @endif
                            </x-table.td>
                            <x-table.td align="right" class="tabular-nums">
                                @if ($stock <= 0)
                                    <x-badge color="rose">{{ $stock < 0 ? 'MINUS' : 'HABIS' }} {{ Num::quantity($stock) }}</x-badge>
                                @elseif ($product->isLowStock())
                                    <x-badge color="amber">MENIPIS {{ Num::quantity($stock) }}</x-badge>
                                @else
                                    <span class="font-semibold text-slate-100">{{ Num::quantity($stock) }}</span>
                                @endif
                                <span class="text-slate-400">{{ $product->unit }}</span>
                                @if ($product->units->isNotEmpty() && $stock > 0 && \App\Support\Features::enabled('business.multi-unit'))
                                    <div class="text-[11px] text-slate-400">{{ Num::unitBreakdown($stock, $product->unit, $product->units) }}</div>
                                @endif
                            </x-table.td>
                            <x-table.td align="right" class="tabular-nums text-slate-400">{{ Num::quantity($product->outletMinStock()) }}</x-table.td>
                            <x-table.td align="right">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                    @if ($this->canAdjust())
                                        <x-secondary-button size="xs" tone="emerald" wire:click="openAdjust({{ $product->id }}, 'stock_in')">
                                            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Masuk
                                        </x-secondary-button>
                                        <x-secondary-button size="xs" wire:click="openAdjust({{ $product->id }}, 'opname')">
                                            <i data-lucide="clipboard-check" class="w-3.5 h-3.5"></i> Opname
                                        </x-secondary-button>
                                    @endif
                                    <x-icon-button icon="history" :label="'Kartu stok '.$product->name" wire:click="$set('productFilter', {{ $product->id }}); $set('tab', 'movements')" />
                                </div>
                            </x-table.td>
                        </x-table.tr>
                    @endforeach
                </tbody>
            </x-table>
        @endif
    @else
        @if ($filteredProduct)
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="text-slate-400">Kartu stok:</span>
                <span class="font-semibold text-slate-100">{{ $filteredProduct->name }}</span>
                <span class="text-slate-400">· stok sekarang {{ Num::quantity($filteredProduct->outletStock()) }} {{ $filteredProduct->unit }}</span>
                <x-text-button size="sm" wire:click="$set('productFilter', null)">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i> Semua produk
                </x-text-button>
                @if ($this->canAdjust() && $filteredProduct->track_stock && ! $filteredProduct->trashed())
                    <x-secondary-button size="xs" tone="emerald" wire:click="openAdjust({{ $filteredProduct->id }}, 'stock_in')" class="ms-auto">Sesuaikan stok</x-secondary-button>
                @endif
            </div>

            @if ($filteredBatches->isNotEmpty())
                <div class="rounded-xl border border-slate-800 bg-slate-900/60 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-slate-400 text-left bg-slate-950/60">
                            <tr>
                                <th class="py-2 px-3 font-semibold">Batch</th>
                                <th class="py-2 px-3 font-semibold">Kedaluwarsa</th>
                                <th class="py-2 px-3 font-semibold text-right">Sisa</th>
                                <th class="py-2 px-3 font-semibold hidden sm:table-cell">Asal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            @foreach ($filteredBatches as $batch)
                                @php $daysLeft = $batch->expires_at ? today()->diffInDays($batch->expires_at, false) : null; @endphp
                                <tr wire:key="batch-{{ $batch->id }}">
                                    <td class="py-2 px-3 font-mono text-slate-200">{{ $batch->batch_number ?: 'Tanpa nomor' }}</td>
                                    <td class="py-2 px-3">
                                        @if ($batch->expires_at === null)
                                            <span class="text-slate-400">-</span>
                                        @elseif ($daysLeft < 0)
                                            <x-badge color="rose">KEDALUWARSA {{ $batch->expires_at->translatedFormat('d M Y') }}</x-badge>
                                        @elseif ($daysLeft <= \App\Support\PosSettings::expiryWarningDays())
                                            <x-badge color="amber">{{ $batch->expires_at->translatedFormat('d M Y') }} · {{ (int) $daysLeft }} hari</x-badge>
                                        @else
                                            <span class="text-slate-300">{{ $batch->expires_at->translatedFormat('d M Y') }}</span>
                                        @endif
                                    </td>
                                    <td @class(['py-2 px-3 text-right tabular-nums font-semibold', 'text-rose-400' => (float) $batch->quantity < 0, 'text-slate-100' => (float) $batch->quantity >= 0])>{{ Num::quantity((float) $batch->quantity) }} {{ $filteredProduct->unit }}</td>
                                    <td class="py-2 px-3 text-slate-400 hidden sm:table-cell">{{ $batch->source->label() }} · {{ $batch->received_at->translatedFormat('d M Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        @if ($movements->isEmpty())
            <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
                <x-empty-state icon="history" title="Belum ada mutasi stok" description="Penjualan, pembatalan, stok masuk, stok keluar, dan opname tercatat otomatis di sini." />
            </div>
        @else
            <x-table :pagination="$movements">
                <x-slot:header>
                    <tr>
                        <x-table.th>Waktu</x-table.th>
                        @unless ($productFilter)
                            <x-table.th>Produk</x-table.th>
                        @endunless
                        <x-table.th>Jenis</x-table.th>
                        <x-table.th align="right">Jumlah</x-table.th>
                        <x-table.th align="right">Stok Akhir</x-table.th>
                        <x-table.th>Keterangan</x-table.th>
                    </tr>
                </x-slot:header>
                <tbody class="divide-y divide-slate-800/60">
                    @foreach ($movements as $movement)
                        @php $qty = (float) $movement->quantity; @endphp
                        <x-table.tr wire:key="movement-{{ $movement->id }}">
                            <x-table.td class="text-slate-400 whitespace-nowrap">
                                <div>{{ $movement->created_at->translatedFormat('d M Y') }}</div>
                                <div class="text-[11px]">{{ $movement->created_at->format('H:i') }}</div>
                            </x-table.td>
                            @unless ($productFilter)
                                <x-table.td class="font-medium text-slate-100">{{ $movement->product?->name ?? '-' }}</x-table.td>
                            @endunless
                            <x-table.td><x-badge :color="$movement->type->color()">{{ strtoupper($movement->type->label()) }}</x-badge></x-table.td>
                            <x-table.td align="right" @class(['tabular-nums font-semibold', 'text-emerald-400' => $qty > 0, 'text-rose-400' => $qty < 0])>{{ $qty > 0 ? '+' : '' }}{{ Num::quantity($qty) }}</x-table.td>
                            <x-table.td align="right" class="tabular-nums text-slate-300">{{ Num::quantity($movement->stock_after) }}</x-table.td>
                            <x-table.td class="text-slate-400">
                                @if ($movement->reference_type === (new \App\Models\Sale)->getMorphClass() && $movement->reference_id)
                                    <x-feature-link :href="route('sales.show', $movement->reference_id)" wire:navigate class="font-mono text-emerald-400 hover:underline">{{ $movement->note }}</x-feature-link>
                                @elseif ($movement->reference_type === (new \App\Models\StockCount)->getMorphClass() && $movement->reference_id && auth()->user()->can('inventory.opname.count'))
                                    <x-feature-link :href="route('inventory.opname.show', $movement->reference_id)" wire:navigate class="font-mono text-emerald-400 hover:underline">{{ $movement->note }}</x-feature-link>
                                @else
                                    <span>{{ $movement->note ?: '-' }}</span>
                                @endif
                                @if ($movement->unit_cost)
                                    <div class="text-[11px]">Harga beli {{ Num::currency($movement->unit_cost) }}</div>
                                @endif
                                <div class="text-[11px]">{{ $movement->user?->name }}</div>
                            </x-table.td>
                        </x-table.tr>
                    @endforeach
                </tbody>
            </x-table>
        @endif
    @endif

    <x-modal name="stock-adjust" max-width="md" focusable>
        @if ($product = $this->adjustingProduct)
            <form wire:submit="{{ $adjustType === 'opname' && $product->tracksBatches() ? 'saveBatchOpname' : 'saveAdjustment' }}" class="p-5 sm:p-6 space-y-4">
                <x-modal-header :title="$product->name" icon="warehouse" closeable>
                    Stok sistem saat ini <strong class="text-slate-200">{{ Num::quantity($product->outletStock()) }} {{ $product->unit }}</strong>@if (app(\App\Support\CurrentOutlet::class)->isMultiOutlet() && $this->outlet) di {{ $this->outlet->name }}@endif
                </x-modal-header>

                <x-segmented class="w-full [&>*]:flex-1">
                    <x-tab-button size="sm" :active="$adjustType === 'stock_in'" wire:click="$set('adjustType', 'stock_in')">Masuk</x-tab-button>
                    <x-tab-button size="sm" :active="$adjustType === 'stock_out'" wire:click="$set('adjustType', 'stock_out')">Keluar</x-tab-button>
                    <x-tab-button size="sm" :active="$adjustType === 'opname'" wire:click="$set('adjustType', 'opname')">Opname</x-tab-button>
                </x-segmented>

                <p class="text-[11px] text-slate-400">
                    @if ($adjustType === 'stock_in')
                        Barang datang dari pemasok atau tambahan stok. Stok bertambah sebanyak jumlah di bawah.
                    @elseif ($adjustType === 'stock_out')
                        Barang rusak, kedaluwarsa, hilang, atau dipakai sendiri. Stok berkurang sebanyak jumlah di bawah.
                    @else
                        Isi hasil hitung fisik di rak. Sistem mencatat selisihnya otomatis.
                    @endif
                </p>

                @php
                    $adjustUnits = \App\Support\Features::enabled('business.multi-unit') && $adjustType !== 'opname' ? $product->units : collect();
                    $selectedUnit = $adjustUnits->firstWhere('id', (int) $adjustUnitId);
                    $unitLabel = $selectedUnit?->name ?? $product->unit;
                @endphp
                @if ($adjustUnits->isNotEmpty())
                    <div>
                        <x-input-label for="adjustUnitId" value="Satuan" />
                        <x-select id="adjustUnitId" wire:model.live="adjustUnitId">
                            <option value="">{{ $product->unit }} (satuan dasar)</option>
                            @foreach ($adjustUnits as $unitOption)
                                <option value="{{ $unitOption->id }}">{{ $unitOption->name }} = {{ Num::quantity((float) $unitOption->factor) }} {{ $product->unit }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('adjustUnitId')" class="mt-1.5" />
                    </div>
                @endif

                @if ($adjustType === 'opname' && $product->tracksBatches())
                    <div class="space-y-2">
                        <p class="text-[11px] text-slate-400">Hitung fisik tiap batch. Selisihnya dicatat per batch; total stok menjadi jumlah semua hitungan.</p>
                        @forelse ($this->adjustingBatches as $batch)
                            <div wire:key="opname-batch-{{ $batch->id }}" class="grid grid-cols-[1fr_8rem] gap-2 items-center rounded-lg border border-slate-800/80 px-3 py-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-mono text-slate-100 truncate">{{ $batch->label() }}</p>
                                    <p class="text-[11px] text-slate-400">Sistem: {{ Num::quantity((float) $batch->quantity) }} {{ $product->unit }}</p>
                                </div>
                                <x-text-input wire:model="opnameCounts.{{ $batch->id }}" :aria-label="'Hitung fisik batch '.$batch->label()" inputmode="decimal" class="w-full text-right font-mono tabular-nums" />
                            </div>
                        @empty
                            <p class="text-xs text-slate-400">Belum ada batch bersaldo di outlet ini.</p>
                        @endforelse
                        <div class="grid grid-cols-3 gap-2 rounded-lg border border-dashed border-slate-700 p-2.5">
                            <x-text-input wire:model="opnameExtraNumber" aria-label="Nomor batch yang belum tercatat" class="w-full font-mono" placeholder="Batch baru" />
                            <x-text-input wire:model="opnameExtraExpiresAt" aria-label="Kedaluwarsa batch baru" type="date" class="w-full" />
                            <x-text-input wire:model="opnameExtraQuantity" aria-label="Jumlah batch baru" inputmode="decimal" class="w-full text-right font-mono" placeholder="Jumlah" />
                        </div>
                        <x-input-error :messages="$errors->get('opnameCounts')" />
                        @foreach ($errors->get('opnameCounts.*') as $messages)
                            <x-input-error :messages="$messages" />
                        @endforeach
                    </div>
                @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div @class(['sm:col-span-2' => $adjustType !== 'stock_in'])>
                        <x-input-label for="adjustQuantity" :value="$adjustType === 'opname' ? 'Stok fisik ('.$product->unit.')' : 'Jumlah ('.$unitLabel.')'" />
                        <x-text-input wire:model="adjustQuantity" id="adjustQuantity" inputmode="decimal" class="w-full text-lg font-bold tabular-nums" placeholder="0" />
                        <x-input-error :messages="$errors->get('adjustQuantity')" class="mt-1.5" />
                    </div>
                    @if ($adjustType === 'stock_in')
                        <div>
                            <x-input-label for="adjustCost" :value="'Harga beli / '.$unitLabel" />
                            <x-text-input wire:model="adjustCost" id="adjustCost" inputmode="numeric" class="w-full tabular-nums" placeholder="Opsional" />
                            <x-input-error :messages="$errors->get('adjustCost')" class="mt-1.5" />
                        </div>
                    @endif
                </div>
                @endif
                @if ($adjustType === 'stock_in')
                    <p class="text-[11px] text-slate-400 -mt-2">Kalau diisi, HPP produk dihitung ulang dengan rata-rata tertimbang.</p>
                @endif

                @if ($product->tracksSerials() && $adjustType !== 'opname')
                    <div>
                        <x-input-label for="adjustSerials" :value="$adjustType === 'stock_in' ? 'Nomor seri / IMEI unit yang masuk *' : 'Nomor seri / IMEI unit yang keluar *'" />
                        <x-textarea wire:model="adjustSerials" id="adjustSerials" rows="4" class="w-full font-mono" placeholder="Satu nomor per baris, jumlahnya sama dengan jumlah unit" />
                        <x-input-error :messages="$errors->get('adjustSerials')" class="mt-1.5" />
                    </div>
                @endif

                @if ($product->tracksBatches())
                    @if ($adjustType === 'stock_in')
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <x-input-label for="adjustBatchNumber" value="Nomor batch *" />
                                <x-text-input wire:model="adjustBatchNumber" id="adjustBatchNumber" class="w-full font-mono" placeholder="Lihat kemasan" />
                                <x-input-error :messages="$errors->get('adjustBatchNumber')" class="mt-1.5" />
                            </div>
                            <div>
                                <x-input-label for="adjustExpiresAt" value="Tanggal kedaluwarsa" />
                                <x-text-input wire:model="adjustExpiresAt" id="adjustExpiresAt" type="date" class="w-full" />
                                <x-input-error :messages="$errors->get('adjustExpiresAt')" class="mt-1.5" />
                            </div>
                        </div>
                    @elseif ($adjustType === 'stock_out' && $this->adjustingBatches->isNotEmpty())
                        <div>
                            <x-input-label for="adjustBatchId" value="Ambil dari batch" />
                            <x-select id="adjustBatchId" wire:model="adjustBatchId">
                                <option value="">Otomatis (kedaluwarsa paling awal)</option>
                                @foreach ($this->adjustingBatches as $batch)
                                    <option value="{{ $batch->id }}">{{ $batch->label() }} · sisa {{ Num::quantity((float) $batch->quantity) }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif
                @endif

                <div>
                    <x-input-label for="adjustNote" :value="$adjustType === 'stock_out' ? 'Alasan *' : 'Catatan'" />
                    <x-text-input wire:model="adjustNote" id="adjustNote" class="w-full" :placeholder="$adjustType === 'stock_in' ? 'Mis. dari Toko Grosir Jaya, nota 123' : 'Mis. 2 pcs pecah'" />
                    <x-input-error :messages="$errors->get('adjustNote')" class="mt-1.5" />
                </div>

                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="saveAdjustment,saveBatchOpname" loading="Menyimpan...">Simpan Mutasi Stok</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        @endif
    </x-modal>
</div>
