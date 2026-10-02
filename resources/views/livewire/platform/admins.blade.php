<div class="space-y-4 sm:space-y-6">
    <x-list-toolbar
        description="Akun pengelola layanan. Mereka tidak terikat toko dan hanya membuka panel Platform."
        search-placeholder="Cari nama, username, atau email..."
        :can-manage="$this->canManage()"
        add-label="Tambah Admin"
    />

    @if ($admins->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state icon="shield-user" title="Tidak ada akun yang cocok" description="Coba kata kunci lain." />
        </div>
    @else
        <x-table :pagination="$admins">
            <x-slot:header>
                <tr>
                    <x-table.th sortable field="name">Nama</x-table.th>
                    <x-table.th>Email</x-table.th>
                    <x-table.th>Akses</x-table.th>
                    <x-table.th sortable field="created_at">Dibuat</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($admins as $admin)
                    <x-table.tr wire:key="admin-{{ $admin->id }}">
                        <x-table.td>
                            <div class="font-medium text-slate-100">{{ $admin->name }}</div>
                            <div class="font-mono text-[11px] text-slate-500">{{ $admin->username }}</div>
                        </x-table.td>
                        <x-table.td class="text-slate-300">{{ $admin->email }}</x-table.td>
                        <x-table.td>
                            @if ($admin->is_platform_admin)
                                <x-badge color="emerald">Aktif</x-badge>
                            @else
                                <x-badge color="slate">Dicabut</x-badge>
                            @endif
                        </x-table.td>
                        <x-table.td class="text-slate-400 tabular-nums">{{ $admin->created_at?->translatedFormat('d M Y') }}</x-table.td>
                        <x-table.td align="right">
                            @if ($this->canManage())
                                <div class="flex items-center justify-end gap-1">
                                    @unless ($admin->is(auth()->user()))
                                        <x-text-button wire:click="toggleAccess({{ $admin->id }})" wire:confirm="{{ $admin->is_platform_admin ? 'Cabut akses '.$admin->name.' ke panel Platform?' : 'Pulihkan akses '.$admin->name.'?' }}">
                                            {{ $admin->is_platform_admin ? 'Cabut akses' : 'Pulihkan' }}
                                        </x-text-button>
                                    @endunless
                                    <x-icon-button icon="pencil" :label="'Edit '.$admin->name" tone="edit" @click="$dispatch('open-modal', 'record-form')" wire:click="openEditModal({{ $admin->id }})" />
                                </div>
                            @endif
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal :title="$editingId ? 'Edit Admin Platform' : 'Tambah Admin Platform'" subtitle="Akun pengelola layanan" icon="shield-user" max-width="md">
        <form wire:submit="save" class="space-y-4">
            <div>
                <x-input-label for="name" value="Nama *" />
                <x-text-input wire:model="name" id="name" class="w-full" autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="username" value="Username *" />
                    <x-text-input wire:model="username" id="username" class="w-full" autocapitalize="none" autocomplete="off" />
                    <x-input-error :messages="$errors->get('username')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="email" value="Email *" />
                    <x-text-input wire:model="email" id="email" type="email" class="w-full" autocomplete="off" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>
            </div>
            <div>
                <x-input-label for="password" :value="$editingId ? 'Password Baru' : 'Password *'" />
                <x-text-input wire:model="password" id="password" type="password" class="w-full" autocomplete="new-password" />
                @if ($editingId)
                    <p class="mt-1 text-[11px] text-slate-500">Kosongkan kalau tidak diganti.</p>
                @endif
                <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
            </div>

            <x-modal-actions>
                <x-secondary-button wire:click="closeModal">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="save" loading="Menyimpan...">Simpan</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>
</div>
