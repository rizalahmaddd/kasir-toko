<div class="space-y-4 sm:space-y-6">
    <x-list-toolbar
        description="Pelanggan tetap, reseller, dan pelanggan yang punya kasbon."
        search-placeholder="Cari nama atau kode pelanggan..."
        :can-manage="$this->canManage()"
        :can-export="true"
        add-label="Tambah Pelanggan"
    />

    @if ($customers->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state
                icon="users"
                title="{{ $search ? 'Tidak ada pelanggan yang cocok' : 'Belum ada pelanggan' }}"
                description="{{ $search ? 'Coba kata kunci lain, atau hapus pencarian.' : 'Tambahkan pelanggan pertama lewat tombol Tambah Pelanggan.' }}"
            />
        </div>
    @else
        <x-table :pagination="$customers">
            <thead class="bg-slate-950 text-slate-400 font-semibold uppercase tracking-wider border-b border-slate-800">
                <tr>
                    <x-table.th sortable field="code">Kode</x-table.th>
                    <x-table.th sortable field="name">Nama Pelanggan</x-table.th>
                    <x-table.th sortable field="contact_person">Kontak</x-table.th>
                    <x-table.th sortable field="due" align="right">Kasbon</x-table.th>
                    <x-table.th sortable field="is_active">Status</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($customers as $customer)
                    <x-table.tr wire:key="customer-{{ $customer->id }}">
                        <x-table.td class="font-mono text-slate-400">{{ $customer->code }}</x-table.td>
                        <x-table.td class="font-medium text-emerald-400">
                            <a href="{{ route('master-data.customers.show', $customer) }}" wire:navigate class="hover:text-emerald-300 transition">{{ $customer->name }}</a>
                            @if ($customer->type)
                                <div class="text-[11px] text-slate-400 font-normal">{{ $customer->type }}</div>
                            @endif
                        </x-table.td>
                        <x-table.td class="text-slate-400">
                            <div>{{ $customer->contact_person ?: '-' }}</div>
                            <div class="text-[11px]">{{ $customer->phone ?: '' }}</div>
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums">
                            @if ($customer->due > 0)
                                <span class="font-semibold text-amber-400">{{ \App\Support\NumberFormatter::currency($customer->due) }}</span>
                            @else
                                <span class="text-slate-400">-</span>
                            @endif
                        </x-table.td>
                        <x-table.td><x-status-badge :active="$customer->is_active" /></x-table.td>
                        <x-table.td align="right">
                            <div class="flex items-center justify-end gap-1">
                                @if ($this->canManage())
                                    <x-icon-button icon="pencil" :label="'Edit '.$customer->name" tone="edit" @click="$dispatch('open-modal', 'record-form')" wire:click="openEditModal({{ $customer->id }})" />
                                    <x-icon-button icon="trash" :label="'Hapus '.$customer->name" tone="danger" @click="$dispatch('open-modal', 'confirm-delete')" wire:click="confirmDelete({{ $customer->id }})" />
                                @endif
                                <a href="{{ route('master-data.customers.show', $customer) }}" wire:navigate aria-label="Lihat {{ $customer->name }}" class="inline-flex items-center justify-center w-7 h-7 rounded-md text-slate-400 hover:text-slate-200">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </a>
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal
        :title="$editingId ? 'Edit Data Pelanggan' : 'Tambah Pelanggan Baru'"
        subtitle="Profil, kontak, alamat, dan termin pembayaran pelanggan"
        icon="users"
        max-width="5xl"
    >
        <form wire:submit="save" class="space-y-4 sm:space-y-6">
            {{-- Responsive Landscape Grid: 1 Col on Mobile, 2 Col on Tablet & Desktop --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 items-start">
                {{-- Kolom Kiri: Profil & Ketentuan Bisnis --}}
                <div class="space-y-5">
                    {{-- Seksi 1: Profil Pelanggan --}}
                    <div class="space-y-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                        <div class="flex items-center gap-2 pb-1.5 border-b border-slate-800/80">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-400">1. Profil Pelanggan</span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <x-input-label for="code" value="Kode Pelanggan" />
                                <x-text-input wire:model="code" id="code" class="w-full font-mono uppercase" placeholder="Kosongkan = otomatis" />
                                <x-input-error :messages="$errors->get('code')" class="mt-1.5" />
                            </div>
                            <div>
                                <x-input-label for="type" value="Jenis Pelanggan" />
                                <x-text-input wire:model="type" id="type" class="w-full" placeholder="Contoh: Member, Reseller, Warung" />
                                <x-input-error :messages="$errors->get('type')" class="mt-1.5" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="name" value="Nama Pelanggan *" />
                            <x-text-input wire:model="name" id="name" class="w-full" placeholder="Contoh: Bu Rina / Warung Sumber Rejeki" autofocus />
                            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                        </div>

                        <div>
                            <x-input-label for="npwp" value="NPWP" />
                            <x-text-input wire:model="npwp" id="npwp" class="w-full font-mono" placeholder="00.000.000.0-000.000 (Opsional)" />
                            <x-input-error :messages="$errors->get('npwp')" class="mt-1.5" />
                        </div>
                    </div>

                    {{-- Seksi 3: Ketentuan Bisnis --}}
                    <div class="space-y-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                        <div class="flex items-center gap-2 pb-1.5 border-b border-slate-800/80">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-400">2. Termin & Status</span>
                        </div>

                        <div>
                            <x-input-label for="payment_term_days" value="Termin Pembayaran *" />
                            <div class="relative">
                                <x-text-input wire:model="payment_term_days" id="payment_term_days" type="number" min="0" class="w-full pr-14 font-mono" placeholder="0" />
                                <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-xs text-slate-400 font-semibold">Hari</span>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1">Jumlah hari sampai tagihan jatuh tempo (0 = tunai).</p>
                            <x-input-error :messages="$errors->get('payment_term_days')" class="mt-1.5" />
                        </div>

                        <x-checkbox-card wire:model="is_active" label="Pelanggan Aktif" description="Pelanggan nonaktif tetap tersimpan tapi ditandai di daftar dan pencarian." />
                    </div>
                </div>

                {{-- Kolom Kanan: Kontak & Alamat --}}
                <div class="space-y-5">
                    <div class="space-y-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                        <div class="flex items-center gap-2 pb-1.5 border-b border-slate-800/80">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-400">3. Kontak & Alamat</span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <x-input-label for="contact_person" value="Nama PIC" />
                                <x-text-input wire:model="contact_person" id="contact_person" class="w-full" placeholder="Contoh: Ibu Sari Wahyuni" />
                                <x-input-error :messages="$errors->get('contact_person')" class="mt-1.5" />
                            </div>
                            <div>
                                <x-input-label for="phone" value="Nomor Telepon / WA" />
                                <x-text-input wire:model="phone" id="phone" type="tel" class="w-full font-mono" placeholder="08xxxxxxxxxx" />
                                <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="email" value="Email" />
                            <x-text-input wire:model="email" id="email" type="email" class="w-full" placeholder="finance@customer.com" />
                            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                        </div>

                        <div>
                            <x-input-label for="address" value="Alamat Lengkap" />
                            <x-textarea wire:model="address" id="address" rows="4" placeholder="Alamat kantor atau lokasi pelanggan" />
                            <x-input-error :messages="$errors->get('address')" class="mt-1.5" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-800/80 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2.5 [&>*]:w-full sm:[&>*]:w-auto">
                <x-secondary-button type="button" wire:click="closeModal">Batal</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <x-loading-label target="save" loading="Menyimpan...">{{ $editingId ? 'Perbarui Pelanggan' : 'Simpan Pelanggan' }}</x-loading-label>
                </x-primary-button>
            </div>
        </form>
    </x-record-form-modal>

    <x-confirm-delete-modal title="Hapus pelanggan ini?" description="Tindakan ini tidak bisa dibatalkan." />
</div>
