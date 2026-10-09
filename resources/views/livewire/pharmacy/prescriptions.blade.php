<div class="space-y-4 sm:space-y-6">
    <x-list-toolbar
        description="Resep dokter beserta sisa obat yang belum ditebus. Obat wajib resep di kasir hanya bisa diserahkan dengan resep dari daftar ini."
        search-placeholder="Cari nomor resep, pasien, atau dokter..."
        :can-manage="$this->canManage()"
        add-label="Catat Resep"
    >
        <x-slot:filters>
            <x-select variant="filter" wire:model.live="statusFilter" aria-label="Filter status resep" class="flex-1 sm:flex-none">
                <option value="open">Belum tuntas</option>
                <option value="unverified">Menunggu verifikasi</option>
                @foreach (\App\Enums\PrescriptionStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
                <option value="all">Semua resep</option>
            </x-select>
        </x-slot:filters>
        <x-slot:actions>
            @can('pharmacy.prescription.view')
                <x-secondary-button size="sm" :href="route('pharmacy.report')" wire:navigate class="flex-1 sm:flex-none shrink-0">
                    <i data-lucide="clipboard-list" class="w-4 h-4"></i>
                    <span>Laporan Obat Keras</span>
                </x-secondary-button>
            @endcan
        </x-slot:actions>
    </x-list-toolbar>

    @if ($prescriptions->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state
                icon="file-heart"
                :title="$search ? 'Tidak ada resep yang cocok' : 'Belum ada resep di daftar ini'"
                :description="$search ? 'Coba nomor resep atau nama pasien lain.' : 'Catat resep yang dibawa pasien lewat tombol Catat Resep, lalu tautkan di kasir saat obatnya diserahkan.'"
            />
        </div>
    @else
        <x-table :pagination="$prescriptions">
            <x-slot:header>
                <tr>
                    <x-table.th sortable field="number">Resep</x-table.th>
                    <x-table.th sortable field="patient_name">Pasien</x-table.th>
                    <x-table.th class="hidden md:table-cell">Dokter</x-table.th>
                    <x-table.th>Status</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($prescriptions as $prescription)
                    <x-table.tr wire:key="prescription-{{ $prescription->id }}">
                        <x-table.td>
                            <a href="{{ route('pharmacy.prescriptions.show', $prescription) }}" wire:navigate class="font-mono font-semibold text-emerald-400 hover:text-emerald-300">{{ $prescription->number }}</a>
                            <div class="text-[11px] text-slate-400">{{ $prescription->prescription_date->translatedFormat('d M Y') }} · {{ $prescription->items_count }} obat</div>
                        </x-table.td>
                        <x-table.td>
                            <div class="font-medium text-slate-100">{{ $prescription->patient_name }}</div>
                            @if ($prescription->patient_age)
                                <div class="text-[11px] text-slate-400">{{ $prescription->patient_age }} tahun</div>
                            @endif
                        </x-table.td>
                        <x-table.td class="hidden md:table-cell text-slate-300">
                            dr. {{ $prescription->doctor_name }}
                            @if ($prescription->clinic_name)
                                <div class="text-[11px] text-slate-400">{{ $prescription->clinic_name }}</div>
                            @endif
                        </x-table.td>
                        <x-table.td>
                            <div class="flex flex-wrap gap-1">
                                <x-badge :color="$prescription->status->color()">{{ mb_strtoupper($prescription->status->label()) }}</x-badge>
                                @if ($prescription->status->isOpen())
                                    <x-badge :color="$prescription->isVerified() ? 'emerald' : 'amber'">{{ $prescription->isVerified() ? 'TERVERIFIKASI' : 'MENUNGGU APOTEKER' }}</x-badge>
                                @endif
                            </div>
                        </x-table.td>
                        <x-table.td align="right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('pharmacy.prescriptions.show', $prescription) }}" wire:navigate title="Detail {{ $prescription->number }}"
                                    class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </a>
                                @if ($this->canManage() && $prescription->status === \App\Enums\PrescriptionStatus::Pending)
                                    <x-icon-button icon="pencil" :label="'Ubah '.$prescription->number" tone="edit" @click="$dispatch('open-modal', 'record-form')" wire:click="openEditModal({{ $prescription->id }})" />
                                    <x-icon-button icon="ban" :label="'Batalkan '.$prescription->number" tone="danger" @click="$dispatch('open-modal', 'confirm-delete')" wire:click="confirmDelete({{ $prescription->id }})" />
                                @endif
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal :title="$editingId ? 'Ubah Resep' : 'Catat Resep'" subtitle="Data dokter, pasien, dan obat yang diresepkan" icon="file-heart" max-width="4xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 items-start">
                <div class="space-y-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 pb-1.5 border-b border-slate-800/80">Dokter</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <x-input-label for="doctor_name" value="Nama dokter *" />
                            <x-text-input wire:model="doctor_name" id="doctor_name" class="w-full" autofocus />
                            <x-input-error :messages="$errors->get('doctor_name')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="doctor_sip" value="No. SIP" />
                            <x-text-input wire:model="doctor_sip" id="doctor_sip" class="w-full" />
                            <x-input-error :messages="$errors->get('doctor_sip')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="clinic_name" value="Klinik / rumah sakit" />
                            <x-text-input wire:model="clinic_name" id="clinic_name" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="prescription_date" value="Tanggal resep *" />
                            <x-text-input wire:model="prescription_date" id="prescription_date" type="date" class="w-full" />
                            <x-input-error :messages="$errors->get('prescription_date')" class="mt-1.5" />
                        </div>
                    </div>
                </div>

                <div class="space-y-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 pb-1.5 border-b border-slate-800/80">Pasien</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <x-input-label for="patient_name" value="Nama pasien *" />
                            <x-text-input wire:model="patient_name" id="patient_name" class="w-full" />
                            <x-input-error :messages="$errors->get('patient_name')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="patient_age" value="Umur (tahun)" />
                            <x-text-input wire:model="patient_age" id="patient_age" inputmode="numeric" class="w-full" />
                            <x-input-error :messages="$errors->get('patient_age')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="patient_phone" value="No. HP" />
                            <x-text-input wire:model="patient_phone" id="patient_phone" inputmode="tel" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="patient_address" value="Alamat" />
                            <x-text-input wire:model="patient_address" id="patient_address" class="w-full" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-3 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                <div class="flex items-center justify-between gap-3 pb-1.5 border-b border-slate-800/80">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400">Obat diresepkan</p>
                    <x-secondary-button size="xs" type="button" wire:click="addItem"><i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah Obat</x-secondary-button>
                </div>
                <p class="text-[11px] text-slate-400">Jumlah dalam satuan dasar obat (mis. tablet), sudah termasuk iter yang diizinkan dokter.</p>
                @foreach ($items as $index => $item)
                    <div wire:key="rx-item-{{ $index }}" class="grid grid-cols-2 sm:grid-cols-[2fr_1.3fr_0.7fr_0.6fr_2fr_auto] gap-2 items-start rounded-lg border border-slate-800/80 p-2.5">
                        <div class="col-span-2 sm:col-span-1">
                            <x-input-label :for="'rx-product-'.$index" value="Obat" class="sm:sr-only" />
                            <x-select :id="'rx-product-'.$index" wire:model.live="items.{{ $index }}.product_id" searchable>
                                <option value="">Di luar katalog</option>
                                @foreach ($this->productOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }} ({{ $option->unit }})</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('items.'.$index.'.product_id')" class="mt-1" />
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <x-input-label :for="'rx-name-'.$index" value="Nama di resep" class="sm:sr-only" />
                            <x-text-input wire:model="items.{{ $index }}.product_name" :id="'rx-name-'.$index" class="w-full" placeholder="Nama di resep" />
                            <x-input-error :messages="$errors->get('items.'.$index.'.product_name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label :for="'rx-qty-'.$index" value="Jumlah" class="sm:sr-only" />
                            <x-text-input wire:model="items.{{ $index }}.quantity" :id="'rx-qty-'.$index" inputmode="decimal" class="w-full font-mono" placeholder="Jumlah" />
                            <x-input-error :messages="$errors->get('items.'.$index.'.quantity')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label :for="'rx-iter-'.$index" value="Iter" class="sm:sr-only" />
                            <x-text-input wire:model="items.{{ $index }}.iteration" :id="'rx-iter-'.$index" inputmode="numeric" class="w-full font-mono" placeholder="Iter" title="Berapa kali resep boleh diulang" />
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <x-input-label :for="'rx-dose-'.$index" value="Aturan pakai" class="sm:sr-only" />
                            <x-text-input wire:model="items.{{ $index }}.dosage_instructions" :id="'rx-dose-'.$index" class="w-full" placeholder="mis. 3 x sehari 1 tablet" />
                        </div>
                        <x-icon-button icon="trash" :label="'Hapus obat '.($index + 1)" tone="danger" wire:click="removeItem({{ $index }})" />
                    </div>
                @endforeach
                <x-input-error :messages="$errors->get('items')" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                <div>
                    <x-input-label for="photo" value="Foto resep" />
                    <x-file-input wire:model="photo" id="photo" accept="image/*" :file="$photo" icon="camera" label="Pilih atau ambil foto resep" hint="JPG/PNG, maks. 5 MB. Hanya bisa dilihat pengguna berizin." />
                    <x-input-error :messages="$errors->get('photo')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="notes" value="Catatan" />
                    <x-textarea wire:model="notes" id="notes" rows="3" class="w-full" />
                </div>
            </div>

            @can('pharmacy.prescription.verify')
                <x-checkbox-card wire:model="verifyNow" label="Verifikasi sekarang" description="Saya apoteker yang memeriksa resep ini; obatnya boleh langsung diserahkan." />
            @endcan

            <div class="pt-4 border-t border-slate-800/80 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2.5 [&>*]:w-full sm:[&>*]:w-auto">
                <x-secondary-button type="button" wire:click="closeModal">Batal</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save,photo">
                    <x-loading-label target="save" loading="Menyimpan...">Simpan Resep</x-loading-label>
                </x-primary-button>
            </div>
        </form>
    </x-record-form-modal>

    <x-modal name="confirm-delete" :show="false" max-width="sm">
        <div class="p-4 sm:p-6 space-y-4">
            <x-modal-header icon="ban" tone="rose" title="Batalkan resep ini?">Resep yang dibatalkan tidak bisa ditebus lagi. Datanya tetap tersimpan sebagai arsip.</x-modal-header>
            <x-modal-actions>
                <x-secondary-button @click="$dispatch('close')" wire:click="cancelDelete">Kembali</x-secondary-button>
                <x-danger-button wire:click="delete" wire:loading.attr="disabled">
                    <x-loading-label target="delete" loading="Membatalkan...">Batalkan Resep</x-loading-label>
                </x-danger-button>
            </x-modal-actions>
        </div>
    </x-modal>
</div>
