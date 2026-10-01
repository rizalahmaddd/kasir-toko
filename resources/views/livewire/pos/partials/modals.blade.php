{{-- Ubah satu baris keranjang --}}
<x-modal name="pos-line" max-width="md">
    <template x-if="editing">
        <form @submit.prevent="saveLine()" class="p-5 sm:p-6 space-y-4">
            <div class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <h3 class="font-bold text-base text-slate-100 leading-snug" x-text="editing.name"></h3>
                    <p class="text-xs text-slate-400 tabular-nums mt-0.5" x-text="`${rupiah(editing.price)} / ${editing.unit}`"></p>
                </div>
                <x-icon-button icon="x" label="Tutup" x-on:click="$dispatch('close')" class="-me-2 -mt-1.5" />
            </div>

            <div>
                <x-input-label for="pos-line-qty" value="Jumlah" />
                <div class="flex items-center gap-2">
                    <x-secondary-button class="w-12 shrink-0" aria-label="Kurangi" @click="editing.quantity = String(Math.max(1, (parseFloat(String(editing.quantity).replace(',', '.')) || 1) - 1))">
                        <i data-lucide="minus" class="w-4 h-4"></i>
                    </x-secondary-button>
                    <x-text-input id="pos-line-qty" x-model="editing.quantity" inputmode="decimal" class="w-full text-center text-lg font-bold tabular-nums" @focus="$el.select()" />
                    <x-secondary-button class="w-12 shrink-0" aria-label="Tambah" @click="editing.quantity = String((parseFloat(String(editing.quantity).replace(',', '.')) || 0) + 1)">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                    </x-secondary-button>
                </div>
                <p class="text-[11px] text-slate-400 mt-1">Boleh desimal untuk barang timbangan, mis. 0,5 atau 1,25.</p>
            </div>

            <div x-show="config.canDiscount">
                <x-input-label for="pos-line-discount" value="Diskon barang ini (Rp)" />
                <x-text-input id="pos-line-discount" x-model="editing.discount" inputmode="numeric" class="w-full tabular-nums" placeholder="0" />
            </div>

            <div>
                <x-input-label for="pos-line-note" value="Catatan" />
                <x-text-input id="pos-line-note" x-model="editing.note" maxlength="150" class="w-full" placeholder="Mis. tanpa gula, ukuran L" />
            </div>

            <p x-show="editing.error" x-text="editing.error" class="text-xs text-rose-400"></p>

            <div class="flex flex-col-reverse sm:flex-row gap-2.5 pt-3 border-t border-slate-800/80">
                <x-secondary-button tone="danger" @click="removeEditing()" class="sm:mr-auto">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                    <span>Hapus dari keranjang</span>
                </x-secondary-button>
                <x-primary-button>Simpan Perubahan</x-primary-button>
            </div>
        </form>
    </template>
</x-modal>

{{-- Diskon transaksi --}}
<x-modal name="pos-discount" max-width="sm">
    <form @submit.prevent="saveDiscount()" class="p-5 sm:p-6 space-y-4">
        <x-modal-header title="Diskon transaksi" icon="badge-percent" closeable>
            Berlaku untuk seluruh belanja, dihitung setelah diskon per barang.
        </x-modal-header>

        <x-segmented class="w-full [&>*]:flex-1">
            <x-tab-button x-bind:aria-pressed="discountForm.type === 'amount'" @click="discountForm.type = 'amount'" x-bind:class="discountForm.type === 'amount' ? '!bg-emerald-500/10 !text-emerald-600 dark:!text-emerald-400 !border-emerald-500/30' : ''">Rupiah</x-tab-button>
            <x-tab-button x-bind:aria-pressed="discountForm.type === 'percent'" @click="discountForm.type = 'percent'" x-bind:class="discountForm.type === 'percent' ? '!bg-emerald-500/10 !text-emerald-600 dark:!text-emerald-400 !border-emerald-500/30' : ''">Persen</x-tab-button>
        </x-segmented>

        <div class="relative">
            <span x-show="discountForm.type === 'amount'" class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">Rp</span>
            <x-text-input x-model="discountForm.value" inputmode="decimal" aria-label="Nilai diskon" class="w-full text-lg font-bold tabular-nums" x-bind:class="discountForm.type === 'amount' ? 'pl-10' : 'pr-10'" placeholder="0" />
            <span x-show="discountForm.type === 'percent'" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">%</span>
        </div>

        <div x-show="discountForm.type === 'percent'" class="flex flex-wrap gap-1.5">
            <template x-for="preset in [5, 10, 15, 20, 25, 50]" :key="preset">
                <x-secondary-button size="xs" @click="discountForm.value = String(preset)" x-text="`${preset}%`"></x-secondary-button>
            </template>
        </div>

        <p x-show="discountForm.error" x-text="discountForm.error" class="text-xs text-rose-400"></p>

        <x-modal-actions>
            <x-secondary-button tone="danger" @click="removeDiscount()" x-show="cart.discountType">Hapus Diskon</x-secondary-button>
            <x-primary-button>Terapkan Diskon</x-primary-button>
        </x-modal-actions>
    </form>
</x-modal>

{{-- Pilih / tambah pelanggan --}}
<x-modal name="pos-customer" max-width="lg">
    <div class="p-5 sm:p-6 space-y-4">
        <x-modal-header title="Pelanggan" icon="users" closeable>
            Wajib dipilih kalau sebagian belanja dicatat sebagai kasbon.
        </x-modal-header>

        <template x-if="!customerForm.open">
            <div class="space-y-3">
                <div class="relative">
                    <i data-lucide="search" aria-hidden="true" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                    <input type="search" x-model="customerQuery" @input.debounce.300ms="searchCustomers()" placeholder="Cari nama atau nomor HP" aria-label="Cari pelanggan"
                        class="w-full h-11 bg-slate-950 border border-slate-800 rounded-lg pl-9 pr-3 text-sm text-slate-100 placeholder:text-slate-400 focus:outline-none focus:border-emerald-500">
                </div>

                <div class="rounded-xl border border-slate-800 divide-y divide-slate-800/60 max-h-[45vh] overflow-y-auto custom-scrollbar">
                    <button type="button" @click="selectCustomer(null)" class="w-full flex items-center gap-3 px-3 py-2.5 min-h-[52px] text-left hover:bg-slate-800/60">
                        <i data-lucide="user-round-x" class="w-4 h-4 text-slate-400"></i>
                        <span class="text-sm text-slate-300">Tanpa pelanggan (umum)</span>
                    </button>
                    <template x-for="customer in customerResults" :key="customer.id">
                        <button type="button" @click="selectCustomer(customer)" class="w-full flex items-center gap-3 px-3 py-2.5 min-h-[52px] text-left hover:bg-slate-800/60"
                            :class="cart.customer?.id === customer.id && 'bg-emerald-500/5'">
                            <span class="w-8 h-8 rounded-full bg-slate-800 text-slate-300 text-xs font-bold flex items-center justify-center uppercase shrink-0" x-text="customer.name.slice(0, 2)"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-slate-100 truncate" x-text="customer.name"></span>
                                <span class="block text-[11px] text-slate-400 truncate" x-text="[customer.phone, customer.code].filter(Boolean).join(' · ')"></span>
                            </span>
                            <span x-show="customer.due > 0" class="text-[11px] font-semibold text-amber-400 tabular-nums shrink-0" x-text="`Kasbon ${rupiah(customer.due)}`"></span>
                        </button>
                    </template>
                    <p x-show="!customerLoading && customerQuery && !customerResults.length" class="px-3 py-4 text-xs text-slate-400">Tidak ada pelanggan yang cocok.</p>
                    <p x-show="customerLoading" class="px-3 py-4 text-xs text-slate-400">Mencari…</p>
                </div>

                <x-secondary-button class="w-full" @click="customerForm.open = true; customerForm.name = customerQuery">
                    <i data-lucide="user-plus" class="w-4 h-4"></i>
                    <span>Tambah pelanggan baru</span>
                </x-secondary-button>
            </div>
        </template>

        <template x-if="customerForm.open">
            <form @submit.prevent="createCustomer()" class="space-y-3.5">
                <div>
                    <x-input-label for="pos-customer-name" value="Nama *" />
                    <x-text-input id="pos-customer-name" x-model="customerForm.name" maxlength="150" class="w-full" placeholder="Nama pelanggan" x-init="$nextTick(() => $el.focus())" />
                </div>
                <div>
                    <x-input-label for="pos-customer-phone" value="Nomor HP / WhatsApp" />
                    <x-text-input id="pos-customer-phone" x-model="customerForm.phone" type="tel" inputmode="tel" class="w-full font-mono" placeholder="08xxxxxxxxxx" />
                    <p class="text-[11px] text-slate-400 mt-1">Dipakai untuk kirim struk lewat WhatsApp.</p>
                </div>
                <p x-show="customerForm.error" x-text="customerForm.error" class="text-xs text-rose-400"></p>
                <x-modal-actions>
                    <x-secondary-button @click="customerForm.open = false">Kembali</x-secondary-button>
                    <x-primary-button x-bind:disabled="customerForm.saving">
                        <span x-text="customerForm.saving ? 'Menyimpan…' : 'Simpan & Pilih'"></span>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </template>
    </div>
</x-modal>

{{-- Tunda transaksi --}}
<x-modal name="pos-hold" max-width="sm">
    <form @submit.prevent="holdCurrent()" class="p-5 sm:p-6 space-y-4">
        <x-modal-header title="Tunda transaksi" icon="pause" closeable>
            Keranjang disimpan dan bisa dilanjutkan nanti, mis. pelanggan masih ambil barang lain.
        </x-modal-header>
        <div>
            <x-input-label for="pos-hold-label" value="Penanda (opsional)" />
            <x-text-input id="pos-hold-label" x-model="holdLabel" maxlength="60" class="w-full" placeholder="Mis. Bu Rina, meja 3, antrean 2" />
        </div>
        <x-modal-actions>
            <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
            <x-primary-button x-bind:disabled="holding">
                <span x-text="holding ? 'Menyimpan…' : 'Tunda Transaksi'"></span>
            </x-primary-button>
        </x-modal-actions>
    </form>
</x-modal>

{{-- Daftar transaksi tertunda --}}
<x-modal name="pos-held" max-width="lg">
    <div class="p-5 sm:p-6 space-y-4">
        <x-modal-header title="Transaksi tertunda" icon="clock" closeable>
            Melanjutkan transaksi akan menunda keranjang yang sedang terbuka.
        </x-modal-header>

        <p x-show="heldLoading" class="text-xs text-slate-400">Memuat…</p>

        <template x-if="!heldLoading && !heldOrders.length">
            <x-empty-state icon="clock" title="Tidak ada transaksi tertunda" description="Gunakan tombol Tunda di keranjang untuk menyimpan transaksi sementara." />
        </template>

        <ul class="rounded-xl border border-slate-800 divide-y divide-slate-800/60 max-h-[55vh] overflow-y-auto custom-scrollbar" x-show="heldOrders.length">
            <template x-for="order in heldOrders" :key="order.id">
                <li class="flex items-center gap-2 px-3 py-2">
                    <button type="button" @click="resumeHeld(order)" class="flex-1 min-w-0 text-left min-h-[48px] rounded-lg px-1 hover:bg-slate-800/50">
                        <span class="block text-sm font-semibold text-slate-100 truncate" x-text="order.label"></span>
                        <span class="block text-[11px] text-slate-400" x-text="`${order.item_count} baris · ${order.created}`"></span>
                    </button>
                    <span class="text-sm font-bold text-slate-100 tabular-nums" x-text="rupiah(order.total)"></span>
                    <x-icon-button icon="trash-2" label="Hapus transaksi tertunda" tone="danger" @click="deleteHeld(order)" />
                </li>
            </template>
        </ul>
    </div>
</x-modal>

{{-- Kosongkan keranjang --}}
<x-modal name="pos-clear" max-width="sm">
    <div class="p-5 sm:p-6 space-y-5">
        <x-modal-header title="Kosongkan keranjang?" icon="trash-2" tone="rose">
            Semua barang di keranjang dihapus. Kalau pelanggan hanya menunda, pakai tombol Tunda.
        </x-modal-header>
        <x-modal-actions>
            <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
            <x-danger-button type="button" @click="clearCart()">Kosongkan</x-danger-button>
        </x-modal-actions>
    </div>
</x-modal>

{{-- Scan barcode dengan kamera --}}
<x-modal name="pos-camera" max-width="md">
    <div class="p-5 sm:p-6 space-y-4" @close-modal.window="$event.detail === 'pos-camera' && stopCamera()">
        <x-modal-header title="Scan barcode" icon="camera" closeable>
            Arahkan kamera ke barcode produk sampai terbaca.
        </x-modal-header>
        <div class="relative rounded-xl overflow-hidden bg-black aspect-[4/3]">
            <video x-ref="cameraVideo" playsinline muted class="w-full h-full object-cover"></video>
            <div class="absolute inset-x-8 top-1/2 h-px bg-emerald-400/80"></div>
        </div>
        <p x-show="camera.error" x-text="camera.error" class="text-xs text-rose-400"></p>
        <x-secondary-button class="w-full" x-on:click="stopCamera(); $dispatch('close')">Tutup Kamera</x-secondary-button>
    </div>
</x-modal>

{{-- Pasangkan layar pelanggan --}}
<x-modal name="pos-display" max-width="2xl">
    <div class="p-5 sm:p-6 space-y-5">
        <x-modal-header title="Layar pelanggan" icon="monitor-smartphone" closeable>
            Layar yang menghadap pembeli: menampilkan belanjaan, total, kembalian, dan QRIS bernominal.
        </x-modal-header>

        <div class="grid sm:grid-cols-2 gap-3">
            <button type="button" @click="openDisplayWindow()" class="text-left rounded-xl border border-slate-800 bg-slate-950/60 hover:border-emerald-500/40 p-4 space-y-1.5">
                <span class="flex items-center gap-2 text-sm font-bold text-slate-100"><i data-lucide="app-window" class="w-4 h-4 text-emerald-400"></i> Monitor kedua</span>
                <span class="block text-[11px] text-slate-400">Buka jendela baru di perangkat ini, seret ke monitor pembeli, lalu ketuk tombol layar penuh.</span>
            </button>
            <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 space-y-1.5">
                <span class="flex items-center gap-2 text-sm font-bold text-slate-100"><i data-lucide="tablet-smartphone" class="w-4 h-4 text-emerald-400"></i> Perangkat lain</span>
                <span class="block text-[11px] text-slate-400">Pindai QR di bawah dengan kamera tablet/HP pembeli, atau buka tautannya di browser perangkat itu. Tidak perlu login.</span>
            </div>
        </div>

        <p x-show="display.loading" class="text-xs text-slate-400">Menyiapkan tautan…</p>

        <template x-if="display.link">
            <div class="flex flex-col sm:flex-row items-center gap-4 rounded-xl border border-slate-800 p-4">
                <div class="w-40 h-40 shrink-0 bg-white rounded-lg p-2 [&>svg]:w-full [&>svg]:h-full" x-html="display.link.qr"></div>
                <div class="min-w-0 flex-1 space-y-2 w-full">
                    <p class="text-[11px] text-slate-400">Tautan layar Anda</p>
                    <p class="font-mono text-[11px] text-slate-200 break-all select-all bg-slate-950 border border-slate-800 rounded-lg p-2" x-text="display.link.url"></p>
                    <div class="flex flex-wrap gap-2">
                        <x-secondary-button size="sm" @click="copyDisplayLink()">
                            <i data-lucide="copy" class="w-3.5 h-3.5"></i> Salin Tautan
                        </x-secondary-button>
                        <x-secondary-button size="sm" tone="danger" @click="loadDisplayLink(true)" x-bind:disabled="display.loading">
                            <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Ganti Kode
                        </x-secondary-button>
                    </div>
                    <p class="text-[11px] text-slate-400">Siapa pun yang memegang tautan ini bisa melihat keranjang Anda. Tekan Ganti Kode kalau perangkat layar hilang atau dipakai orang lain.</p>
                </div>
            </div>
        </template>
    </div>
</x-modal>
