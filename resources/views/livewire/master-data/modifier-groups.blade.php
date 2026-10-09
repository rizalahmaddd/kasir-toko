@php
    use App\Support\NumberFormatter as Num;
@endphp

<div class="space-y-4 sm:space-y-6">
    <x-list-toolbar
        description="Pilihan yang ditanyakan kasir saat menambahkan menu, mis. ukuran, level gula, atau topping. Harga tambahan dihitung per porsi."
        search-placeholder="Cari grup pilihan..."
        :can-manage="$this->canManage()"
        add-label="Tambah Grup"
    />

    @if ($groups->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state
                icon="list-plus"
                title="{{ $search ? 'Tidak ada grup yang cocok' : 'Belum ada grup pilihan' }}"
                description="{{ $search ? 'Coba kata kunci lain.' : 'Mis. Ukuran (Regular / Large +5.000) atau Gula (Normal / Less / No). Pasang ke produk supaya muncul di kasir.' }}"
            />
        </div>
    @else
        <x-table :pagination="$groups">
            <x-slot:header>
                <tr>
                    <x-table.th sortable field="name">Grup</x-table.th>
                    <x-table.th>Pilihan</x-table.th>
                    <x-table.th sortable field="products_count">Dipakai</x-table.th>
                    <x-table.th sortable field="is_active">Status</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($groups as $group)
                    <x-table.tr wire:key="modifier-group-{{ $group->id }}">
                        <x-table.td>
                            <div class="font-medium text-slate-100">{{ $group->name }}</div>
                            <div class="text-[11px] text-slate-400">{{ $group->ruleLabel() }}</div>
                        </x-table.td>
                        <x-table.td class="text-slate-300 text-xs">
                            {{ $group->modifiers->map(fn ($m) => $m->name.($m->price > 0 ? ' +'.Num::currency($m->price) : ''))->implode(', ') }}
                        </x-table.td>
                        <x-table.td class="text-slate-300 tabular-nums">{{ $group->products_count }} produk</x-table.td>
                        <x-table.td><x-status-badge :active="$group->is_active" /></x-table.td>
                        <x-table.td align="right">
                            @if ($this->canManage())
                                <div class="flex items-center justify-end gap-1">
                                    <x-icon-button icon="pencil" :label="'Edit '.$group->name" tone="edit" @click="$dispatch('open-modal', 'record-form')" wire:click="openEditModal({{ $group->id }})" />
                                    <x-icon-button icon="trash" :label="'Hapus '.$group->name" tone="danger" @click="$dispatch('open-modal', 'confirm-delete')" wire:click="confirmDelete({{ $group->id }})" />
                                </div>
                            @endif
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal
        :title="$editingId ? 'Edit Grup Pilihan' : 'Tambah Grup Pilihan'"
        subtitle="Aturan pilih, daftar pilihan, dan produk yang memakainya"
        icon="list-plus"
        max-width="3xl"
    >
        <form wire:submit="save" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] gap-3">
                <div>
                    <x-input-label for="name" value="Nama Grup *" />
                    <x-text-input wire:model="name" id="name" class="w-full" placeholder="Contoh: Ukuran" autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div class="sm:w-28">
                    <x-input-label for="min_select" value="Minimal pilih" />
                    <x-text-input wire:model="min_select" id="min_select" type="number" min="0" max="20" class="w-full font-mono" />
                    <x-input-error :messages="$errors->get('min_select')" class="mt-1.5" />
                </div>
                <div class="sm:w-28">
                    <x-input-label for="max_select" value="Maksimal pilih" />
                    <x-text-input wire:model="max_select" id="max_select" type="number" min="1" max="20" class="w-full font-mono" placeholder="Bebas" />
                    <x-input-error :messages="$errors->get('max_select')" class="mt-1.5" />
                </div>
            </div>
            <p class="-mt-3 text-[11px] text-slate-400">Minimal 1 = wajib dipilih (mis. Ukuran). Maksimal 1 = cukup satu pilihan. Kosongkan maksimal untuk topping yang boleh banyak.</p>

            <section class="space-y-2">
                <div class="flex items-center justify-between">
                    <x-input-label value="Pilihan *" class="!mb-0" />
                    <x-text-button size="sm" tone="emerald" wire:click="addOption">+ Tambah pilihan</x-text-button>
                </div>
                <x-input-error :messages="$errors->get('options')" />
                <div class="rounded-xl border border-slate-800 divide-y divide-slate-800/60">
                    @foreach ($options as $index => $option)
                        <div wire:key="option-{{ $index }}" class="p-3 grid grid-cols-2 sm:grid-cols-[1.4fr_1fr_1.4fr_0.8fr_auto] gap-2 items-start">
                            <div class="col-span-2 sm:col-span-1">
                                <x-text-input wire:model="options.{{ $index }}.name" aria-label="Nama pilihan" class="w-full" placeholder="Mis. Large" />
                                <x-input-error :messages="$errors->get('options.'.$index.'.name')" class="mt-1" />
                            </div>
                            <div>
                                <x-text-input wire:model="options.{{ $index }}.price" aria-label="Harga tambahan" inputmode="numeric" class="w-full font-mono text-right" placeholder="+Rp 0" />
                                <x-input-error :messages="$errors->get('options.'.$index.'.price')" class="mt-1" />
                            </div>
                            <div>
                                <x-select wire:model="options.{{ $index }}.product_id" aria-label="Bahan yang dipotong stoknya">
                                    <option value="">Tanpa potong stok</option>
                                    @foreach ($this->ingredientChoices as $ingredient)
                                        <option value="{{ $ingredient->id }}">{{ $ingredient->name }} ({{ $ingredient->unit }})</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <div>
                                <x-text-input wire:model="options.{{ $index }}.ingredient_quantity" aria-label="Jumlah bahan per porsi" inputmode="decimal" class="w-full font-mono text-right" placeholder="Jml" />
                                <x-input-error :messages="$errors->get('options.'.$index.'.ingredient_quantity')" class="mt-1" />
                            </div>
                            <div class="flex items-center gap-1 justify-end">
                                <label class="inline-flex items-center gap-1.5 text-[11px] text-slate-400 min-h-[44px] px-1">
                                    <x-checkbox wire:model="options.{{ $index }}.is_active" />
                                    <span>Aktif</span>
                                </label>
                                <x-icon-button icon="trash-2" label="Hapus pilihan" tone="danger" wire:click="removeOption({{ $index }})" />
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="text-[11px] text-slate-400">Isi bahan bila pilihan memakai stok sendiri, mis. Extra Shot memotong 18 gram biji kopi per porsi.</p>
            </section>

            <section class="space-y-2">
                <x-input-label value="Dipakai di produk" class="!mb-0" />
                @if ($this->selectedProducts->isNotEmpty())
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($this->selectedProducts as $product)
                            <button type="button" wire:click="toggleProduct({{ $product->id }})" class="inline-flex items-center gap-1 min-h-[32px] rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2.5 text-xs font-semibold text-emerald-300 hover:border-emerald-500/60">
                                {{ $product->name }} <i data-lucide="x" class="w-3 h-3"></i>
                            </button>
                        @endforeach
                    </div>
                @endif
                <x-search-input wire:model.live.debounce.300ms="productSearch" placeholder="Cari produk untuk dipasangi grup ini..." />
                <div class="max-h-48 overflow-y-auto custom-scrollbar rounded-xl border border-slate-800 divide-y divide-slate-800/60">
                    @forelse ($this->productChoices as $product)
                        <label wire:key="choice-{{ $product->id }}" class="flex items-center gap-2.5 px-3 min-h-[44px] text-sm text-slate-200 hover:bg-slate-800/40 cursor-pointer">
                            <x-checkbox :checked="in_array($product->id, array_map('intval', $productIds), true)" wire:click="toggleProduct({{ $product->id }})" />
                            <span class="truncate">{{ $product->name }}</span>
                        </label>
                    @empty
                        <p class="px-3 py-3 text-xs text-slate-400">Produk tidak ditemukan.</p>
                    @endforelse
                </div>
            </section>

            <x-checkbox-card wire:model="is_active" label="Grup aktif" description="Grup nonaktif tidak ditanyakan di kasir, tapi tetap terpasang di produknya." />

            <x-modal-actions>
                <x-secondary-button wire:click="closeModal">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="save" loading="Menyimpan...">{{ $editingId ? 'Perbarui Grup' : 'Simpan Grup' }}</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>

    <x-confirm-delete-modal title="Hapus grup pilihan ini?" description="Grup dilepas dari semua produk. Transaksi lama tetap menyimpan nama & harga pilihannya." />
</div>
