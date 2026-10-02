<div class="space-y-4 sm:space-y-6">
    <x-list-toolbar
        description="Toko yang terdaftar di layanan. Atur paket, masa aktif, dan status toko dari sini."
        search-placeholder="Cari nama atau slug toko..."
        :can-manage="false"
    >
        <x-slot:filters>
            <x-select id="statusFilter" wire:model.live="statusFilter" variant="filter">
                <option value="">Semua status</option>
                <option value="{{ \App\Models\Tenant::STATUS_ACTIVE }}">Aktif</option>
                <option value="{{ \App\Models\Tenant::STATUS_SUSPENDED }}">Nonaktif</option>
            </x-select>
        </x-slot:filters>
    </x-list-toolbar>

    @if ($tenants->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state
                icon="store"
                title="{{ $search || $statusFilter ? 'Tidak ada toko yang cocok' : 'Belum ada toko' }}"
                description="{{ $search || $statusFilter ? 'Coba kata kunci atau filter lain.' : 'Toko muncul di sini setelah pemiliknya mendaftar.' }}"
            />
        </div>
    @else
        <x-table :pagination="$tenants">
            <x-slot:header>
                <tr>
                    <x-table.th sortable field="name">Toko</x-table.th>
                    <x-table.th sortable field="plan">Paket</x-table.th>
                    <x-table.th>Aktif Sampai</x-table.th>
                    <x-table.th sortable field="users_count">Pengguna</x-table.th>
                    <x-table.th>Status</x-table.th>
                    <x-table.th sortable field="created_at">Terdaftar</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($tenants as $tenant)
                    @php($reason = $tenant->blockedReason())
                    <x-table.tr wire:key="tenant-{{ $tenant->id }}">
                        <x-table.td>
                            <a href="{{ route('platform.tenants.show', $tenant) }}" wire:navigate class="font-medium text-slate-100 hover:text-emerald-400 hover:underline">{{ $tenant->name }}</a>
                            <div class="font-mono text-[11px] text-slate-500">{{ $tenant->slug }}</div>
                        </x-table.td>
                        <x-table.td><x-badge :color="$tenant->isOnTrial() ? 'amber' : 'sky'">{{ $tenant->planLabel() }}</x-badge></x-table.td>
                        <x-table.td class="text-slate-300 tabular-nums">{{ $tenant->accessEndsAt()?->translatedFormat('d M Y') ?? 'Tanpa batas' }}</x-table.td>
                        <x-table.td class="text-slate-300 tabular-nums">{{ $tenant->users_count }}</x-table.td>
                        <x-table.td>
                            @if ($reason === null)
                                <x-badge color="emerald">Aktif</x-badge>
                            @elseif ($reason === 'tenant_suspended')
                                <x-badge color="rose">Nonaktif</x-badge>
                            @else
                                <x-badge color="amber">Masa aktif habis</x-badge>
                            @endif
                        </x-table.td>
                        <x-table.td class="text-slate-400 tabular-nums">{{ $tenant->created_at?->translatedFormat('d M Y') }}</x-table.td>
                        <x-table.td align="right">
                            @if ($this->canManage())
                                <div class="flex items-center justify-end gap-1">
                                    <x-text-button wire:click="extend({{ $tenant->id }})">+30 hari</x-text-button>
                                    <x-icon-button icon="pencil" :label="'Edit '.$tenant->name" tone="edit" @click="$dispatch('open-modal', 'record-form')" wire:click="openEditModal({{ $tenant->id }})" />
                                </div>
                            @endif
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal title="Edit Toko" subtitle="Paket, masa aktif, dan status toko" icon="store" max-width="md">
        <form wire:submit="save" class="space-y-4">
            <div>
                <x-input-label for="name" value="Nama Toko *" />
                <x-text-input wire:model="name" id="name" class="w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="plan" value="Paket *" />
                <x-select id="plan" wire:model="plan">
                    @foreach ($plans as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
                <x-input-error :messages="$errors->get('plan')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="access_ends_at" value="Aktif Sampai" />
                <x-text-input wire:model="access_ends_at" id="access_ends_at" type="date" class="w-full" />
                <p class="mt-1 text-[11px] text-slate-500">Kosongkan untuk tanpa batas waktu.</p>
                <x-input-error :messages="$errors->get('access_ends_at')" class="mt-1.5" />
            </div>
            @include('livewire.platform.partials.payment-fields')
            <p class="-mt-2 text-[11px] text-slate-500">Isi kalau perubahan ini disertai pembayaran. Tercatat di riwayat langganan toko.</p>
            <div>
                <x-input-label for="status" value="Status *" />
                <x-select id="status" wire:model="status">
                    <option value="{{ \App\Models\Tenant::STATUS_ACTIVE }}">Aktif</option>
                    <option value="{{ \App\Models\Tenant::STATUS_SUSPENDED }}">Nonaktif (pengguna toko tidak bisa masuk)</option>
                </x-select>
                <x-input-error :messages="$errors->get('status')" class="mt-1.5" />
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
