@php
    $quota = $this->quota;
    $outlets = $this->outlets;
    $operational = $this->operationalIds;
    $canManage = $this->canManage();
@endphp

<div class="space-y-4 sm:space-y-6">
    {{-- Titik fokus: kuota outlet paket ini --}}
    <section class="rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 border border-slate-800 p-4 sm:p-6 shadow-lg shadow-slate-950/30">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="min-w-0">
                <p class="text-xs font-semibold text-slate-400">Outlet aktif · Paket {{ $quota['plan'] }}</p>
                <p class="mt-1 text-3xl font-extrabold text-slate-100 tabular-nums">{{ $quota['used'] }}<span class="text-lg font-bold text-slate-400"> / {{ $quota['max'] }}</span></p>
                <p class="mt-1 text-xs text-slate-400">
                    @if ($quota['canAdd'])
                        Masih bisa menambah {{ $quota['max'] - $quota['used'] }} outlet. Produk, kategori, dan pelanggan dipakai bersama; stok, shift, dan transaksi dicatat per outlet.
                    @elseif ($quota['max'] <= 1)
                        Paket ini hanya mendukung satu outlet. Tambah outlet tersedia di paket Pro, kelola langganan di menu Paket & Langganan.
                    @else
                        Batas outlet paket ini sudah terpakai. Nonaktifkan outlet yang tidak dipakai atau naikkan paket.
                    @endif
                </p>
            </div>
            @if ($canManage)
                <x-primary-button size="sm" type="button" wire:click="openCreate" :disabled="! $quota['canAdd']" class="w-full sm:w-auto shrink-0">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>Tambah Outlet</span>
                </x-primary-button>
            @endif
        </div>
    </section>

    @if ($outlets->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state icon="store" title="Belum ada outlet" description="Outlet utama dibuat otomatis saat toko terdaftar. Hubungi admin layanan jika daftar ini kosong." />
        </div>
    @else
        <x-table>
            <x-slot:header>
                <tr>
                    <x-table.th>Outlet</x-table.th>
                    <x-table.th>Alamat</x-table.th>
                    <x-table.th>Status</x-table.th>
                    <x-table.th>Pengguna</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @php $tenantStoreType = app(\App\Support\CurrentTenant::class)->get()?->store_type; @endphp
                @foreach ($outlets as $outlet)
                    @php
                        $locked = $outlet->is_active && ! in_array($outlet->id, $operational, true);
                        $movable = $canManage && $outlet->is_active && ! $outlet->is_primary && $outlets->where('is_primary', false)->where('is_active', true)->count() > 1;
                    @endphp
                    <x-table.tr wire:key="outlet-{{ $outlet->id }}">
                        <x-table.td>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-slate-100">{{ $outlet->name }}</span>
                                <span class="font-mono text-[11px] text-slate-400">{{ $outlet->code }}</span>
                                @if ($outlet->is_primary)
                                    <x-badge color="sky">UTAMA</x-badge>
                                @endif
                            </div>
                            @php $outletType = ($outlet->store_type ?? $tenantStoreType)?->label(); @endphp
                            @if ($outletType || $outlet->phone)
                                <p class="text-[11px] text-slate-400 mt-0.5">{{ collect([$outletType, $outlet->phone])->filter()->implode(' · ') }}</p>
                            @endif
                        </x-table.td>
                        <x-table.td data-label="Alamat" class="text-slate-300 max-w-xs truncate">{{ $outlet->address ?: '-' }}</x-table.td>
                        <x-table.td data-label="Status">
                            @if (! $outlet->is_active)
                                <x-badge color="slate">NONAKTIF</x-badge>
                            @elseif ($locked)
                                <x-badge color="amber">TERKUNCI PAKET</x-badge>
                            @else
                                <x-badge color="emerald">AKTIF</x-badge>
                            @endif
                        </x-table.td>
                        <x-table.td data-label="Pengguna" class="text-slate-300 tabular-nums">{{ $outlet->users_count }}</x-table.td>
                        <x-table.td align="right" data-label="Aksi">
                            @if ($canManage)
                                <div class="flex items-center justify-end gap-1">
                                    @if ($movable)
                                        <x-icon-button icon="arrow-up" :label="'Naikkan prioritas '.$outlet->name" wire:click="move({{ $outlet->id }}, 'up')" />
                                        <x-icon-button icon="arrow-down" :label="'Turunkan prioritas '.$outlet->name" wire:click="move({{ $outlet->id }}, 'down')" />
                                    @endif
                                    <x-icon-button icon="pencil" :label="'Edit '.$outlet->name" tone="edit" wire:click="openEdit({{ $outlet->id }})" />
                                    <x-icon-button icon="sliders-horizontal" :label="'Pengaturan kasir '.$outlet->name" wire:click="openConfig({{ $outlet->id }})" />
                                    <x-dropdown width="64">
                                        <x-slot:trigger>
                                            <x-icon-button icon="ellipsis" :label="'Aksi lain '.$outlet->name" />
                                        </x-slot:trigger>
                                        <x-slot:content>
                                            <x-dropdown-link href="#" wire:click.prevent="openAccess({{ $outlet->id }})">Atur akses pengguna</x-dropdown-link>
                                            <x-dropdown-link href="#" wire:click.prevent="openCapabilities({{ $outlet->id }})">Jenis usaha &amp; fitur khusus</x-dropdown-link>
                                            @if ($outlets->count() > 1)
                                                <x-dropdown-link href="#" wire:click.prevent="openCopy({{ $outlet->id }})">Salin pengaturan &amp; harga dari outlet lain</x-dropdown-link>
                                            @endif
                                            @if (! $outlet->is_primary && $outlet->is_active)
                                                <x-dropdown-link href="#" wire:click.prevent="setPrimary({{ $outlet->id }})">Jadikan outlet utama</x-dropdown-link>
                                            @endif
                                            @if (! $outlet->is_primary)
                                                <x-dropdown-link href="#" wire:click.prevent="toggleActive({{ $outlet->id }})">{{ $outlet->is_active ? 'Nonaktifkan outlet' : 'Aktifkan kembali' }}</x-dropdown-link>
                                                <x-dropdown-link href="#" wire:click.prevent="confirmDelete({{ $outlet->id }})" class="text-rose-400 hover:text-rose-300">Hapus outlet</x-dropdown-link>
                                            @endif
                                        </x-slot:content>
                                    </x-dropdown>
                                </div>
                            @endif
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>

        <p class="text-[11px] text-slate-400">
            Urutan prioritas menentukan outlet mana yang tetap bisa bertransaksi bila batas paket lebih kecil dari jumlah outlet. Outlet terkunci tetap bisa dilihat datanya. Saat jumlah outlet melebihi batas, outlet yang beroperasi hanya bisa diganti sekali per 30 hari.
        </p>
    @endif

    {{-- Tambah / ubah outlet (dengan Wizard 4-langkah saat tambah) --}}
    <x-record-form-modal
        :title="$editingId ? 'Edit Outlet' : 'Tambah Outlet Baru'"
        :subtitle="$editingId ? 'Identitas outlet tampil di struk dan nomor transaksi' : 'Panduan 4 langkah menyiapkan cabang operasional dan akses kasir'"
        icon="store"
        :max-width="$editingId ? 'lg' : '2xl'"
    >
        @if ($editingId)
            {{-- Form Edit Sederhana --}}
            <form wire:submit="save" class="space-y-4">
                <div class="grid sm:grid-cols-[minmax(0,1fr)_9rem] gap-3.5">
                    <div>
                        <x-input-label for="outlet-name" value="Nama Outlet *" />
                        <x-text-input wire:model="name" id="outlet-name" class="w-full" placeholder="Contoh: Cabang Dago" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="outlet-code" value="Kode *" />
                        <x-text-input wire:model="code" id="outlet-code" class="w-full font-mono uppercase" maxlength="10" placeholder="DGO" />
                        <x-input-error :messages="$errors->get('code')" class="mt-1.5" />
                    </div>
                </div>
                <p class="text-[11px] text-slate-400 -mt-2">Kode dipakai di nomor transaksi, mis. TRX-DGO-2026-000001. Tidak bisa diubah setelah outlet dipakai bertransaksi.</p>
                <div>
                    <x-input-label for="outlet-address" value="Alamat" />
                    <x-textarea wire:model="address" id="outlet-address" rows="2" placeholder="Alamat yang dicetak di struk outlet ini" />
                    <x-input-error :messages="$errors->get('address')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="outlet-phone" value="Telepon" />
                    <x-text-input wire:model="phone" id="outlet-phone" class="w-full" inputmode="tel" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                </div>

                <x-modal-actions>
                    <x-secondary-button size="sm" type="button" wire:click="closeModal">Batal</x-secondary-button>
                    <x-primary-button size="sm" wire:loading.attr="disabled">
                        <x-loading-label target="save" loading="Menyimpan...">Perbarui Outlet</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        @else
            {{-- Mode Wizard Tambah Outlet --}}
            <div class="space-y-5">
                {{-- Stepper Progress Bar --}}
                <div class="grid grid-cols-4 gap-2 border-b border-slate-800/80 pb-4">
                    @php
                        $steps = [
                            1 => ['label' => 'Identitas', 'icon' => 'store'],
                            2 => ['label' => 'Pengaturan', 'icon' => 'sliders-horizontal'],
                            3 => ['label' => 'Akses Kasir', 'icon' => 'users'],
                            4 => ['label' => 'Ringkasan', 'icon' => 'check-circle-2'],
                        ];
                    @endphp
                    @foreach ($steps as $sNum => $step)
                        @php
                            $isActive = $wizardStep === $sNum;
                            $isCompleted = $wizardStep > $sNum;
                        @endphp
                        <button
                            type="button"
                            wire:click="goToStep({{ $sNum }})"
                            @class([
                                'flex items-center gap-2 p-2 sm:px-3 sm:py-2 rounded-xl text-xs font-medium transition-all text-left min-h-[44px]',
                                'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' => $isActive,
                                'bg-slate-800/40 text-slate-300 hover:bg-slate-800 border border-slate-700/40' => $isCompleted,
                                'bg-slate-900/60 text-slate-500 border border-slate-800 cursor-not-allowed' => ! $isActive && ! $isCompleted,
                            ])
                            @disabled(! $isCompleted && ! $isActive)
                        >
                            <span @class([
                                'w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0',
                                'bg-emerald-500 text-slate-950' => $isActive,
                                'bg-emerald-500/20 text-emerald-400' => $isCompleted,
                                'bg-slate-800 text-slate-500' => ! $isActive && ! $isCompleted,
                            ])>
                                @if ($isCompleted)
                                    <i data-lucide="check" class="w-3 h-3"></i>
                                @else
                                    {{ $sNum }}
                                @endif
                            </span>
                            <span class="truncate hidden sm:inline">{{ $step['label'] }}</span>
                        </button>
                    @endforeach
                </div>

                {{-- Langkah 1: Identitas --}}
                @if ($wizardStep === 1)
                    <div class="space-y-4">
                        <div class="grid sm:grid-cols-[minmax(0,1fr)_9rem] gap-3.5">
                            <div>
                                <x-input-label for="wizard-outlet-name" value="Nama Outlet *" />
                                <x-text-input wire:model="name" id="wizard-outlet-name" class="w-full" placeholder="Contoh: Cabang Dago" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                            </div>
                            <div>
                                <x-input-label for="wizard-outlet-code" value="Kode *" />
                                <x-text-input wire:model="code" id="wizard-outlet-code" class="w-full font-mono uppercase" maxlength="10" placeholder="DGO" />
                                <x-input-error :messages="$errors->get('code')" class="mt-1.5" />
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-400 -mt-2">Kode dipakai di nomor transaksi, mis. TRX-DGO-2026-000001. Tidak bisa diubah setelah outlet dipakai bertransaksi.</p>
                        <div>
                            <x-input-label for="wizard-outlet-address" value="Alamat" />
                            <x-textarea wire:model="address" id="wizard-outlet-address" rows="2" placeholder="Alamat yang dicetak di struk outlet ini" />
                            <x-input-error :messages="$errors->get('address')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="wizard-outlet-phone" value="Telepon" />
                            <x-text-input wire:model="phone" id="wizard-outlet-phone" class="w-full" inputmode="tel" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                        </div>

                        <div class="space-y-3 border-t border-slate-800/80 pt-4">
                            <div>
                                <x-input-label for="wizard-store-type" value="Outlet ini usaha apa?" />
                                <x-select wire:model.live="storeType" id="wizard-store-type" class="w-full">
                                    <option value="">Sama seperti outlet lain{{ $this->baseStoreType ? ' ('.$this->baseStoreType->label().')' : '' }}</option>
                                    @foreach (\App\Enums\StoreType::cases() as $type)
                                        @continue($type === $this->baseStoreType)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </x-select>
                                <x-input-error :messages="$errors->get('storeType')" class="mt-1.5" />
                                @if ($storeType === '')
                                    <p class="text-[11px] text-slate-400 mt-1.5">Fitur usaha mengikuti outlet yang disalin di langkah berikutnya, atau outlet utama.</p>
                                @endif
                            </div>

                            @if ($storeType !== '')
                                @php $presetType = \App\Enums\StoreType::from($storeType); $sampleCount = \App\Support\StorePresets::sampleProductCount($presetType); @endphp
                                <p class="text-[11px] text-slate-400">Kategori {{ $presetType->label() }} dibuat khusus untuk outlet ini, jadi tidak muncul di kasir outlet lain. Pajak toko tidak berubah.</p>
                                @if ($sampleCount > 0)
                                    <x-checkbox-card wire:model="includeSampleProducts" label="Isi dengan produk contoh" :description="$sampleCount.' produk dengan harga perkiraan dan stok 0. Bisa diubah atau dihapus nanti.'" />
                                @endif

                                <div x-data="{ open: false }" class="rounded-xl border border-slate-800 bg-slate-950/40">
                                    <button type="button" x-on:click="open = ! open" :aria-expanded="open" class="w-full flex items-center justify-between gap-3 p-3 min-h-[44px] text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 rounded-xl">
                                        <span class="text-xs text-slate-200"><span class="font-semibold">Fitur khusus usaha:</span> {{ count($presetCapabilities) }} dinyalakan untuk outlet ini</span>
                                        <span class="text-[11px] text-emerald-400 shrink-0" x-text="open ? 'Tutup' : 'Atur'"></span>
                                    </button>
                                    <div x-show="open" x-cloak class="space-y-2 px-3 pb-3 max-h-[36vh] overflow-y-auto custom-scrollbar">
                                        @foreach ($this->presetCapabilityOptions as $option)
                                            @php $on = in_array($option['key'], $presetCapabilities, true); @endphp
                                            <button type="button" wire:click="togglePresetCapability(@js($option['key']))" wire:key="outlet-cap-{{ $option['key'] }}" aria-pressed="{{ $on ? 'true' : 'false' }}"
                                                @class([
                                                    'w-full flex items-start gap-3 p-3 min-h-[44px] rounded-xl border text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500',
                                                    'border-emerald-500/50 bg-emerald-500/10' => $on,
                                                    'border-slate-800 bg-slate-800/40 hover:border-slate-700' => ! $on,
                                                ])>
                                                <i data-lucide="{{ $on ? 'square-check' : 'square' }}" @class(['w-4 h-4 mt-0.5 shrink-0', 'text-emerald-400' => $on, 'text-slate-400' => ! $on])></i>
                                                <span class="min-w-0">
                                                    <span class="flex items-center gap-2 text-xs font-semibold text-slate-100">
                                                        {{ $option['label'] }}
                                                        @if ($option['recommended'])
                                                            <x-badge color="emerald">DISARANKAN</x-badge>
                                                        @endif
                                                    </span>
                                                    <span class="block text-[11px] text-slate-400 mt-0.5">{{ $option['description'] }}</span>
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        <x-modal-actions>
                            <x-secondary-button size="sm" type="button" wire:click="closeModal">Batal</x-secondary-button>
                            <x-primary-button size="sm" type="button" wire:click="nextStep">
                                <span>Lanjut: Pengaturan</span>
                                <i data-lucide="arrow-right" class="w-4 h-4 ms-1"></i>
                            </x-primary-button>
                        </x-modal-actions>
                    </div>
                @endif

                {{-- Langkah 2: Model Konfigurasi --}}
                @if ($wizardStep === 2)
                    <div class="space-y-4">
                        <div>
                            <p class="text-xs font-semibold text-slate-300">Pilih Model Pengaturan Outlet</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Tentukan bagaimana aturan pajak, biaya layanan, kasir, dan pembayaran cabang ini diberlakukan.</p>
                        </div>

                        <div class="grid sm:grid-cols-3 gap-3">
                            @if ($outlets->isNotEmpty())
                                <label @class([
                                    'relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all',
                                    'bg-emerald-500/10 border-emerald-500/50 text-slate-100 shadow-sm' => $configMode === 'copy',
                                    'bg-slate-950/60 border-slate-800 text-slate-300 hover:border-slate-700' => $configMode !== 'copy',
                                ])>
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-semibold text-xs text-slate-100">Salin Cabang Lain</span>
                                        <input type="radio" wire:model.live="configMode" value="copy" class="text-emerald-500 focus:ring-emerald-500 bg-slate-900 border-slate-700">
                                    </div>
                                    <p class="text-[11px] text-slate-400 leading-relaxed">Salin pajak, metode bayar, struk, dan harga jual dari outlet yang sudah berjalan.</p>
                                </label>
                            @endif

                            <label @class([
                                'relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all',
                                'bg-emerald-500/10 border-emerald-500/50 text-slate-100 shadow-sm' => $configMode === 'inherit',
                                'bg-slate-950/60 border-slate-800 text-slate-300 hover:border-slate-700' => $configMode !== 'inherit',
                            ])>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold text-xs text-slate-100">Ikuti Standar Toko</span>
                                    <input type="radio" wire:model.live="configMode" value="inherit" class="text-emerald-500 focus:ring-emerald-500 bg-slate-900 border-slate-700">
                                </div>
                                <p class="text-[11px] text-slate-400 leading-relaxed">Gunakan konfigurasi bawaan toko pusat secara otomatis tanpa penimpaan.</p>
                            </label>

                            <label @class([
                                'relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all',
                                'bg-emerald-500/10 border-emerald-500/50 text-slate-100 shadow-sm' => $configMode === 'custom',
                                'bg-slate-950/60 border-slate-800 text-slate-300 hover:border-slate-700' => $configMode !== 'custom',
                            ])>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold text-xs text-slate-100">Konfigurasi Mandiri</span>
                                    <input type="radio" wire:model.live="configMode" value="custom" class="text-emerald-500 focus:ring-emerald-500 bg-slate-900 border-slate-700">
                                </div>
                                <p class="text-[11px] text-slate-400 leading-relaxed">Atur spesifik tarif pajak, service charge, metode pembayaran aktif, dan printer.</p>
                            </label>
                        </div>

                        @if ($configMode === 'copy')
                            <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-3.5 space-y-3">
                                <div>
                                    <x-input-label for="wizard-copy-source" value="Pilih Outlet Sumber *" />
                                    <x-select wire:model="copyFromId" id="wizard-copy-source" class="w-full">
                                        @foreach ($outlets as $source)
                                            <option value="{{ $source->id }}">{{ $source->name }} ({{ $source->code }})</option>
                                        @endforeach
                                    </x-select>
                                    <x-input-error :messages="$errors->get('copyFromId')" class="mt-1.5" />
                                </div>
                                <p class="text-[11px] text-slate-400">Pajak, metode bayar, struk, dan harga jual khusus disalin sebagai titik awal. Setelah itu bisa diubah mandiri tanpa memengaruhi outlet sumber. Stok tidak disalin.</p>
                            </div>
                        @elseif ($configMode === 'custom')
                            <div class="space-y-3 max-h-[42vh] overflow-y-auto custom-scrollbar pr-1">
                                {{-- Pajak --}}
                                <section class="space-y-3 rounded-xl border border-slate-800 bg-slate-950/40 p-3.5">
                                    <x-checkbox-card wire:model.live="inheritTax" label="Pajak ikut pengaturan toko" description="Matikan untuk memakai tarif pajak khusus outlet ini." />
                                    @unless ($inheritTax)
                                        <x-checkbox-card wire:model.live="taxEnabled" label="Tambahkan pajak ke setiap transaksi di outlet ini" />
                                        @if ($taxEnabled)
                                            <div class="grid grid-cols-2 gap-3.5">
                                                <div>
                                                    <x-input-label for="wzd-tax-label" value="Nama pajak" />
                                                    <x-text-input wire:model="taxLabel" id="wzd-tax-label" class="w-full" />
                                                    <x-input-error :messages="$errors->get('taxLabel')" class="mt-1.5" />
                                                </div>
                                                <div>
                                                    <x-input-label for="wzd-tax-rate" value="Tarif (%)" />
                                                    <x-text-input wire:model="taxRate" id="wzd-tax-rate" inputmode="decimal" class="w-full font-mono" />
                                                    <x-input-error :messages="$errors->get('taxRate')" class="mt-1.5" />
                                                </div>
                                            </div>
                                        @endif
                                    @endunless
                                </section>

                                {{-- Service charge --}}
                                <section class="space-y-3 rounded-xl border border-slate-800 bg-slate-950/40 p-3.5">
                                    <x-checkbox-card wire:model.live="inheritService" label="Service charge ikut pengaturan toko" description="Matikan untuk memakai tarif service charge khusus outlet ini." />
                                    @unless ($inheritService)
                                        <div>
                                            <x-input-label for="wzd-service-rate" value="Service charge (%)" />
                                            <x-text-input wire:model="serviceChargeRate" id="wzd-service-rate" inputmode="decimal" class="w-full font-mono" placeholder="0 = tidak dipungut" />
                                            <x-input-error :messages="$errors->get('serviceChargeRate')" class="mt-1.5" />
                                        </div>
                                        @if (\App\Support\Features::enabled('business.order-type'))
                                            <x-checkbox-card wire:model="serviceChargeDineInOnly" label="Hanya untuk makan di tempat" />
                                        @endif
                                    @endunless
                                </section>

                                {{-- Metode bayar --}}
                                <section class="space-y-3 rounded-xl border border-slate-800 bg-slate-950/40 p-3.5">
                                    <x-checkbox-card wire:model.live="inheritPayments" label="Metode pembayaran ikut pengaturan toko" description="Matikan untuk memilih metode yang tersedia di kasir outlet ini." />
                                    @unless ($inheritPayments)
                                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                            @foreach (\App\Enums\PaymentMethod::cases() as $method)
                                                <label @class(['flex items-center gap-2.5 min-h-[44px] px-3 rounded-lg border border-slate-800 bg-slate-950 text-xs font-semibold text-slate-200', 'opacity-70' => $method->value === 'cash', 'cursor-pointer' => $method->value !== 'cash'])>
                                                    <x-checkbox wire:model="paymentMethods" value="{{ $method->value }}" :disabled="$method->value === 'cash'" :checked="$method->value === 'cash'" />
                                                    <i data-lucide="{{ $method->icon() }}" class="w-4 h-4 text-slate-400"></i>
                                                    <span class="truncate">{{ $method->shortLabel() }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endunless
                                </section>

                                {{-- Struk --}}
                                <section class="space-y-3 rounded-xl border border-slate-800 bg-slate-950/40 p-3.5">
                                    <x-checkbox-card wire:model.live="inheritReceipt" label="Struk printer thermal ikut pengaturan toko" description="Matikan untuk mengatur lebar kertas khusus outlet ini." />
                                    @unless ($inheritReceipt)
                                        <div>
                                            <x-input-label value="Lebar kertas printer thermal" />
                                            <x-segmented class="w-full sm:w-auto sm:inline-flex [&>*]:flex-1">
                                                <x-tab-button :active="$receiptWidth === '58'" wire:click="$set('receiptWidth', '58')">58 mm</x-tab-button>
                                                <x-tab-button :active="$receiptWidth === '80'" wire:click="$set('receiptWidth', '80')">80 mm</x-tab-button>
                                            </x-segmented>
                                        </div>
                                    @endunless
                                </section>
                            </div>
                        @else
                            <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-4 flex items-center gap-3 text-xs text-slate-300">
                                <i data-lucide="info" class="w-4 h-4 text-sky-400 shrink-0"></i>
                                <span>Outlet akan secara otomatis mewarisi tarif pajak, biaya layanan, metode bayar, dan pengaturan cetak struk dari pengaturan toko pusat.</span>
                            </div>
                        @endif

                        <x-modal-actions>
                            <x-secondary-button size="sm" type="button" wire:click="previousStep">
                                <i data-lucide="arrow-left" class="w-4 h-4 me-1"></i>
                                <span>Kembali</span>
                            </x-secondary-button>
                            <x-primary-button size="sm" type="button" wire:click="nextStep">
                                <span>Lanjut: Akses Kasir</span>
                                <i data-lucide="arrow-right" class="w-4 h-4 ms-1"></i>
                            </x-primary-button>
                        </x-modal-actions>
                    </div>
                @endif

                {{-- Langkah 3: Akses Staf / Kasir --}}
                @if ($wizardStep === 3)
                    <div class="space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold text-slate-300">Penugasan Pengguna ke Outlet</p>
                                <p class="text-[11px] text-slate-400 mt-0.5">Pilih siapa saja staf/kasir yang diizinkan bertransaksi di outlet ini.</p>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <x-secondary-button size="xs" type="button" wire:click="selectAllStaff">Pilih Semua</x-secondary-button>
                                <x-secondary-button size="xs" type="button" wire:click="clearStaffSelection">Reset</x-secondary-button>
                            </div>
                        </div>

                        <div class="space-y-2 max-h-[45vh] overflow-y-auto custom-scrollbar pr-1">
                            @foreach ($this->accessUsers as $accessUser)
                                @php $everywhere = $accessUser->hasAllOutletAccess(); @endphp
                                <label wire:key="wizard-access-{{ $accessUser->id }}" @class(['flex items-center gap-3 min-h-[48px] px-3.5 rounded-xl border border-slate-800 bg-slate-950 text-xs', 'opacity-70' => $everywhere, 'cursor-pointer' => ! $everywhere])>
                                    <x-checkbox wire:model="newOutletUserIds" value="{{ $accessUser->id }}" :disabled="$everywhere" :checked="$everywhere" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-semibold text-slate-200 truncate">{{ $accessUser->name }}</span>
                                        <span class="block text-slate-400 text-[11px] truncate">{{ $accessUser->username }}</span>
                                    </span>
                                    @if ($everywhere)
                                        <x-badge color="sky">SEMUA OUTLET</x-badge>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('newOutletUserIds')" />

                        <x-modal-actions>
                            <x-secondary-button size="sm" type="button" wire:click="previousStep">
                                <i data-lucide="arrow-left" class="w-4 h-4 me-1"></i>
                                <span>Kembali</span>
                            </x-secondary-button>
                            <x-primary-button size="sm" type="button" wire:click="nextStep">
                                <span>Lanjut: Ringkasan</span>
                                <i data-lucide="arrow-right" class="w-4 h-4 ms-1"></i>
                            </x-primary-button>
                        </x-modal-actions>
                    </div>
                @endif

                {{-- Langkah 4: Ringkasan & Konfirmasi --}}
                @if ($wizardStep === 4)
                    <div class="space-y-4">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 space-y-3">
                            <h4 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="store" class="w-4 h-4 text-emerald-400"></i>
                                Identitas Outlet
                            </h4>
                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <span class="text-slate-400 block text-[11px]">Nama Outlet</span>
                                    <span class="font-semibold text-slate-200">{{ $name }}</span>
                                </div>
                                <div>
                                    <span class="text-slate-400 block text-[11px]">Kode Transaksi</span>
                                    <span class="font-mono font-bold text-slate-200">{{ strtoupper($code) }}</span>
                                </div>
                                @if ($address)
                                    <div class="col-span-2">
                                        <span class="text-slate-400 block text-[11px]">Alamat Struk</span>
                                        <span class="text-slate-200">{{ $address }}</span>
                                    </div>
                                @endif
                                @if ($phone)
                                    <div>
                                        <span class="text-slate-400 block text-[11px]">No. Telepon</span>
                                        <span class="text-slate-200">{{ $phone }}</span>
                                    </div>
                                @endif
                                <div class="col-span-2">
                                    <span class="text-slate-400 block text-[11px]">Jenis Usaha</span>
                                    @if ($storeType !== '')
                                        <span class="text-slate-200">{{ \App\Enums\StoreType::from($storeType)->label() }}, {{ count($presetCapabilities) }} fitur khusus{{ $includeSampleProducts ? ', dengan produk contoh' : '' }}</span>
                                    @else
                                        <span class="text-slate-200">Sama seperti outlet lain{{ $this->baseStoreType ? ' ('.$this->baseStoreType->label().')' : '' }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 space-y-3">
                            <h4 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="sliders-horizontal" class="w-4 h-4 text-sky-400"></i>
                                Model Konfigurasi
                            </h4>
                            <div class="text-xs text-slate-300">
                                @if ($configMode === 'copy')
                                    @php
                                        $sourceOutlet = $outlets->firstWhere('id', (int) $copyFromId);
                                    @endphp
                                    <p>Salin pengaturan &amp; harga dari: <strong class="text-emerald-400">{{ $sourceOutlet?->name ?? 'Outlet #'.$copyFromId }}</strong></p>
                                    <p class="text-[11px] text-slate-400 mt-1">Pajak, metode bayar, lebar struk, dan harga jual khusus disalin utuh.</p>
                                @elseif ($configMode === 'custom')
                                    <div class="space-y-1 text-[11px] text-slate-300">
                                        <p>• Pajak: {{ $inheritTax ? 'Ikut toko' : ($taxEnabled ? "{$taxLabel} ({$taxRate}%)" : 'Nonaktif') }}</p>
                                        <p>• Service Charge: {{ $inheritService ? 'Ikut toko' : ($serviceChargeRate > 0 ? "{$serviceChargeRate}%" : 'Nonaktif') }}</p>
                                        <p>• Pembayaran: {{ $inheritPayments ? 'Ikut toko' : count($paymentMethods).' metode aktif' }}</p>
                                        <p>• Lebar Struk: {{ $inheritReceipt ? 'Ikut toko' : "{$receiptWidth} mm" }}</p>
                                    </div>
                                @else
                                    <p>Mengikuti pengaturan standar toko pusat tanpa penimpaan.</p>
                                @endif
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 space-y-2">
                            <h4 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="users" class="w-4 h-4 text-amber-400"></i>
                                Akses Kasir &amp; Staf
                            </h4>
                            @php
                                $assignedCount = count(array_unique(array_merge(
                                    $newOutletUserIds,
                                    $this->accessUsers->filter(fn($u) => $u->hasAllOutletAccess())->pluck('id')->all()
                                )));
                            @endphp
                            <p class="text-xs text-slate-300"><strong class="text-emerald-400">{{ $assignedCount }} pengguna</strong> memiliki akses langsung ke cabang ini.</p>
                        </div>

                        <x-modal-actions>
                            <x-secondary-button size="sm" type="button" wire:click="previousStep">
                                <i data-lucide="arrow-left" class="w-4 h-4 me-1"></i>
                                <span>Kembali</span>
                            </x-secondary-button>
                            <x-primary-button size="sm" type="button" wire:click="save" wire:loading.attr="disabled">
                                <x-loading-label target="save" loading="Menyimpan...">Simpan &amp; Buka Outlet</x-loading-label>
                            </x-primary-button>
                        </x-modal-actions>
                    </div>
                @endif
            </div>
        @endif
    </x-record-form-modal>

    {{-- Akses pengguna --}}
    <x-record-form-modal name="outlet-access" title="Akses Pengguna" subtitle="Siapa yang boleh memakai outlet ini" icon="users" max-width="lg" close-action="closeAuxModal('outlet-access')">
        <div class="space-y-3">
            <p class="text-[11px] text-slate-400">Pemilik dan pengguna bertanda "semua outlet" selalu punya akses. Pengguna lain hanya melihat outlet yang dicentang.</p>
            <div class="space-y-2 max-h-[50vh] overflow-y-auto custom-scrollbar">
                @foreach ($this->accessUsers as $accessUser)
                    @php $everywhere = $accessUser->hasAllOutletAccess(); @endphp
                    <label wire:key="access-{{ $accessUser->id }}" @class(['flex items-center gap-3 min-h-[48px] px-3 rounded-lg border border-slate-800 bg-slate-950 text-xs', 'opacity-70' => $everywhere, 'cursor-pointer' => ! $everywhere])>
                        <x-checkbox wire:model="accessUserIds" value="{{ $accessUser->id }}" :disabled="$everywhere" :checked="$everywhere" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-slate-200 truncate">{{ $accessUser->name }}</span>
                            <span class="block text-slate-400 truncate">{{ $accessUser->username }}</span>
                        </span>
                        @if ($everywhere)
                            <x-badge color="sky">SEMUA OUTLET</x-badge>
                        @endif
                    </label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('accessUserIds')" />
            <x-modal-actions>
                <x-secondary-button type="button" @click="$dispatch('close-modal', 'outlet-access')">Batal</x-secondary-button>
                <x-primary-button type="button" wire:click="saveAccess" wire:loading.attr="disabled">
                    <x-loading-label target="saveAccess" loading="Menyimpan...">Simpan Akses</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </div>
    </x-record-form-modal>

    {{-- Pajak, metode bayar, dan struk per outlet --}}
    <x-record-form-modal name="outlet-config" title="Pengaturan Kasir Outlet" subtitle="Bagian yang dicentang ikut pengaturan toko" icon="sliders-horizontal" max-width="2xl" close-action="closeAuxModal('outlet-config')">
        <form wire:submit="saveConfig" class="space-y-4">
            <section class="space-y-3 rounded-xl border border-slate-800 p-3.5">
                <x-checkbox-card wire:model.live="inheritTax" label="Pajak ikut pengaturan toko" description="Matikan untuk memakai tarif pajak khusus outlet ini." />
                @unless ($inheritTax)
                    <x-checkbox-card wire:model.live="taxEnabled" label="Tambahkan pajak ke setiap transaksi di outlet ini" />
                    @if ($taxEnabled)
                        <div class="grid grid-cols-2 gap-3.5">
                            <div>
                                <x-input-label for="cfg-tax-label" value="Nama pajak" />
                                <x-text-input wire:model="taxLabel" id="cfg-tax-label" class="w-full" />
                                <x-input-error :messages="$errors->get('taxLabel')" class="mt-1.5" />
                            </div>
                            <div>
                                <x-input-label for="cfg-tax-rate" value="Tarif (%)" />
                                <x-text-input wire:model="taxRate" id="cfg-tax-rate" inputmode="decimal" class="w-full font-mono" />
                                <x-input-error :messages="$errors->get('taxRate')" class="mt-1.5" />
                            </div>
                        </div>
                    @endif
                @endunless
            </section>

            <section class="space-y-3 rounded-xl border border-slate-800 p-3.5">
                <x-checkbox-card wire:model.live="inheritService" label="Service charge ikut pengaturan toko" description="Matikan untuk memakai tarif service charge khusus outlet ini." />
                @unless ($inheritService)
                    <div>
                        <x-input-label for="cfg-service-rate" value="Service charge (%)" />
                        <x-text-input wire:model="serviceChargeRate" id="cfg-service-rate" inputmode="decimal" class="w-full font-mono" placeholder="0 = tidak dipungut" />
                        <x-input-error :messages="$errors->get('serviceChargeRate')" class="mt-1.5" />
                    </div>
                    @if (\App\Support\Features::enabledAt('business.order-type', $configOutletId))
                        <x-checkbox-card wire:model="serviceChargeDineInOnly" label="Hanya untuk makan di tempat" />
                    @endif
                @endunless
            </section>

            <section class="space-y-3 rounded-xl border border-slate-800 p-3.5">
                <x-checkbox-card wire:model.live="inheritPayments" label="Metode pembayaran ikut pengaturan toko" description="Matikan untuk memilih metode yang tersedia di kasir outlet ini." />
                @unless ($inheritPayments)
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach (\App\Enums\PaymentMethod::cases() as $method)
                            <label @class(['flex items-center gap-2.5 min-h-[48px] px-3 rounded-lg border border-slate-800 bg-slate-950 text-xs font-semibold text-slate-200', 'opacity-70' => $method->value === 'cash', 'cursor-pointer' => $method->value !== 'cash'])>
                                <x-checkbox wire:model="paymentMethods" value="{{ $method->value }}" :disabled="$method->value === 'cash'" :checked="$method->value === 'cash'" />
                                <i data-lucide="{{ $method->icon() }}" class="w-4 h-4 text-slate-400"></i>
                                {{ $method->shortLabel() }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-[11px] text-slate-400">Tunai selalu aktif karena dipakai untuk kembalian dan rekap laci.</p>
                @endunless
            </section>

            <section class="space-y-3 rounded-xl border border-slate-800 p-3.5">
                <x-checkbox-card wire:model.live="inheritReceipt" label="Struk ikut pengaturan toko" description="Matikan untuk mengatur lebar kertas, teks atas dan bawah, serta cetak otomatis khusus outlet ini." />
                @unless ($inheritReceipt)
                    <div>
                        <x-input-label value="Lebar kertas printer thermal" />
                        <x-segmented class="w-full sm:w-auto sm:inline-flex [&>*]:flex-1">
                            <x-tab-button :active="$receiptWidth === '58'" wire:click="$set('receiptWidth', '58')">58 mm</x-tab-button>
                            <x-tab-button :active="$receiptWidth === '80'" wire:click="$set('receiptWidth', '80')">80 mm</x-tab-button>
                        </x-segmented>
                    </div>
                    <div>
                        <x-input-label for="cfg-header" value="Teks tambahan di atas struk" />
                        <x-textarea wire:model="receiptHeader" id="cfg-header" rows="2" />
                        <x-input-error :messages="$errors->get('receiptHeader')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="cfg-footer" value="Teks di bawah struk" />
                        <x-textarea wire:model="receiptFooter" id="cfg-footer" rows="2" />
                        <x-input-error :messages="$errors->get('receiptFooter')" class="mt-1.5" />
                    </div>
                    <x-checkbox-card wire:model="autoPrint" label="Cetak struk otomatis setelah bayar" />
                @endunless
            </section>

            <section class="space-y-3 rounded-xl border border-slate-800 p-3.5">
                <x-checkbox-card wire:model.live="inheritQris" label="QRIS ikut pengaturan toko" description="Matikan bila outlet ini punya QRIS statis sendiri." />
                @unless ($inheritQris)
                    <div>
                        <x-input-label for="cfg-qris" value="Teks QRIS statis outlet" />
                        <x-textarea wire:model="qrisPayload" id="cfg-qris" rows="3" class="font-mono text-[11px]" placeholder="000201010211..." />
                        <p class="text-[11px] text-slate-400 mt-1">Kosongkan untuk mematikan QRIS bernominal di outlet ini. Gambar QRIS bisa diunggah dari Pengaturan Kasir lalu teksnya disalin ke sini.</p>
                        <x-input-error :messages="$errors->get('qrisPayload')" class="mt-1.5" />
                    </div>
                @endunless
            </section>

            <section class="space-y-3 rounded-xl border border-slate-800 p-3.5">
                <x-checkbox-card wire:model.live="inheritRules" label="Aturan kasir ikut pengaturan toko" description="Matikan untuk mengatur kasbon, stok minus, dan tombol uang cepat khusus outlet ini." />
                @unless ($inheritRules)
                    <x-checkbox-card wire:model="allowCredit" label="Boleh kasbon di outlet ini" description="Sisa pembayaran dicatat sebagai piutang pelanggan." />
                    <x-checkbox-card wire:model="allowNegativeStock" label="Tetap bisa jual saat stok habis" />
                    <div>
                        <x-input-label for="cfg-quick-cash" value="Tombol uang cepat" />
                        <x-text-input wire:model="quickCash" id="cfg-quick-cash" class="w-full font-mono" placeholder="10000, 20000, 50000, 100000" />
                        <x-input-error :messages="$errors->get('quickCash')" class="mt-1.5" />
                    </div>
                @endunless
            </section>

            @if ($configOutletId && (\App\Support\Features::enabledAt('business.prescription', $configOutletId) || \App\Support\Features::enabledAt('business.batch-expiry', $configOutletId)))
                <section class="space-y-3 rounded-xl border border-slate-800 p-3.5">
                    <x-checkbox-card wire:model.live="inheritPharmacy" label="Aturan obat & kedaluwarsa ikut pengaturan toko" description="Matikan untuk mengatur resep, obat keras, dan barang kedaluwarsa khusus outlet ini." />
                    @unless ($inheritPharmacy)
                        @if (\App\Support\Features::enabledAt('business.prescription', $configOutletId))
                            <div>
                                <x-input-label for="cfg-rx-mode" value="Obat wajib resep" />
                                <x-select wire:model="prescriptionMode" id="cfg-rx-mode" class="w-full">
                                    @foreach (\App\Support\PosSettings::PRESCRIPTION_MODES as $mode => $label)
                                        <option value="{{ $mode }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                                <x-input-error :messages="$errors->get('prescriptionMode')" class="mt-1.5" />
                            </div>
                            <x-checkbox-card wire:model="allowControlledDrugs" label="Boleh jual narkotika & psikotropika lewat kasir" />
                        @endif
                        @if (\App\Support\Features::enabledAt('business.batch-expiry', $configOutletId))
                            <x-checkbox-card wire:model="blockExpiredSale" label="Tolak penjualan barang kedaluwarsa" />
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label for="cfg-near-percent" value="Diskon ED dekat (%)" />
                                    <x-text-input wire:model="nearExpiryPercent" id="cfg-near-percent" inputmode="decimal" class="w-full font-mono" placeholder="0 = tidak ada" />
                                    <x-input-error :messages="$errors->get('nearExpiryPercent')" class="mt-1.5" />
                                </div>
                                <div>
                                    <x-input-label for="cfg-near-days" value="Berlaku H- (hari)" />
                                    <x-text-input wire:model="nearExpiryDays" id="cfg-near-days" inputmode="numeric" class="w-full font-mono" />
                                    <x-input-error :messages="$errors->get('nearExpiryDays')" class="mt-1.5" />
                                </div>
                            </div>
                        @endif
                    @endunless
                </section>
            @endif

            <x-modal-actions>
                <x-secondary-button type="button" @click="$dispatch('close-modal', 'outlet-config')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="saveConfig" loading="Menyimpan...">Simpan Pengaturan Outlet</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>

    <x-record-form-modal name="outlet-capabilities" title="Jenis Usaha & Fitur Khusus" subtitle="Hanya berlaku di kasir outlet ini" icon="briefcase-business" max-width="lg" close-action="closeAuxModal('outlet-capabilities')">
        <form wire:submit="saveCapabilities" class="space-y-4">
            <div>
                <x-input-label for="cap-store-type" value="Jenis usaha outlet" />
                <x-select wire:model.live="outletStoreType" id="cap-store-type" class="w-full">
                    <option value="">Ikut jenis toko{{ app(\App\Support\CurrentTenant::class)->get()?->store_type ? ' ('.app(\App\Support\CurrentTenant::class)->get()->store_type->label().')' : '' }}</option>
                    @foreach (\App\Enums\StoreType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </x-select>
                <p class="text-[11px] text-slate-400 mt-1.5">Mengganti jenis usaha menambahkan kategori dan fitur yang disarankan. Kategori dan fitur lama tidak dihapus.</p>
            </div>

            <div class="space-y-2 max-h-[46vh] overflow-y-auto custom-scrollbar pr-1">
                @foreach ($this->outletCapabilityOptions as $option)
                    @php $on = in_array($option['key'], $outletCapabilities, true); @endphp
                    <button type="button" wire:click="toggleOutletCapability(@js($option['key']))" wire:key="cap-modal-{{ $option['key'] }}" aria-pressed="{{ $on ? 'true' : 'false' }}"
                        @class([
                            'w-full flex items-start gap-3 p-3 min-h-[44px] rounded-xl border text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500',
                            'border-emerald-500/50 bg-emerald-500/10' => $on,
                            'border-slate-800 bg-slate-800/40 hover:border-slate-700' => ! $on,
                        ])>
                        <i data-lucide="{{ $on ? 'square-check' : 'square' }}" @class(['w-4 h-4 mt-0.5 shrink-0', 'text-emerald-400' => $on, 'text-slate-400' => ! $on])></i>
                        <span class="min-w-0">
                            <span class="flex items-center gap-2 text-xs font-semibold text-slate-100">
                                {{ $option['label'] }}
                                @if ($option['recommended'])
                                    <x-badge color="emerald">DISARANKAN</x-badge>
                                @endif
                            </span>
                            <span class="block text-[11px] text-slate-400 mt-0.5">{{ $option['description'] }}</span>
                        </span>
                    </button>
                @endforeach
            </div>

            @if ($this->capabilityWarnings)
                <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-[11px] text-amber-200">
                    {{ collect($this->capabilityWarnings)->map(fn ($key) => \App\Support\Features::MODULES['business']['features'][explode('.', $key, 2)[1]]['label'])->implode(', ') }}
                    tidak dipakai outlet lain, jadi isiannya ikut tersembunyi dari form produk. Datanya tetap tersimpan dan muncul lagi saat fitur dinyalakan.
                </div>
            @endif

            <x-modal-actions>
                <x-secondary-button type="button" @click="$dispatch('close-modal', 'outlet-capabilities')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="saveCapabilities" loading="Menyimpan...">Simpan Fitur Outlet</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>

    {{-- Salin pengaturan & harga --}}
    <x-modal name="outlet-copy" :show="false" max-width="md">
        <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
            <x-modal-header icon="copy" title="Salin pengaturan & harga">Pajak, metode bayar, struk, dan harga jual khusus outlet ini diganti dengan milik outlet sumber.</x-modal-header>
            <div>
                <x-input-label for="copy-source" value="Salin dari" />
                <x-select wire:model="copySourceId" id="copy-source">
                    <option value="">Pilih outlet sumber</option>
                    @foreach ($outlets->where('id', '!=', $copyingToId) as $source)
                        <option value="{{ $source->id }}">{{ $source->name }}</option>
                    @endforeach
                </x-select>
                <x-input-error :messages="$errors->get('copySourceId')" class="mt-1.5" />
            </div>
            <x-checkbox-card wire:model="copyBusiness" label="Ikut salin jenis usaha & fitur khusus" description="Fitur khusus outlet ini diganti dengan milik outlet sumber, mis. resep atau tipe pesanan. Kategori dan data produk tidak berubah." />
            <div class="bg-amber-950/20 border border-amber-900/30 rounded-xl p-3 text-xs text-amber-300/90 flex items-center gap-2">
                <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0 text-amber-400"></i>
                <span>Penimpaan yang sudah ada di outlet ini akan diganti. Stok dan transaksi tidak berubah.</span>
            </div>
            <x-modal-actions>
                <x-secondary-button type="button" @click="$dispatch('close-modal', 'outlet-copy')">Batal</x-secondary-button>
                <x-primary-button type="button" wire:click="copyConfiguration" wire:loading.attr="disabled">
                    <x-loading-label target="copyConfiguration" loading="Menyalin...">Salin Sekarang</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </div>
    </x-modal>

    <x-confirm-delete-modal title="Hapus outlet ini?" description="Hanya outlet tanpa riwayat transaksi atau stok yang bisa dihapus. Selain itu, nonaktifkan saja." />
</div>
