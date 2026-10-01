@php
    use App\Enums\StockMovementType;
    use App\Support\NumberFormatter as Num;
    $summary = $this->summary;
@endphp

<div class="space-y-4 sm:space-y-6">
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
                    @foreach ($products as $product)
                        @php $stock = (float) $product->stock; @endphp
                        <x-table.tr wire:key="stock-{{ $product->id }}">
                            <x-table.td>
                                <div class="font-semibold text-slate-100">{{ $product->name }}</div>
                                <div class="text-[11px] text-slate-400 font-mono">{{ $product->sku }}{{ $product->category ? ' · '.$product->category->name : '' }}</div>
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
                            </x-table.td>
                            <x-table.td align="right" class="tabular-nums text-slate-400">{{ Num::quantity($product->min_stock) }}</x-table.td>
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
                <span class="text-slate-400">· stok sekarang {{ Num::quantity($filteredProduct->stock) }} {{ $filteredProduct->unit }}</span>
                <x-text-button size="sm" wire:click="$set('productFilter', null)">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i> Semua produk
                </x-text-button>
                @if ($this->canAdjust() && $filteredProduct->track_stock && ! $filteredProduct->trashed())
                    <x-secondary-button size="xs" tone="emerald" wire:click="openAdjust({{ $filteredProduct->id }}, 'stock_in')" class="ms-auto">Sesuaikan stok</x-secondary-button>
                @endif
            </div>
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
            <form wire:submit="saveAdjustment" class="p-5 sm:p-6 space-y-4">
                <x-modal-header :title="$product->name" icon="warehouse" closeable>
                    Stok sistem saat ini <strong class="text-slate-200">{{ Num::quantity($product->stock) }} {{ $product->unit }}</strong>
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

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div @class(['sm:col-span-2' => $adjustType !== 'stock_in'])>
                        <x-input-label for="adjustQuantity" :value="$adjustType === 'opname' ? 'Stok fisik ('.$product->unit.')' : 'Jumlah ('.$product->unit.')'" />
                        <x-text-input wire:model="adjustQuantity" id="adjustQuantity" inputmode="decimal" class="w-full text-lg font-bold tabular-nums" placeholder="0" />
                        <x-input-error :messages="$errors->get('adjustQuantity')" class="mt-1.5" />
                    </div>
                    @if ($adjustType === 'stock_in')
                        <div>
                            <x-input-label for="adjustCost" value="Harga beli / unit" />
                            <x-text-input wire:model="adjustCost" id="adjustCost" inputmode="numeric" class="w-full tabular-nums" placeholder="Opsional" />
                            <x-input-error :messages="$errors->get('adjustCost')" class="mt-1.5" />
                        </div>
                    @endif
                </div>
                @if ($adjustType === 'stock_in')
                    <p class="text-[11px] text-slate-400 -mt-2">Kalau diisi, HPP produk dihitung ulang dengan rata-rata tertimbang.</p>
                @endif

                <div>
                    <x-input-label for="adjustNote" :value="$adjustType === 'stock_out' ? 'Alasan *' : 'Catatan'" />
                    <x-text-input wire:model="adjustNote" id="adjustNote" class="w-full" :placeholder="$adjustType === 'stock_in' ? 'Mis. dari Toko Grosir Jaya, nota 123' : 'Mis. 2 pcs pecah'" />
                    <x-input-error :messages="$errors->get('adjustNote')" class="mt-1.5" />
                </div>

                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="saveAdjustment" loading="Menyimpan...">Simpan Mutasi Stok</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        @endif
    </x-modal>
</div>
