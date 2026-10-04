@php use App\Support\NumberFormatter as Num; @endphp

<div class="space-y-4 sm:space-y-6">
    <x-list-toolbar
        description="Harga jual, HPP, barcode, dan batas stok minimum. Produk nonaktif disembunyikan dari kasir."
        search-placeholder="Cari nama, SKU, atau barcode..."
        :can-manage="$this->canManage()"
        :can-export="true"
        add-label="Tambah Produk"
    >
        <x-slot:filters>
            <x-select variant="filter" wire:model.live="categoryFilter" aria-label="Filter kategori" class="flex-1 sm:flex-none">
                <option value="">Semua kategori</option>
                <option value="none">Tanpa kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-select>
            <x-select variant="filter" wire:model.live="statusFilter" aria-label="Filter status" class="flex-1 sm:flex-none">
                <option value="">Semua status</option>
                <option value="active">Aktif</option>
                <option value="inactive">Nonaktif</option>
                <option value="low">Stok menipis</option>
            </x-select>
        </x-slot:filters>
        <x-slot:actions>
            <x-secondary-button size="sm" type="button" wire:click="openImportModal" class="flex-1 sm:flex-none shrink-0">
                <i data-lucide="file-spreadsheet" class="w-4 h-4 mr-1.5 text-emerald-400"></i>
                <span>Import Excel/CSV</span>
            </x-secondary-button>
        </x-slot:actions>
    </x-list-toolbar>

    @if ($products->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state
                icon="package"
                title="{{ $search || $categoryFilter || $statusFilter ? 'Tidak ada produk yang cocok' : 'Belum ada produk' }}"
                description="{{ $search || $categoryFilter || $statusFilter ? 'Ubah kata kunci atau filter.' : 'Tambahkan produk pertama lewat tombol Tambah Produk, lalu produk langsung muncul di kasir.' }}"
            />
        </div>
    @else
        <x-table :pagination="$products">
            <x-slot:header>
                <tr>
                    <x-table.th sortable field="name">Produk</x-table.th>
                    <x-table.th class="hidden xl:table-cell">Kategori</x-table.th>
                    <x-table.th sortable field="price" align="right">Harga Jual</x-table.th>
                    <x-table.th sortable field="cost_price" align="right" class="hidden lg:table-cell">HPP</x-table.th>
                    <x-table.th sortable field="stock" align="right">Stok</x-table.th>
                    <x-table.th sortable field="is_active">Status</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($products as $product)
                    @php
                        $margin = $product->price - $product->cost_price;
                    @endphp
                    <x-table.tr wire:key="product-{{ $product->id }}">
                        <x-table.td>
                            <div class="flex items-center gap-3 min-w-0">
                                @if ($imageUrl = $product->imageUrl())
                                    <img src="{{ $imageUrl }}" alt="" loading="lazy" class="w-10 h-10 rounded-lg object-cover bg-slate-800 shrink-0">
                                @else
                                    <span class="w-10 h-10 rounded-lg bg-slate-800 border border-slate-700/60 text-slate-300 text-xs font-bold flex items-center justify-center uppercase shrink-0">{{ mb_substr($product->name, 0, 2) }}</span>
                                @endif
                                <div class="min-w-0">
                                    <div class="font-semibold text-slate-100 truncate">{{ $product->name }}</div>
                                    <div class="text-[11px] text-slate-400 font-mono truncate">{{ $product->sku }}{{ $product->barcode ? ' · '.$product->barcode : '' }}</div>
                                </div>
                            </div>
                        </x-table.td>
                        <x-table.td class="text-slate-400 hidden xl:table-cell">{{ $product->category?->name ?? '-' }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums">
                            <div class="font-semibold text-slate-100">{{ Num::currency($product->price) }}<span class="text-slate-400 font-normal">/{{ $product->unit }}</span></div>
                            @if ($product->cost_price > 0)
                                <div @class(['text-[11px]', 'text-rose-400' => $margin < 0, 'text-slate-400' => $margin >= 0])>
                                    {{ $margin < 0 ? 'Rugi ' : 'Margin ' }}{{ Num::currency(abs($margin)) }}
                                </div>
                            @endif
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-400 hidden lg:table-cell">{{ Num::currency($product->cost_price) }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums whitespace-nowrap">
                            @if (! $product->track_stock)
                                <span class="text-slate-400">Tidak dilacak</span>
                            @elseif ((float) $product->stock <= 0)
                                <x-badge color="rose">HABIS {{ Num::quantity($product->stock) }}</x-badge>
                            @elseif ($product->isLowStock())
                                <x-badge color="amber">{{ Num::quantity($product->stock) }} {{ $product->unit }}</x-badge>
                            @else
                                <span class="text-slate-200 font-semibold">{{ Num::quantity($product->stock) }}</span> <span class="text-slate-400">{{ $product->unit }}</span>
                            @endif
                        </x-table.td>
                        <x-table.td>
                            @if ($this->canManage())
                                <button type="button" wire:click="toggleActive({{ $product->id }})" title="{{ $product->is_active ? 'Sembunyikan dari kasir' : 'Jual lagi di kasir' }}" class="min-h-[44px] sm:min-h-0">
                                    <x-status-badge :active="$product->is_active" />
                                </button>
                            @else
                                <x-status-badge :active="$product->is_active" />
                            @endif
                        </x-table.td>
                        <x-table.td align="right">
                            <div class="flex items-center justify-end gap-1">
                                @if ($product->track_stock)
                                    <x-feature-link :href="route('inventory.stock', ['product' => $product->id])" wire:navigate hide-when-disabled
                                        class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800" title="Kartu stok {{ $product->name }}">
                                        <i data-lucide="warehouse" class="w-4 h-4"></i>
                                    </x-feature-link>
                                @endif
                                @if ($this->canManage())
                                    <x-icon-button icon="pencil" :label="'Edit '.$product->name" tone="edit" @click="$dispatch('open-modal', 'record-form')" wire:click="openEditModal({{ $product->id }})" />
                                    <x-icon-button icon="trash" :label="'Hapus '.$product->name" tone="danger" @click="$dispatch('open-modal', 'confirm-delete')" wire:click="confirmDelete({{ $product->id }})" />
                                @endif
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal
        :title="$editingId ? 'Edit Produk' : 'Tambah Produk'"
        subtitle="Harga, kode, dan pengaturan stok produk"
        icon="package"
        max-width="4xl"
    >
        <form wire:submit="save" class="space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 items-start">
                <div class="space-y-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 pb-1.5 border-b border-slate-800/80">Identitas</p>

                    <div>
                        <x-input-label for="name" value="Nama Produk *" />
                        <x-text-input wire:model="name" id="name" class="w-full" placeholder="Contoh: Kopi Susu Gula Aren 250ml" autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <x-input-label for="category_id" value="Kategori" />
                            <x-select id="category_id" wire:model="category_id">
                                <option value="">Tanpa kategori</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('category_id')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="unit" value="Satuan *" />
                            <x-text-input wire:model="unit" id="unit" list="unit-options" class="w-full" placeholder="pcs" />
                            <datalist id="unit-options">
                                @foreach (\App\Livewire\MasterData\Products::UNITS as $unitOption)
                                    <option value="{{ $unitOption }}"></option>
                                @endforeach
                            </datalist>
                            <x-input-error :messages="$errors->get('unit')" class="mt-1.5" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <x-input-label for="sku" value="SKU / Kode" />
                            <x-text-input wire:model="sku" id="sku" class="w-full font-mono uppercase" placeholder="Kosongkan = otomatis" />
                            <x-input-error :messages="$errors->get('sku')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="barcode" value="Barcode" />
                            <x-text-input wire:model="barcode" id="barcode" class="w-full font-mono" placeholder="Scan atau ketik" />
                            <x-input-error :messages="$errors->get('barcode')" class="mt-1.5" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="image" value="Foto (opsional)" />
                        <div class="flex items-center gap-3">
                            @if ($image && method_exists($image, 'isPreviewable') && $image->isPreviewable())
                                <img src="{{ $image->temporaryUrl() }}" alt="" class="w-14 h-14 rounded-lg object-cover shrink-0">
                            @elseif ($currentImageUrl && ! $removeImage)
                                <img src="{{ $currentImageUrl }}" alt="" class="w-14 h-14 rounded-lg object-cover shrink-0">
                            @endif
                            <x-file-input wire:model="image" id="image" accept="image/*" :file="$image" icon="image" label="Pilih foto produk" hint="JPG/PNG, maks. 2 MB" class="flex-1" />
                        </div>
                        @if ($currentImageUrl && ! $image)
                            <label class="inline-flex items-center gap-2 mt-2 text-[11px] text-slate-400 min-h-[44px] sm:min-h-0">
                                <x-checkbox wire:model.live="removeImage" />
                                Hapus foto saat ini
                            </label>
                        @endif
                        <x-input-error :messages="$errors->get('image')" class="mt-1.5" />
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="space-y-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60" x-data="{ price: @entangle('price'), cost: @entangle('cost_price') }">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 pb-1.5 border-b border-slate-800/80">Harga</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <x-input-label for="price" value="Harga Jual *" />
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 pointer-events-none">Rp</span>
                                    <x-text-input x-model="price" id="price" inputmode="numeric" class="w-full pl-9 font-mono font-bold" placeholder="0" />
                                </div>
                                <x-input-error :messages="$errors->get('price')" class="mt-1.5" />
                            </div>
                            <div>
                                <x-input-label for="cost_price" value="Harga Modal (HPP)" />
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 pointer-events-none">Rp</span>
                                    <x-text-input x-model="cost" id="cost_price" inputmode="numeric" class="w-full pl-9 font-mono" placeholder="0" />
                                </div>
                                <x-input-error :messages="$errors->get('cost_price')" class="mt-1.5" />
                            </div>
                        </div>
                        <p class="text-[11px]" x-show="Number(price) > 0 && Number(cost) > 0"
                            :class="Number(price) < Number(cost) ? 'text-rose-400' : 'text-slate-400'"
                            x-text="Number(price) < Number(cost)
                                ? `Harga jual di bawah modal (rugi Rp${new Intl.NumberFormat('id-ID').format(Number(cost) - Number(price))} per unit).`
                                : `Margin Rp${new Intl.NumberFormat('id-ID').format(Number(price) - Number(cost))} (${Math.round((Number(price) - Number(cost)) / Number(price) * 100)}% dari harga jual).`"></p>
                        <p class="text-[11px] text-slate-400">HPP dipakai untuk menghitung laba kotor di laporan; ikut diperbarui otomatis saat mencatat stok masuk dengan harga beli.</p>
                    </div>

                    <div class="space-y-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 pb-1.5 border-b border-slate-800/80">Stok & Status</p>
                        <x-checkbox-card wire:model.live="track_stock" label="Lacak stok" description="Matikan untuk jasa atau barang yang stoknya tidak dihitung (mis. menu racikan)." />

                        @if ($track_stock)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                                <div>
                                    <x-input-label for="stock" :value="$editingId ? 'Stok Saat Ini' : 'Stok Awal *'" />
                                    <x-text-input wire:model="stock" id="stock" inputmode="decimal" class="w-full font-mono" :disabled="(bool) $editingId" />
                                    @if ($editingId)
                                        <p class="text-[11px] text-slate-400 mt-1">Ubah lewat menu Stok Barang supaya tercatat di kartu stok.</p>
                                    @endif
                                    <x-input-error :messages="$errors->get('stock')" class="mt-1.5" />
                                </div>
                                <div>
                                    <x-input-label for="min_stock" value="Batas Stok Minimum" />
                                    <x-text-input wire:model="min_stock" id="min_stock" inputmode="decimal" class="w-full font-mono" />
                                    <p class="text-[11px] text-slate-400 mt-1">Ditandai "menipis" di dashboard saat stok mencapai angka ini.</p>
                                    <x-input-error :messages="$errors->get('min_stock')" class="mt-1.5" />
                                </div>
                            </div>
                        @endif

                        <x-checkbox-card wire:model="is_active" label="Dijual di kasir" description="Produk nonaktif tetap tersimpan beserta riwayat penjualannya." />
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-800/80 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2.5 [&>*]:w-full sm:[&>*]:w-auto">
                <x-secondary-button type="button" wire:click="closeModal">Batal</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save,image">
                    <x-loading-label target="save" loading="Menyimpan...">{{ $editingId ? 'Perbarui Produk' : 'Simpan Produk' }}</x-loading-label>
                </x-primary-button>
            </div>
        </form>
    </x-record-form-modal>

    <x-confirm-delete-modal title="Hapus produk ini?" description="Riwayat penjualan produk ini tetap tersimpan. Kalau hanya ingin berhenti menjual, cukup nonaktifkan." />

    {{-- Modal Import Produk --}}
    <x-modal name="import-products-modal" max-width="lg">
        <div class="p-6">
            <x-modal-header title="Import Produk dari Excel / CSV" icon="file-spreadsheet" />

            <div class="mt-4 space-y-4 text-sm text-slate-300">
                <p>
                    Unggah daftar produk Anda sekaligus menggunakan file Excel (.xlsx, .xls) atau CSV.
                    Jika kategori belum ada, sistem akan membuatnya secara otomatis.
                </p>

                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 flex items-center justify-between gap-4">
                    <div>
                        <div class="font-semibold text-white">Template Format Import</div>
                        <p class="text-xs text-slate-400 mt-0.5">Gunakan format kolom yang telah disesuaikan.</p>
                    </div>
                    <x-secondary-button size="xs" wire:click="downloadTemplate">
                        <i data-lucide="download" class="w-3.5 h-3.5 mr-1.5 text-emerald-400"></i>
                        Unduh Template (.CSV)
                    </x-secondary-button>
                </div>

                <div>
                    <x-input-label for="importFile" value="Pilih File Excel / CSV" />
                    <x-file-input wire:model="importFile" id="importFile" accept=".xlsx,.xls,.csv,.txt" class="mt-1" />
                    <x-input-error :messages="$errors->get('importFile')" class="mt-1.5" />
                </div>

                @if ($importSummary)
                    <div class="rounded-xl border {{ $importSummary['imported'] > 0 ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-rose-500/30 bg-rose-500/10' }} p-4 space-y-2">
                        <div class="flex items-center gap-2 text-sm font-bold {{ $importSummary['imported'] > 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                            <i data-lucide="{{ $importSummary['imported'] > 0 ? 'check-circle-2' : 'alert-circle' }}" class="w-4 h-4"></i>
                            <span>{{ $importSummary['imported'] }} produk berhasil diimpor.</span>
                            @if ($importSummary['skipped'] > 0)
                                <span class="text-slate-400 font-normal">({{ $importSummary['skipped'] }} dilewati)</span>
                            @endif
                        </div>

                        @if ($importSummary['errors'])
                            <div class="text-xs text-rose-200 mt-2 max-h-32 overflow-y-auto space-y-1">
                                @foreach ($importSummary['errors'] as $err)
                                    <div>• {{ $err }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close-modal', 'import-products-modal')">
                    Tutup
                </x-secondary-button>
                <x-primary-button wire:click="processImport" wire:loading.attr="disabled" wire:target="processImport,importFile">
                    <x-loading-label target="processImport" loading="Mengimpor...">
                        <i data-lucide="upload" class="w-4 h-4 mr-1.5"></i>
                        Mulai Import
                    </x-loading-label>
                </x-primary-button>
            </div>
        </div>
    </x-modal>
</div>
