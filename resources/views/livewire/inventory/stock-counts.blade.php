@php
    use App\Enums\StockCountScope;
    use App\Enums\StockCountStatus;
    use App\Livewire\Inventory\StockCounts;
    use App\Support\NumberFormatter as Num;
    $canManage = $this->canManage();
@endphp

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <p class="text-xs text-slate-400">Hitung stok fisik bersama-sama tanpa menutup toko. Stok baru disesuaikan sebesar selisihnya setelah opname diselesaikan.</p>
        <div class="flex flex-wrap items-center gap-2">
            <x-segmented class="flex-1 sm:flex-none sm:inline-flex [&>*]:flex-1" aria-label="Status opname">
                @foreach (StockCounts::TABS as $key => $label)
                    <x-tab-button size="sm" :active="$tab === $key" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</x-tab-button>
                @endforeach
            </x-segmented>
            @if ($canManage)
                <x-primary-button size="sm" type="button" wire:click="openStart" class="flex-1 sm:flex-none">
                    <i data-lucide="plus" class="w-4 h-4"></i> <span>Mulai Opname</span>
                </x-primary-button>
            @endif
        </div>
    </div>

    @if ($counts->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
            <x-empty-state icon="clipboard-check" :title="$tab === 'open' ? 'Tidak ada opname yang berjalan' : 'Belum ada opname di sini'" description="Setiap opname tercatat sebagai dokumen bernomor lengkap dengan siapa yang menghitung dan selisihnya.">
                @if ($canManage && $tab === 'open')
                    <x-primary-button size="sm" type="button" wire:click="openStart" class="mt-4">
                        <i data-lucide="plus" class="w-4 h-4"></i> <span>Mulai Opname</span>
                    </x-primary-button>
                @endif
            </x-empty-state>
        </div>
    @else
        <x-table :pagination="$counts">
            <x-slot:header>
                <tr>
                    <x-table.th>Nomor</x-table.th>
                    @if ($multiOutlet)
                        <x-table.th>Outlet</x-table.th>
                    @endif
                    <x-table.th>Lingkup</x-table.th>
                    <x-table.th>Progres</x-table.th>
                    <x-table.th align="right">Selisih</x-table.th>
                    <x-table.th>Status</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($counts as $count)
                    @php
                        $percent = $count->items_count > 0 ? (int) floor($count->counted_items_count * 100 / $count->items_count) : 0;
                        $net = $count->summary ? (int) ($count->summary['surplus_value'] ?? 0) - (int) ($count->summary['shortage_value'] ?? 0) : null;
                    @endphp
                    <x-table.tr wire:key="stock-count-{{ $count->id }}">
                        <x-table.td>
                            <a href="{{ route('inventory.opname.show', $count) }}" wire:navigate class="font-mono font-semibold text-slate-100 hover:text-emerald-400">{{ $count->number }}</a>
                            <div class="text-[11px] text-slate-400">{{ $count->created_at->translatedFormat('d M Y H:i') }} · {{ $count->creator?->name ?? 'Pengguna terhapus' }}</div>
                        </x-table.td>
                        @if ($multiOutlet)
                            <x-table.td data-label="Outlet" class="text-slate-300">{{ $count->outlet?->name }}</x-table.td>
                        @endif
                        <x-table.td data-label="Lingkup" class="text-slate-300">
                            @if ($count->scope === StockCountScope::All)
                                Semua barang
                            @elseif ($count->scope === StockCountScope::Categories)
                                {{ count($count->scope_category_ids ?? []) }} kategori
                            @else
                                {{ Num::quantity($count->items_count) }} barang
                            @endif
                        </x-table.td>
                        <x-table.td data-label="Progres">
                            <div class="flex items-center gap-2 min-w-[140px]">
                                <div class="h-1.5 flex-1 rounded-full bg-slate-800 overflow-hidden" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progres hitung {{ $count->number }}">
                                    <div class="h-full bg-emerald-500" style="width: {{ $percent }}%"></div>
                                </div>
                                <span class="text-[11px] tabular-nums text-slate-300">{{ Num::quantity($count->counted_items_count) }}/{{ Num::quantity($count->items_count) }}</span>
                            </div>
                        </x-table.td>
                        <x-table.td data-label="Selisih" align="right" class="tabular-nums">
                            @if ($net === null)
                                <span class="text-slate-500">-</span>
                            @else
                                <span @class(['text-rose-400' => $net < 0, 'text-emerald-400' => $net > 0, 'text-slate-300' => $net === 0])>{{ $net > 0 ? '+' : '' }}{{ Num::currency($net) }}</span>
                            @endif
                        </x-table.td>
                        <x-table.td data-label="Status">
                            <x-badge :color="$count->status->color()">{{ $count->status->label() }}</x-badge>
                        </x-table.td>
                        <x-table.td data-label="Aksi" align="right">
                            <a href="{{ route('inventory.opname.show', $count) }}" wire:navigate aria-label="Buka {{ $count->number }}" title="Buka {{ $count->number }}" class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40 transition">
                                <i data-lucide="arrow-right" aria-hidden="true" class="w-4 h-4"></i>
                            </a>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    @if ($canManage)
        <x-record-form-modal name="stock-count-start" title="Mulai Opname" subtitle="Stok tidak berubah sampai opname diselesaikan" icon="clipboard-check" max-width="2xl" close-action="closeStart">
            <form wire:submit="start" class="space-y-4">
                <div>
                    <x-input-label value="Barang apa yang mau dihitung?" />
                    <x-segmented class="w-full [&>*]:flex-1" aria-label="Lingkup opname">
                        @foreach (StockCountScope::cases() as $option)
                            <x-tab-button size="sm" :active="$scope === $option->value" wire:click="$set('scope', '{{ $option->value }}')">{{ $option->label() }}</x-tab-button>
                        @endforeach
                    </x-segmented>
                    <x-input-error :messages="$errors->get('scope')" class="mt-1.5" />
                </div>

                @if ($scope === StockCountScope::Categories->value)
                    <div>
                        <x-input-label value="Kategori" />
                        @if ($this->categories->isEmpty())
                            <p class="text-xs text-slate-400">Belum ada kategori. Pakai "Pilih barang" atau "Semua barang".</p>
                        @else
                            <div class="grid sm:grid-cols-2 gap-1 max-h-56 overflow-y-auto rounded-lg border border-slate-800 p-2">
                                @foreach ($this->categories as $category)
                                    <x-checkbox wire:model="categoryIds" value="{{ $category->id }}" :label="$category->name" wire:key="category-{{ $category->id }}" />
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if ($scope === StockCountScope::Products->value)
                    <div>
                        <x-input-label for="count-product-search" value="Cari atau scan barang" />
                        <x-search-input wire:model.live.debounce.300ms="productSearch" wire:keydown.enter.prevent="pickFirstMatch" id="count-product-search" placeholder="Nama, SKU, atau barcode..." />
                        @if ($this->productMatches->isNotEmpty())
                            <ul class="mt-1.5 rounded-lg border border-slate-800 divide-y divide-slate-800/60 overflow-hidden">
                                @foreach ($this->productMatches as $match)
                                    <li wire:key="pick-{{ $match->id }}">
                                        <button type="button" wire:click="pickProduct({{ $match->id }})" class="w-full min-h-[44px] px-3 py-2 flex items-center justify-between gap-3 text-left text-xs hover:bg-slate-800/60">
                                            <span class="font-semibold text-slate-100 truncate">{{ $match->name }}</span>
                                            <span class="shrink-0 font-mono text-[11px] text-slate-400">{{ $match->sku }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($pickedProducts !== [])
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($pickedProducts as $picked)
                                    <span wire:key="picked-{{ $picked['id'] }}" class="inline-flex items-center gap-1 rounded-lg border border-slate-700 bg-slate-950 pl-2.5 text-xs text-slate-200">
                                        {{ $picked['name'] }}
                                        <x-icon-button icon="x" :label="'Hapus '.$picked['name']" wire:click="unpickProduct({{ $picked['id'] }})" />
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                <details class="rounded-xl border border-slate-800 bg-slate-900/40 px-3 py-2 group">
                    <summary class="min-h-[44px] flex items-center gap-2 text-xs font-semibold text-slate-300 cursor-pointer select-none">
                        <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Atur
                    </summary>
                    <div class="space-y-2 pb-2">
                        <x-checkbox-card wire:model="blindCount" label="Sembunyikan stok sistem dari penghitung" description="Penghitung tidak melihat angka sistem, supaya hasilnya benar-benar hitungan fisik. Pengelola opname tetap melihatnya." />
                        <x-checkbox-card wire:model.live="holdAdjustments" label="Tahan stok masuk/keluar manual selama opname" description="Stok masuk/keluar, opname cepat, dan transfer untuk barang ini ditolak sampai opname selesai. Penjualan tetap jalan." />
                    </div>
                </details>

                <div>
                    <x-input-label for="count-note" value="Catatan" />
                    <x-text-input wire:model="note" id="count-note" class="w-full" placeholder="Mis. Opname akhir bulan" />
                    <x-input-error :messages="$errors->get('note')" class="mt-1.5" />
                </div>

                <x-modal-actions>
                    <x-secondary-button type="button" wire:click="closeStart">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="start" loading="Menyiapkan...">Mulai Menghitung</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </x-record-form-modal>
    @endif
</div>
