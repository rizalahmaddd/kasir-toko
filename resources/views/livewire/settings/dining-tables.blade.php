<div class="space-y-4 sm:space-y-6">
    @unless ($this->featureOn)
        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-700 dark:text-amber-200 flex items-start gap-3 shadow-xs">
            <i data-lucide="triangle-alert" class="w-5 h-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5"></i>
            <p class="leading-relaxed">Fitur Pesan Lewat QR belum menyala di outlet <strong class="text-amber-900 dark:text-amber-100">{{ $this->outlet->name }}</strong>. Aktifkan bersama Tipe Pesanan & Meja di <x-feature-link :href="route('settings.features')" wire:navigate class="font-bold underline text-amber-700 dark:text-amber-300 hover:text-amber-900 dark:hover:white">Pengaturan Fitur</x-feature-link> atau di kapabilitas outlet. Meja dan QR tetap bisa disiapkan dan dicetak dari sekarang.</p>
        </div>
    @endunless

    {{-- Focal Section: Outlet QR Status & Standee --}}
    <section class="rounded-3xl border border-slate-700/80 bg-slate-900 shadow-xl p-5 sm:p-7 grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
        <div class="space-y-3.5 min-w-0">
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="p-2 rounded-xl bg-slate-800 text-emerald-600 dark:text-emerald-400 border border-slate-700/80">
                    <i data-lucide="qr-code" class="w-5 h-5"></i>
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-black text-slate-100 tracking-tight">Pemesanan QR di {{ $this->outlet->name }}</h2>
                    <p class="text-xs text-slate-400">Pesan mandiri untuk pelanggan di tempat atau bawa pulang</p>
                </div>
                <div class="ml-auto sm:ml-0">
                    @if ($this->closedReason === null)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 text-xs font-black tracking-wide">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            TERBUKA
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-700 dark:text-amber-300 text-xs font-black tracking-wide">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            TUTUP
                        </span>
                    @endif
                </div>
            </div>

            <p class="text-xs sm:text-sm text-slate-300 leading-relaxed max-w-2xl">
                @if ($this->closedReason === null)
                    Pelanggan yang memindai QR meja atau standee kasir dapat langsung memesan. Pesanan masuk otomatis ke kotak masuk kasir.
                @elseif ($this->closedReason === 'no_shift')
                    Sakelar pemesanan sudah dibuka, namun belum ada shift kasir yang sedang berjalan. Buka shift di layar Kasir agar pesanan dapat ditanggapi staf.
                @elseif ($this->closedReason === 'disabled')
                    Pemesanan sedang dinonaktifkan. Pelanggan yang memindai QR hanya dapat melihat katalog menu tanpa bisa mengirim pesanan.
                @else
                    {{ \App\Services\Pos\SelfOrderGate::message($this->closedReason) }}
                @endif
            </p>

            @unless ($hasQris)
                <div class="flex items-center gap-2 text-xs text-slate-400 bg-slate-800/60 p-2.5 rounded-xl border border-slate-700/80">
                    <i data-lucide="info" class="w-4 h-4 text-amber-500 dark:text-amber-400 shrink-0"></i>
                    <span>QRIS belum diunggah; pelanggan hanya ditawari bayar di kasir. Atur QRIS di <x-feature-link :href="route('settings.pos')" wire:navigate class="text-emerald-600 dark:text-emerald-400 font-bold hover:underline">Pengaturan Kasir</x-feature-link>.</span>
                </div>
            @endunless

            @if ($this->canManage())
                <div class="flex flex-wrap items-center gap-2.5 pt-1">
                    @if ($this->closedReason === 'no_shift' && auth()->user()->can('pos.sell'))
                        <x-primary-button :href="route('pos.cashier')" wire:navigate>
                            <i data-lucide="play" class="w-4 h-4 mr-1.5"></i> Buka Shift di Kasir
                        </x-primary-button>
                    @endif
                    @if ($this->orderingOn)
                        <x-secondary-button wire:click="toggleOrdering" wire:loading.attr="disabled">
                            <x-loading-label target="toggleOrdering" loading="Menutup...">Tutup Pemesanan</x-loading-label>
                        </x-secondary-button>
                    @else
                        <x-primary-button wire:click="toggleOrdering" wire:loading.attr="disabled">
                            <x-loading-label target="toggleOrdering" loading="Membuka...">Buka Pemesanan</x-loading-label>
                        </x-primary-button>
                    @endif
                </div>
            @endif
        </div>

        {{-- Standee Take-Away Preview Card --}}
        <div class="flex items-center gap-4 rounded-2xl border border-slate-700/80 bg-slate-800/40 p-4 shadow-sm hover:border-slate-600 transition">
            <div class="w-24 h-24 shrink-0 rounded-xl bg-white p-2 border border-slate-300 dark:border-slate-700 shadow-xs [&>svg]:w-full [&>svg]:h-full" role="img" aria-label="QR take away {{ $this->outlet->name }}">{!! $this->standee['qr'] !!}</div>
            <div class="space-y-1.5 min-w-0">
                <span class="inline-flex px-2 py-0.5 rounded bg-emerald-500/15 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 text-[10px] font-black uppercase tracking-wider">Take Away</span>
                <p class="text-xs font-bold text-slate-100">QR Standee Kasir</p>
                <p class="text-[11px] text-slate-400 leading-snug max-w-[13rem]">Dipajang di meja kasir. Pelanggan pesan bawa pulang dengan nomor antrean.</p>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pt-1">
                    <a href="{{ route('print.self-order-standee') }}" target="_blank" rel="noopener" class="text-xs font-bold text-emerald-600 dark:text-emerald-400 hover:underline inline-flex items-center gap-1">
                        <i data-lucide="printer" class="w-3.5 h-3.5"></i> Cetak Standee (A5)
                    </a>
                    @if ($this->canManage())
                        <button type="button" wire:click="confirmRotate(0)" class="text-xs font-semibold text-slate-400 hover:text-slate-200">Reset QR</button>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- Order Flow Settings --}}
    @if ($this->canManage())
        <form wire:submit="saveSettings" class="bg-slate-900 border border-slate-700/80 rounded-2xl p-4 sm:p-6 space-y-4 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-700/80 pb-3">
                <div class="flex items-center gap-2">
                    <i data-lucide="settings-2" class="w-4 h-4 text-emerald-600 dark:text-emerald-400"></i>
                    <h3 class="text-sm font-bold text-slate-100">Alur & Kebijakan Pesanan QR</h3>
                </div>
                <span class="text-[11px] text-slate-400">Berlaku untuk outlet {{ $this->outlet->name }}</span>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl bg-slate-800/40 border border-slate-700/80 p-3.5 space-y-1.5">
                    <x-input-label for="kitchenFlow" value="Alur Masuk Tiket Dapur" class="font-bold text-slate-200" />
                    <x-select id="kitchenFlow" wire:model.live="kitchenFlow">
                        <option value="confirm">Setelah dikonfirmasi kasir</option>
                        <option value="auto">Langsung masuk dapur otomatis</option>
                    </x-select>
                    <p class="text-[11px] text-slate-400">Mode konfirmasi mencegah pesanan palsu langsung dimasak.</p>
                    <x-input-error :messages="$errors->get('kitchenFlow')" class="mt-1" />
                </div>

                <div class="rounded-xl bg-slate-800/40 border border-slate-700/80 p-3.5 space-y-1.5">
                    <x-input-label for="dineInPayment" value="Waktu Pembayaran Makan di Tempat" class="font-bold text-slate-200" />
                    <x-select id="dineInPayment" wire:model="dineInPayment">
                        <option value="after">Setelah makan (Open bill meja)</option>
                        <option value="before">Sebelum pesanan diproses</option>
                    </x-select>
                    <p class="text-[11px] text-slate-400">Take away selalu dibayar sebelum pesanan dibuat.</p>
                    <x-input-error :messages="$errors->get('dineInPayment')" class="mt-1" />
                </div>

                <div class="rounded-xl bg-slate-800/40 border border-slate-700/80 p-3.5 space-y-1.5">
                    <x-input-label for="expireMinutes" value="Batalkan Otomatis Jika Menggantung" class="font-bold text-slate-200" />
                    <div class="relative">
                        <x-text-input wire:model="expireMinutes" id="expireMinutes" type="number" min="1" max="240" class="w-full pr-16 font-mono" />
                        <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-xs text-slate-400 font-semibold">menit</span>
                    </div>
                    <p class="text-[11px] text-slate-400">Untuk pesanan yang belum ditanggapi staf.</p>
                    <x-input-error :messages="$errors->get('expireMinutes')" class="mt-1" />
                </div>
            </div>

            @if ($kitchenFlow === 'auto')
                <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3.5 text-xs text-amber-700 dark:text-amber-200 flex items-start gap-2.5">
                    <i data-lucide="triangle-alert" class="w-4 h-4 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5"></i>
                    <p class="leading-relaxed"><strong>Perhatian:</strong> Dengan mode langsung tanpa konfirmasi, pesanan iseng dari luar bisa langsung tercetak di dapur. Gunakan hanya jika meja diawasi pelayan.</p>
                </div>
            @endif

            <div class="flex justify-end pt-1">
                <x-primary-button type="submit" size="sm" wire:loading.attr="disabled">
                    <x-loading-label target="saveSettings" loading="Menyimpan...">Simpan Pengaturan Alur</x-loading-label>
                </x-primary-button>
            </div>
        </form>
    @endif

    {{-- Dining Tables List Toolbar --}}
    <x-list-toolbar
        description="Setiap meja memiliki kode QR unik. Nama meja tercantum di open bill dan tiket dapur."
        search-placeholder="Cari nama meja atau area..."
        :can-manage="$this->canManage()"
        add-label="Tambah Meja"
    >
        <x-slot:actions>
            @if ($tableCount > 0)
                <a href="{{ route('print.table-grid') }}" target="_blank" rel="noopener" class="inline-flex shrink-0 whitespace-nowrap items-center justify-center gap-1.5 h-11 sm:h-[38px] px-3.5 rounded-xl border border-slate-700 bg-slate-800 text-xs font-bold text-slate-200 hover:bg-slate-700 shadow-xs transition">
                    <i data-lucide="printer" class="w-4 h-4 text-emerald-400"></i>
                    <span>Cetak Semua QR (A4)</span>
                </a>
            @endif
        </x-slot:actions>
    </x-list-toolbar>

    @if ($tables->isEmpty())
        <div class="bg-slate-900 rounded-2xl border border-slate-700/80 overflow-hidden">
            <x-empty-state
                icon="qr-code"
                title="{{ $search ? 'Tidak ada meja yang cocok' : 'Belum ada meja' }}"
                description="{{ $search ? 'Coba kata kunci lain, atau bersihkan pencarian.' : 'Tambahkan meja lewat tombol Tambah Meja di atas, lalu cetak QR untuk ditempel di meja.' }}"
            />
        </div>
    @else
        <x-table :pagination="$tables">
            <thead class="bg-slate-800/80 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-700/80">
                <tr>
                    <x-table.th sortable field="name">Meja</x-table.th>
                    <x-table.th sortable field="area">Area / Ruangan</x-table.th>
                    <x-table.th sortable field="capacity" align="right">Kapasitas</x-table.th>
                    <x-table.th sortable field="is_active">Status QR</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                @foreach ($tables as $table)
                    <x-table.tr wire:key="table-{{ $table->id }}">
                        <x-table.td class="font-extrabold text-slate-100">
                            <div class="inline-flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-lg bg-slate-800 border border-slate-700 text-emerald-600 dark:text-emerald-400 font-mono text-xs font-black">
                                    {{ $table->name }}
                                </span>
                                <span>Meja {{ $table->name }}</span>
                            </div>
                        </x-table.td>
                        <x-table.td class="text-slate-400">
                            @if ($table->area)
                                <span class="px-2 py-0.5 rounded-md bg-slate-800/60 border border-slate-700/60 text-slate-300 text-xs">{{ $table->area }}</span>
                            @else
                                <span class="text-slate-500">-</span>
                            @endif
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-300">
                            <span class="inline-flex items-center gap-1 font-semibold text-xs">
                                <i data-lucide="users" class="w-3.5 h-3.5 text-slate-500"></i>
                                {{ $table->capacity }} kursi
                            </span>
                        </x-table.td>
                        <x-table.td><x-status-badge :active="$table->is_active" /></x-table.td>
                        <x-table.td align="right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('print.table-tent', $table) }}" target="_blank" rel="noopener" aria-label="Cetak QR Meja {{ $table->name }}" title="Cetak Tent Card Meja (A6)" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-slate-400 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-800 transition">
                                    <i data-lucide="printer" class="w-4 h-4"></i>
                                </a>
                                @if ($this->canManage())
                                    <x-icon-button icon="refresh-cw" :label="'Buat ulang QR Meja '.$table->name" wire:click="confirmRotate({{ $table->id }})" />
                                    <x-icon-button icon="pencil" :label="'Edit Meja '.$table->name" tone="edit" @click="$dispatch('open-modal', 'record-form')" wire:click="openEditModal({{ $table->id }})" />
                                    <x-icon-button icon="trash" :label="'Hapus Meja '.$table->name" tone="danger" @click="$dispatch('open-modal', 'confirm-delete')" wire:click="confirmDelete({{ $table->id }})" />
                                @endif
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal
        :title="$editingId ? 'Edit Meja' : 'Tambah Meja'"
        subtitle="Nama meja dipakai di QR, open bill, dan tiket dapur"
        icon="qr-code"
        max-width="lg"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                    <x-input-label for="name" value="Nama / nomor meja *" />
                    <x-text-input wire:model="name" id="name" class="w-full" maxlength="30" placeholder="05" autofocus />
                    <p class="text-[11px] text-slate-400 mt-1">Tanpa kata "Meja"; ditambahkan otomatis.</p>
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="area" value="Area" />
                    <x-text-input wire:model="area" id="area" class="w-full" maxlength="50" placeholder="Teras, Lantai 2" />
                    <x-input-error :messages="$errors->get('area')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="capacity" value="Jumlah kursi *" />
                    <x-text-input wire:model="capacity" id="capacity" type="number" min="1" class="w-full font-mono" />
                    <x-input-error :messages="$errors->get('capacity')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="sort_order" value="Urutan cetak" />
                    <x-text-input wire:model="sort_order" id="sort_order" type="number" min="0" class="w-full font-mono" />
                    <x-input-error :messages="$errors->get('sort_order')" class="mt-1.5" />
                </div>
            </div>

            <x-checkbox-card wire:model="is_active" label="Meja aktif" description="QR meja nonaktif tidak bisa dipakai memesan." />

            <div class="pt-4 border-t border-slate-800/80 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2.5 [&>*]:w-full sm:[&>*]:w-auto">
                <x-secondary-button type="button" wire:click="closeModal">Batal</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <x-loading-label target="save" loading="Menyimpan...">{{ $editingId ? 'Perbarui Meja' : 'Simpan Meja' }}</x-loading-label>
                </x-primary-button>
            </div>
        </form>
    </x-record-form-modal>

    <x-confirm-delete-modal title="Hapus meja ini?" description="QR meja ini berhenti berfungsi. Riwayat pesanannya tetap tersimpan." />

    <x-modal name="rotate-token" :show="false" max-width="sm">
        <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
            <x-modal-header icon="refresh-cw" tone="amber" title="Buat ulang QR?">QR yang sudah dicetak langsung tidak bisa dipakai. Cetak dan pasang QR baru setelah ini.</x-modal-header>

            <x-modal-actions>
                <x-secondary-button @click="$dispatch('close')" class="justify-center">Batal</x-secondary-button>
                <x-primary-button wire:click="rotateToken" wire:loading.attr="disabled" class="justify-center">
                    <x-loading-label target="rotateToken" loading="Membuat...">Buat QR Baru</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </div>
    </x-modal>
</div>
