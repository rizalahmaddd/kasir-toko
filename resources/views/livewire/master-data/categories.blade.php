<div class="space-y-4 sm:space-y-6">
    <x-list-toolbar
        description="Kategori jadi tombol filter di layar kasir. Urutan kecil tampil lebih dulu."
        search-placeholder="Cari kategori..."
        :can-manage="$this->canManage()"
        add-label="Tambah Kategori"
    />

    @if ($categories->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state
                icon="tags"
                title="{{ $search ? 'Tidak ada kategori yang cocok' : 'Belum ada kategori' }}"
                description="{{ $search ? 'Coba kata kunci lain.' : 'Mis. Makanan, Minuman, Sembako. Produk tanpa kategori tetap bisa dijual.' }}"
            />
        </div>
    @else
        <x-table :pagination="$categories">
            <x-slot:header>
                <tr>
                    <x-table.th sortable field="name">Nama Kategori</x-table.th>
                    <x-table.th sortable field="sort_order">Urutan</x-table.th>
                    <x-table.th sortable field="products_count">Jumlah Produk</x-table.th>
                    <x-table.th sortable field="is_active">Status</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($categories as $category)
                    <x-table.tr wire:key="category-{{ $category->id }}">
                        <x-table.td>
                            <span class="font-medium text-slate-100">{{ $category->name }}</span>
                            @if ($outletOptions->isNotEmpty() && $category->outlets->isNotEmpty())
                                <p class="text-[11px] text-slate-400 mt-0.5">Hanya di {{ $category->outlets->pluck('name')->implode(', ') }}</p>
                            @endif
                        </x-table.td>
                        <x-table.td class="font-mono text-slate-400">{{ $category->sort_order }}</x-table.td>
                        <x-table.td class="text-slate-300 tabular-nums">
                            <x-feature-link :href="route('master-data.products', ['category' => $category->id])" wire:navigate class="hover:text-emerald-400">{{ $category->products_count }} produk</x-feature-link>
                        </x-table.td>
                        <x-table.td><x-status-badge :active="$category->is_active" /></x-table.td>
                        <x-table.td align="right">
                            @if ($this->canManage())
                                <div class="flex items-center justify-end gap-1">
                                    <x-icon-button icon="pencil" :label="'Edit '.$category->name" tone="edit" @click="$dispatch('open-modal', 'record-form')" wire:click="openEditModal({{ $category->id }})" />
                                    <x-icon-button icon="trash" :label="'Hapus '.$category->name" tone="danger" @click="$dispatch('open-modal', 'confirm-delete')" wire:click="confirmDelete({{ $category->id }})" />
                                </div>
                            @endif
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal
        :title="$editingId ? 'Edit Kategori' : 'Tambah Kategori'"
        subtitle="Nama dan urutan tampil di layar kasir"
        icon="tags"
        max-width="md"
    >
        <form wire:submit="save" class="space-y-4">
            <div>
                <x-input-label for="name" value="Nama Kategori *" />
                <x-text-input wire:model="name" id="name" class="w-full" placeholder="Contoh: Minuman" autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="sort_order" value="Urutan Tampil" />
                <x-text-input wire:model="sort_order" id="sort_order" type="number" min="0" class="w-full font-mono" />
                <x-input-error :messages="$errors->get('sort_order')" class="mt-1.5" />
            </div>
            <x-checkbox-card wire:model="is_active" label="Kategori aktif" description="Kategori nonaktif tidak tampil sebagai filter di kasir." />
            @if ($outletOptions->isNotEmpty())
                <fieldset class="space-y-2">
                    <legend class="text-xs font-semibold text-slate-300">Dijual di outlet</legend>
                    <p class="text-[11px] text-slate-400">Biarkan semua tidak dicentang agar kategori ini tampil di kasir semua outlet.</p>
                    <div class="grid sm:grid-cols-2 gap-2">
                        @foreach ($outletOptions as $option)
                            <label class="flex items-center gap-2.5 min-h-[44px] px-3 rounded-lg border border-slate-800 bg-slate-950 text-xs text-slate-200 cursor-pointer">
                                <x-checkbox wire:model="outlet_ids" value="{{ $option->id }}" />
                                {{ $option->name }}
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('outlet_ids.*')" class="mt-1.5" />
                </fieldset>
            @endif

            <x-modal-actions>
                <x-secondary-button wire:click="closeModal">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="save" loading="Menyimpan...">{{ $editingId ? 'Perbarui Kategori' : 'Simpan Kategori' }}</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>

    <x-confirm-delete-modal title="Hapus kategori ini?" description="Produk di kategori ini tidak terhapus, hanya jadi tanpa kategori." />
</div>
