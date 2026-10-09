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
                        $price = $product->effectivePrice();
                        $margin = $price - $product->cost_price;
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
                                    <div class="font-semibold text-slate-100 truncate flex items-center gap-1.5">
                                        <span class="truncate">{{ $product->name }}</span>
                                        @if ($product->requires_prescription && \App\Support\Features::enabled('business.prescription'))
                                            <x-badge color="rose">RESEP</x-badge>
                                        @endif
                                    </div>
                                    <div class="text-[11px] text-slate-400 font-mono truncate">{{ $product->sku }}{{ $product->barcode ? ' · '.$product->barcode : '' }}</div>
                                </div>
                            </div>
                        </x-table.td>
                        <x-table.td class="text-slate-400 hidden xl:table-cell">{{ $product->category?->name ?? '-' }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums">
                            <div class="font-semibold text-slate-100">{{ Num::currency($price) }}<span class="text-slate-400 font-normal">/{{ $product->unit }}</span></div>
                            @if ($product->hasOutletPrice())
                                <div class="text-[11px] text-sky-400">Harga khusus outlet</div>
                            @endif
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
                            @elseif ($product->outletStock() <= 0)
                                <x-badge color="rose">HABIS {{ Num::quantity($product->outletStock()) }}</x-badge>
                            @elseif ($product->isLowStock())
                                <x-badge color="amber">{{ Num::quantity($product->outletStock()) }} {{ $product->unit }}</x-badge>
                            @else
                                <span class="text-slate-200 font-semibold">{{ Num::quantity($product->outletStock()) }}</span> <span class="text-slate-400">{{ $product->unit }}</span>
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
                                @foreach ($this->unitSuggestions() as $unitOption)
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

                        @if (($outletChoices = $this->outletChoices())->isNotEmpty())
                            <details class="group rounded-lg border border-slate-800/80 bg-slate-950/40" @if (collect($outletPrices)->filter()->isNotEmpty() || $errors->has('outletPrices.*')) open @endif>
                                <summary class="min-h-[44px] px-3 flex items-center justify-between cursor-pointer text-xs font-semibold text-slate-200">
                                    <span>Harga per outlet</span>
                                    <span class="text-[11px] font-normal text-slate-400">Opsional</span>
                                </summary>
                                <div class="px-3 pb-3 space-y-2.5">
                                    <p class="text-[11px] text-slate-400">Harga jual di atas berlaku untuk semua outlet. Isi harga di bawah hanya untuk outlet yang harganya berbeda; kosongkan untuk mengikuti harga bawaan.</p>
                                    @foreach ($outletChoices as $choice)
                                        <div wire:key="outlet-price-{{ $choice->id }}" class="grid grid-cols-[minmax(0,1fr)_9rem] items-center gap-3">
                                            <x-input-label :for="'outlet-price-'.$choice->id" :value="$choice->name" class="!mb-0 truncate" />
                                            <x-text-input wire:model="outletPrices.{{ $choice->id }}" :id="'outlet-price-'.$choice->id" inputmode="numeric" class="w-full font-mono text-right" placeholder="Ikuti bawaan" />
                                        </div>
                                        <x-input-error :messages="$errors->get('outletPrices.'.$choice->id)" />
                                    @endforeach
                                </div>
                            </details>
                        @endif
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

                            @if (($outletChoices = $this->outletChoices())->isNotEmpty())
                                <details class="group rounded-lg border border-slate-800/80 bg-slate-950/40" @if (collect($outletMinStocks)->filter()->isNotEmpty()) open @endif>
                                    <summary class="min-h-[44px] px-3 flex items-center justify-between cursor-pointer text-xs font-semibold text-slate-200">
                                        <span>Stok minimum per outlet</span>
                                        <span class="text-[11px] font-normal text-slate-400">Opsional</span>
                                    </summary>
                                    <div class="px-3 pb-3 space-y-2.5">
                                        @foreach ($outletChoices as $choice)
                                            <div wire:key="outlet-min-{{ $choice->id }}" class="grid grid-cols-[minmax(0,1fr)_9rem] items-center gap-3">
                                                <x-input-label :for="'outlet-min-'.$choice->id" :value="$choice->name" class="!mb-0 truncate" />
                                                <x-text-input wire:model="outletMinStocks.{{ $choice->id }}" :id="'outlet-min-'.$choice->id" inputmode="decimal" class="w-full font-mono text-right" placeholder="Ikuti bawaan" />
                                            </div>
                                            <x-input-error :messages="$errors->get('outletMinStocks.'.$choice->id)" />
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        @endif

                        <x-checkbox-card wire:model="is_active" label="Dijual di kasir" description="Produk nonaktif tetap tersimpan beserta riwayat penjualannya." />
                    </div>
                </div>
            </div>

            @php
                $attributeFields = $this->attributeFields();
                $showPharmacy = \App\Support\Features::enabled('business.prescription');
                $showBatch = \App\Support\Features::enabled('business.batch-expiry') && $track_stock;
                $showUnits = \App\Support\Features::enabled('business.multi-unit');
                $showTiers = \App\Support\Features::enabled('business.tiered-price');
                $showModifiers = \App\Support\Features::enabled('business.modifiers');
                $showComponents = \App\Support\Features::enabled('business.components') && ! $track_stock;
                $showSerial = \App\Support\Features::enabled('business.serial-number');
                $showVariants = \App\Support\Features::enabled('business.variants');
            @endphp
            @if ($attributeFields || $showPharmacy || $showBatch || $showUnits || $showTiers || $showModifiers || $showComponents || $showSerial || $showVariants)
                <div class="space-y-4 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 pb-1.5 border-b border-slate-800/80">Khusus usaha</p>

                    @if ($showPharmacy)
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 items-start">
                            <div>
                                <x-input-label for="drug_class" value="Golongan obat" />
                                <x-select id="drug_class" wire:model.live="drug_class">
                                    <option value="">Bukan obat / tidak diatur</option>
                                    @foreach (\App\Enums\DrugClass::cases() as $class)
                                        <option value="{{ $class->value }}">{{ $class->label() }}</option>
                                    @endforeach
                                </x-select>
                                <x-input-error :messages="$errors->get('drug_class')" class="mt-1.5" />
                            </div>
                            <x-checkbox-card wire:model="requires_prescription" label="Wajib resep dokter" description="Kasir tidak bisa menjual produk ini tanpa resep yang tertaut." />
                        </div>
                    @endif

                    @if ($attributeFields)
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
                            @foreach ($attributeFields as $field)
                                <div wire:key="attr-{{ $field->key }}">
                                    @if ($field->type === 'bool')
                                        <x-checkbox-card wire:model="custom_attributes.{{ $field->key }}" :label="$field->label" />
                                    @else
                                        <x-input-label :for="'attr-'.$field->key" :value="$field->label.($field->required ? ' *' : '')" />
                                        @if ($field->type === 'select')
                                            <x-select :id="'attr-'.$field->key" wire:model="custom_attributes.{{ $field->key }}">
                                                <option value="">-</option>
                                                @foreach ($field->options as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </x-select>
                                        @else
                                            <x-text-input wire:model="custom_attributes.{{ $field->key }}" :id="'attr-'.$field->key" :type="$field->type === 'date' ? 'date' : 'text'" :inputmode="$field->type === 'number' ? 'decimal' : null" class="w-full" :placeholder="$field->placeholder" />
                                        @endif
                                    @endif
                                    <x-input-error :messages="$errors->get('custom_attributes.'.$field->key)" class="mt-1.5" />
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($showBatch)
                        <div class="space-y-3">
                            <x-checkbox-card wire:model.live="track_batch" label="Lacak batch & kedaluwarsa" description="Stok masuk dicatat per nomor batch dan tanggal kedaluwarsa; kasir menjual batch yang paling cepat kedaluwarsa lebih dulu." />
                            @if ($track_batch && ! $editingId)
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                                    <div>
                                        <x-input-label for="initial_batch_number" value="Nomor batch stok awal" />
                                        <x-text-input wire:model="initial_batch_number" id="initial_batch_number" class="w-full font-mono" placeholder="Opsional" />
                                        <x-input-error :messages="$errors->get('initial_batch_number')" class="mt-1.5" />
                                    </div>
                                    <div>
                                        <x-input-label for="initial_expires_at" value="Kedaluwarsa stok awal" />
                                        <x-text-input wire:model="initial_expires_at" id="initial_expires_at" type="date" class="w-full" />
                                        <x-input-error :messages="$errors->get('initial_expires_at')" class="mt-1.5" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($showUnits)
                        <div class="space-y-2.5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold text-slate-200">Satuan jual lain</p>
                                    <p class="text-[11px] text-slate-400">Stok tetap dihitung dalam satuan dasar ({{ $unit ?: 'pcs' }}). Harga kosong = isi × harga satuan dasar.</p>
                                </div>
                                <x-secondary-button size="xs" type="button" wire:click="addUnit" :disabled="count($units) >= 10">
                                    <i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah Satuan
                                </x-secondary-button>
                            </div>
                            @foreach ($units as $index => $row)
                                <div wire:key="unit-row-{{ $index }}-{{ $row['id'] ?? 'new' }}" class="grid grid-cols-2 sm:grid-cols-[1fr_1fr_1.2fr_1.3fr_auto_auto] gap-2 items-start rounded-lg border border-slate-800/80 p-2.5">
                                    <div>
                                        <x-input-label :for="'unit-name-'.$index" value="Nama" class="sm:sr-only" />
                                        <x-text-input wire:model="units.{{ $index }}.name" :id="'unit-name-'.$index" list="unit-options" class="w-full" placeholder="mis. box" />
                                        <x-input-error :messages="$errors->get('units.'.$index.'.name')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label :for="'unit-factor-'.$index" :value="'Isi ('.($unit ?: 'pcs').')'" class="sm:sr-only" />
                                        <x-text-input wire:model="units.{{ $index }}.factor" :id="'unit-factor-'.$index" inputmode="decimal" class="w-full font-mono" :placeholder="'isi '.($unit ?: 'pcs')" />
                                        <x-input-error :messages="$errors->get('units.'.$index.'.factor')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label :for="'unit-price-'.$index" value="Harga" class="sm:sr-only" />
                                        <x-text-input wire:model="units.{{ $index }}.price" :id="'unit-price-'.$index" inputmode="numeric" class="w-full font-mono" placeholder="Harga (opsional)" />
                                        <x-input-error :messages="$errors->get('units.'.$index.'.price')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label :for="'unit-barcode-'.$index" value="Barcode" class="sm:sr-only" />
                                        <x-text-input wire:model="units.{{ $index }}.barcode" :id="'unit-barcode-'.$index" class="w-full font-mono" placeholder="Barcode (opsional)" />
                                        <x-input-error :messages="$errors->get('units.'.$index.'.barcode')" class="mt-1" />
                                    </div>
                                    <label class="inline-flex items-center gap-2 min-h-[44px] text-[11px] text-slate-300 whitespace-nowrap" title="Satuan yang dipilih otomatis saat produk ditambahkan di kasir">
                                        <x-checkbox wire:model="units.{{ $index }}.is_default_sale" /> Default kasir
                                    </label>
                                    <x-icon-button icon="trash" :label="'Hapus satuan '.($row['name'] ?: ($index + 1))" tone="danger" wire:click="removeUnit({{ $index }})" />
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($showSerial)
                        <div class="grid grid-cols-1 sm:grid-cols-[1fr_12rem] gap-3.5 items-start">
                            <x-checkbox-card wire:model="track_serial" label="Catat nomor seri / IMEI" description="Setiap unit dicatat nomor serinya saat stok masuk dan dipilih kasir saat dijual. Butuh Lacak stok." />
                            <div>
                                <x-input-label for="warranty_days" value="Garansi (hari)" />
                                <x-text-input wire:model="warranty_days" id="warranty_days" inputmode="numeric" class="w-full font-mono" placeholder="Tanpa garansi" />
                                <x-input-error :messages="$errors->get('warranty_days')" class="mt-1.5" />
                            </div>
                        </div>
                    @endif

                    @if ($showVariants)
                        <div class="space-y-2.5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold text-slate-200">Varian (ukuran, warna, dll.)</p>
                                    <p class="text-[11px] text-slate-400">Setiap kombinasi jadi SKU sendiri dengan stok, harga, dan barcode masing-masing. Produk ini jadi induk dan tidak dijual langsung.</p>
                                </div>
                                <x-secondary-button size="xs" type="button" wire:click="addVariantOption" :disabled="count($variant_options) >= 3">
                                    <i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah Pilihan
                                </x-secondary-button>
                            </div>
                            @foreach ($variant_options as $index => $option)
                                <div wire:key="variant-option-{{ $index }}" class="grid grid-cols-[9rem_1fr_auto] gap-2 items-start rounded-lg border border-slate-800/80 p-2.5">
                                    <x-text-input wire:model="variant_options.{{ $index }}.name" aria-label="Nama pilihan" class="w-full" placeholder="Ukuran" />
                                    <div>
                                        <x-text-input wire:model="variant_options.{{ $index }}.values" aria-label="Nilai pilihan" class="w-full" placeholder="S, M, L, XL" />
                                        <x-input-error :messages="$errors->get('variant_options.'.$index.'.name')" class="mt-1" />
                                    </div>
                                    <x-icon-button icon="trash" label="Hapus pilihan varian" tone="danger" wire:click="removeVariantOption({{ $index }})" />
                                </div>
                            @endforeach
                            <x-input-error :messages="$errors->get('variant_options')" />
                            @php $children = $this->variantChildren(); @endphp
                            @if ($children->isNotEmpty())
                                <div class="rounded-lg border border-slate-800/80 divide-y divide-slate-800/60 text-xs">
                                    @foreach ($children as $child)
                                        <div wire:key="variant-child-{{ $child['id'] }}" class="flex items-center justify-between gap-3 px-3 py-2">
                                            <span class="text-slate-200">{{ $child['label'] }} <span class="font-mono text-slate-400">{{ $child['sku'] }}</span></span>
                                            <span class="tabular-nums text-slate-300">{{ Num::currency($child['price']) }} · stok {{ Num::quantity($child['stock']) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                <p class="text-[11px] text-slate-400">Ubah harga, barcode, atau stok tiap varian dari daftar produk. Kombinasi yang dihapus dari pilihan ikut dihapus.</p>
                            @endif
                        </div>
                    @endif

                    @if ($showTiers)
                        <div class="space-y-2.5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold text-slate-200">Harga grosir</p>
                                    <p class="text-[11px] text-slate-400">Harga per {{ $unit ?: 'pcs' }} turun otomatis di kasir saat jumlah beli mencapai batasnya.</p>
                                </div>
                                <x-secondary-button size="xs" type="button" wire:click="addTier" :disabled="count($price_tiers) >= 10">
                                    <i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah Tingkat
                                </x-secondary-button>
                            </div>
                            @foreach ($price_tiers as $index => $tier)
                                <div wire:key="tier-row-{{ $index }}" class="grid grid-cols-[1fr_1fr_auto] gap-2 items-start rounded-lg border border-slate-800/80 p-2.5">
                                    <div>
                                        <x-input-label :for="'tier-min-'.$index" :value="'Mulai beli ('.($unit ?: 'pcs').')'" />
                                        <x-text-input wire:model="price_tiers.{{ $index }}.min_quantity" :id="'tier-min-'.$index" inputmode="decimal" class="w-full font-mono" placeholder="mis. 12" />
                                        <x-input-error :messages="$errors->get('price_tiers.'.$index.'.min_quantity')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label :for="'tier-price-'.$index" value="Harga per satuan" />
                                        <x-text-input wire:model="price_tiers.{{ $index }}.price" :id="'tier-price-'.$index" inputmode="numeric" class="w-full font-mono" placeholder="Rp" />
                                        <x-input-error :messages="$errors->get('price_tiers.'.$index.'.price')" class="mt-1" />
                                    </div>
                                    <x-icon-button icon="trash" label="Hapus tingkat harga" tone="danger" class="mt-6" wire:click="removeTier({{ $index }})" />
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($showModifiers)
                        @php $modifierGroups = $this->modifierGroupChoices(); @endphp
                        <div class="space-y-2">
                            <p class="text-xs font-semibold text-slate-200">Pilihan tambahan di kasir</p>
                            @if ($modifierGroups->isEmpty())
                                <p class="text-[11px] text-slate-400">Belum ada grup pilihan. Buat dulu di <a href="{{ route('master-data.modifiers') }}" wire:navigate class="text-emerald-400 hover:underline">Pilihan Tambahan</a>.</p>
                            @else
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    @foreach ($modifierGroups as $group)
                                        <label wire:key="product-modgroup-{{ $group->id }}" class="flex items-center gap-2.5 min-h-[44px] rounded-lg border border-slate-800/80 px-3 text-sm text-slate-200 cursor-pointer hover:border-slate-700">
                                            <x-checkbox wire:model="modifier_group_ids" value="{{ $group->id }}" />
                                            <span class="min-w-0 flex-1 truncate">{{ $group->name }}</span>
                                            <span class="text-[11px] text-slate-400 shrink-0">{{ $group->ruleLabel() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($showComponents)
                        <div class="space-y-2.5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold text-slate-200">Komposisi / racikan</p>
                                    <p class="text-[11px] text-slate-400">Stok produk ini tidak dilacak; setiap 1 {{ $unit ?: 'pcs' }} terjual memotong stok bahan di bawah.</p>
                                </div>
                                <x-secondary-button size="xs" type="button" wire:click="addComponent" :disabled="count($components) >= 20">
                                    <i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah Bahan
                                </x-secondary-button>
                            </div>
                            @php $componentChoices = $this->componentChoices(); @endphp
                            @foreach ($components as $index => $component)
                                <div wire:key="component-row-{{ $index }}" class="grid grid-cols-[1fr_7rem_auto] gap-2 items-start rounded-lg border border-slate-800/80 p-2.5">
                                    <div>
                                        <x-select wire:model="components.{{ $index }}.component_id" aria-label="Bahan">
                                            <option value="">Pilih bahan</option>
                                            @foreach ($componentChoices as $choice)
                                                <option value="{{ $choice->id }}">{{ $choice->name }} ({{ $choice->unit }})</option>
                                            @endforeach
                                        </x-select>
                                        <x-input-error :messages="$errors->get('components.'.$index.'.component_id')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-text-input wire:model="components.{{ $index }}.quantity" aria-label="Jumlah bahan" inputmode="decimal" class="w-full font-mono text-right" placeholder="Jumlah" />
                                        <x-input-error :messages="$errors->get('components.'.$index.'.quantity')" class="mt-1" />
                                    </div>
                                    <x-icon-button icon="trash" label="Hapus bahan" tone="danger" wire:click="removeComponent({{ $index }})" />
                                </div>
                            @endforeach
                            <x-input-error :messages="$errors->get('components')" />
                        </div>
                    @endif
                </div>
            @endif

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
