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

            <div x-show="editing.serial" x-cloak class="flex items-center gap-3 rounded-xl border border-slate-800 bg-slate-900/60 px-3 py-2.5">
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Nomor seri / IMEI</p>
                    <p class="text-sm text-slate-100 font-mono truncate" x-text="editing.serials || 'Belum dipilih'"></p>
                </div>
                <x-secondary-button size="sm" @click="editSerials(cart.items.find((l) => l.key === editing.key))">Pilih Nomor Seri</x-secondary-button>
            </div>

            <div x-show="editing.hasModifierGroups" x-cloak class="flex items-center gap-3 rounded-xl border border-slate-800 bg-slate-900/60 px-3 py-2.5">
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Pilihan tambahan</p>
                    <p class="text-sm text-slate-100 truncate" x-text="editing.modifiers || 'Belum ada pilihan'"></p>
                </div>
                <x-secondary-button size="sm" @click="editModifiers()">Ubah Pilihan</x-secondary-button>
            </div>

            <div x-show="editing.units.length" x-cloak>
                <x-input-label value="Satuan" />
                <x-segmented class="w-full flex-wrap [&>*]:flex-1">
                    <x-tab-button x-bind:aria-pressed="editing.unit_id === null" @click="editing.unit_id = null"
                        x-bind:class="editing.unit_id === null ? '!bg-emerald-500/10 !text-emerald-600 dark:!text-emerald-400 !border-emerald-500/30' : ''">
                        <span x-text="`${editing.base_unit} · ${rupiah(editing.base_price)}`"></span>
                    </x-tab-button>
                    <template x-for="unit in editing.units" :key="unit.id">
                        <x-tab-button x-bind:aria-pressed="editing.unit_id === unit.id" @click="editing.unit_id = unit.id"
                            x-bind:class="editing.unit_id === unit.id ? '!bg-emerald-500/10 !text-emerald-600 dark:!text-emerald-400 !border-emerald-500/30' : ''">
                            <span x-text="`${unit.name} (${quantity(unit.factor)}) · ${rupiah(unit.price)}`"></span>
                        </x-tab-button>
                    </template>
                </x-segmented>
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

{{-- Pilih varian produk induk --}}
<x-modal name="pos-variants" max-width="md">
    <template x-if="variantPick">
        <div class="p-5 sm:p-6 space-y-4">
            <x-modal-header icon="layers" closeable>
                <x-slot:title><span x-text="variantPick.product.name"></span></x-slot:title>
                Pilih varian yang dibeli. Stok dihitung per varian.
            </x-modal-header>
            <div class="grid grid-cols-2 gap-2 max-h-[55vh] overflow-y-auto custom-scrollbar">
                <template x-for="child in variantPick.product.variants" :key="child.id">
                    <button type="button" @click="pickVariant(child)" :disabled="child.track && !config.allowNegative && child.stock <= 0"
                        class="min-h-[56px] rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 text-left hover:border-emerald-500/50 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/60">
                        <span class="block text-[13px] font-semibold text-slate-100" x-text="child.variant || child.name"></span>
                        <span class="block text-[11px] text-slate-400 tabular-nums" x-text="`${rupiah(child.price)} · stok ${child.track ? quantity(child.stock) : '∞'}`"></span>
                    </button>
                </template>
            </div>
        </div>
    </template>
</x-modal>

{{-- Pilih nomor seri / IMEI --}}
<x-modal name="pos-serials" max-width="md">
    <template x-if="serialPick">
        <form @submit.prevent="confirmSerials()" class="p-5 sm:p-6 space-y-4">
            <x-modal-header icon="scan-barcode" closeable>
                <x-slot:title><span x-text="serialPick.product.name"></span></x-slot:title>
                Pilih unit yang diserahkan. Jumlah barang mengikuti banyaknya nomor seri.
            </x-modal-header>
            <div class="flex gap-2">
                <x-text-input x-model="serialPick.manual" @keydown.enter.prevent="addManualSerial()" class="flex-1 font-mono" placeholder="Scan / ketik nomor seri" aria-label="Nomor seri" />
                <x-secondary-button @click="addManualSerial()">Tambah</x-secondary-button>
            </div>
            <p x-show="serialPick.loading" class="text-xs text-slate-400">Memuat nomor seri…</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-[40vh] overflow-y-auto custom-scrollbar" x-show="serialPick.options.length || serialPick.selected.length">
                <template x-for="serial in [...new Set([...serialPick.selected, ...serialPick.options])]" :key="serial">
                    <label class="flex items-center gap-2.5 min-h-[44px] rounded-lg border px-3 text-sm font-mono cursor-pointer"
                        :class="serialPick.selected.includes(serial) ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-300' : 'border-slate-800 text-slate-200'">
                        <input type="checkbox" class="w-4 h-4 rounded border-slate-700 bg-slate-950 text-emerald-500 focus:ring-emerald-500" :checked="serialPick.selected.includes(serial)" @change="toggleSerial(serial)">
                        <span x-text="serial"></span>
                    </label>
                </template>
            </div>
            <p x-show="!serialPick.loading && !serialPick.options.length && !serialPick.selected.length" class="text-xs text-amber-400">Tidak ada nomor seri tersedia di outlet ini. Daftarkan lewat halaman Nomor Seri atau Stok Masuk.</p>
            <p x-show="serialPick.error" x-text="serialPick.error" class="text-xs text-rose-400"></p>
            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button><span x-text="`Pakai ${serialPick.selected.length} unit`"></span></x-primary-button>
            </x-modal-actions>
        </form>
    </template>
</x-modal>

{{-- Pilihan tambahan (modifier) --}}
<x-modal name="pos-modifiers" max-width="md">
    <template x-if="modPick">
        <form @submit.prevent="confirmModifiers()" class="p-5 sm:p-6 space-y-4">
            <x-modal-header icon="list-plus" closeable>
                <x-slot:title><span x-text="modPick.product.name"></span></x-slot:title>
                Pilih varian pesanan. Harga tambahan dihitung per porsi.
            </x-modal-header>

            <div class="space-y-4 max-h-[55vh] overflow-y-auto custom-scrollbar -mx-1 px-1">
                <template x-for="group in modPick.groups" :key="group.id">
                    <fieldset>
                        <legend class="flex items-baseline justify-between gap-2 w-full mb-1.5">
                            <span class="text-sm font-semibold text-slate-100" x-text="group.name"></span>
                            <span class="text-[11px]" :class="group.min > 0 ? 'text-amber-400 font-semibold' : 'text-slate-400'" x-text="group.rule"></span>
                        </legend>
                        <div class="grid grid-cols-2 gap-2">
                            <template x-for="modifier in group.modifiers" :key="modifier.id">
                                <button type="button" @click="toggleModifier(group, modifier)" :aria-pressed="isModifierPicked(group, modifier)"
                                    class="min-h-[48px] rounded-lg border px-3 py-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/60"
                                    :class="isModifierPicked(group, modifier) ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-300' : 'border-slate-800 bg-slate-900 text-slate-200 hover:border-slate-700'">
                                    <span class="block text-[13px] font-semibold leading-snug" x-text="modifier.name"></span>
                                    <span class="block text-[11px] tabular-nums" :class="isModifierPicked(group, modifier) ? 'text-emerald-400' : 'text-slate-400'" x-text="modifier.price > 0 ? `+${rupiah(modifier.price)}` : 'Tanpa biaya'"></span>
                                </button>
                            </template>
                        </div>
                    </fieldset>
                </template>
            </div>

            <p x-show="modPick.error" x-text="modPick.error" class="text-xs text-rose-400"></p>

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button>
                    <span x-text="modPick.lineKey ? 'Simpan Pilihan' : `Tambah ${modPickExtra > 0 ? '(+' + rupiah(modPickExtra) + ')' : ''}`"></span>
                </x-primary-button>
            </x-modal-actions>
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
                            <div class="text-right shrink-0">
                                <span x-show="customer.due > 0" class="block text-[11px] font-semibold text-amber-400 tabular-nums" x-text="`Kasbon ${rupiah(customer.due)}`"></span>
                                <span x-show="customer.credit_limit !== null" class="block text-[10px] tabular-nums" :class="(customer.due || 0) >= customer.credit_limit ? 'text-rose-400 font-bold' : 'text-slate-400'" x-text="`Sisa limit ${rupiah(Math.max(0, customer.credit_limit - (customer.due || 0)))}`"></span>
                            </div>
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
                        <span class="flex items-center gap-1.5 min-w-0">
                            <span class="text-sm font-semibold text-slate-100 truncate" x-text="order.label"></span>
                            <span x-show="order.table" class="shrink-0 px-1.5 rounded bg-sky-500/10 text-sky-400 text-[10px] font-bold">OPEN BILL</span>
                        </span>
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
    @php
        $currentTenant = app(\App\Support\CurrentTenant::class)->get();
    @endphp

    @if ($currentTenant && ! $currentTenant->isPro())
        <div class="p-6 sm:p-8 text-center space-y-5">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500/20 to-amber-600/10 border border-amber-500/30 flex items-center justify-center mx-auto shadow-lg shadow-amber-500/10">
                <i data-lucide="sparkles" class="w-8 h-8 text-amber-400"></i>
            </div>
            <div class="space-y-2 max-w-md mx-auto">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-500/10 text-amber-400 border border-amber-500/20">
                    Fitur Layar Khusus Pro
                </span>
                <h3 class="text-xl font-bold text-slate-100">Layar Pelanggan (Customer Display)</h3>
                <p class="text-sm text-slate-400 leading-relaxed">
                    Tampilkan keranjang belanja realtime, total nominal belanja, dan QRIS dinamis langsung di monitor kedua atau tablet menghadap pembeli.
                </p>
            </div>
            <div class="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-primary-button size="sm" :href="route('settings.subscription')" wire:navigate class="!bg-gradient-to-r !from-amber-500 !to-amber-600 hover:!from-amber-400 hover:!to-amber-500 !text-slate-950 font-bold">
                    <i data-lucide="sparkles" class="w-4 h-4"></i>
                    <span>Upgrade ke Pro Sekarang</span>
                </x-primary-button>
                <x-secondary-button size="sm" @click="$dispatch('close-modal', 'pos-display')">
                    Tutup
                </x-secondary-button>
            </div>
        </div>
    @else
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
    @endif
</x-modal>

{{-- Tautkan resep obat --}}
<x-modal name="pos-prescription" max-width="lg">
    <div class="p-5 sm:p-6 space-y-4">
        <x-modal-header title="Resep obat" icon="file-heart" closeable>
            <span x-text="config.prescription?.mode === 'strict' ? 'Obat wajib resep hanya bisa diserahkan dengan resep yang sudah diverifikasi apoteker.' : 'Catat dokter dan pasien untuk obat wajib resep.'"></span>
        </x-modal-header>

        <x-segmented class="w-full [&>*]:flex-1" x-show="config.prescription?.canView && canDraftPrescription">
            <x-tab-button x-bind:aria-pressed="rx.tab === 'saved'" @click="rx.tab = 'saved'; searchPrescriptions()" x-bind:class="rx.tab === 'saved' ? '!bg-emerald-500/10 !text-emerald-600 dark:!text-emerald-400 !border-emerald-500/30' : ''">Resep tersimpan</x-tab-button>
            <x-tab-button x-bind:aria-pressed="rx.tab === 'draft'" @click="rx.tab = 'draft'" x-bind:class="rx.tab === 'draft' ? '!bg-emerald-500/10 !text-emerald-600 dark:!text-emerald-400 !border-emerald-500/30' : ''">Isi langsung</x-tab-button>
        </x-segmented>

        <template x-if="rx.tab === 'saved'">
            <div class="space-y-3">
                <div class="relative">
                    <i data-lucide="search" aria-hidden="true" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                    <input type="search" x-model="rx.query" @input.debounce.300ms="searchPrescriptions()" placeholder="Cari nomor resep, pasien, atau dokter" aria-label="Cari resep"
                        class="w-full h-11 bg-slate-950 border border-slate-800 rounded-lg pl-9 pr-3 text-sm text-slate-100 placeholder:text-slate-400 focus:outline-none focus:border-emerald-500">
                </div>
                <div class="rounded-xl border border-slate-800 divide-y divide-slate-800/60 max-h-[45vh] overflow-y-auto custom-scrollbar">
                    <template x-for="item in rx.results" :key="item.id">
                        <button type="button" @click="selectPrescription(item)" class="w-full px-3 py-2.5 min-h-[52px] text-left hover:bg-slate-800/60"
                            :class="cart.prescription?.id === item.id && 'bg-emerald-500/5'">
                            <span class="flex items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-slate-100" x-text="item.number"></span>
                                <span class="text-[11px] text-slate-400" x-text="item.date"></span>
                                <span class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded" :class="item.verified ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400'" x-text="item.verified ? 'TERVERIFIKASI' : 'MENUNGGU APOTEKER'"></span>
                            </span>
                            <span class="block text-sm text-slate-200 mt-0.5" x-text="`${item.patient} · dr. ${item.doctor}`"></span>
                            <div class="flex flex-wrap gap-1 mt-1.5">
                                <template x-for="(drug, dIdx) in (item.items || []).slice(0, 4)" :key="dIdx">
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-slate-800 text-[10px] text-slate-300 font-medium">
                                        <span x-text="drug.name"></span>
                                        <span class="text-emerald-400 font-bold" x-text="`sisa ${quantity(drug.remaining)}`"></span>
                                    </span>
                                </template>
                                <span x-show="(item.items || []).length > 4" class="text-[10px] text-slate-500 self-center" x-text="`+${item.items.length - 4} lainnya`"></span>
                            </div>
                        </button>
                    </template>
                    <p x-show="!rx.loading && !rx.results.length" class="px-3 py-4 text-xs text-slate-400">Belum ada resep yang bisa ditebus.</p>
                    <p x-show="rx.loading" class="px-3 py-4 text-xs text-slate-400">Mencari…</p>
                </div>
                <a x-show="config.prescription?.createUrl" :href="config.prescription?.createUrl" target="_blank" rel="noopener"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-400 hover:underline py-1.5">
                    <i data-lucide="file-plus-2" class="w-4 h-4"></i> Catat resep baru di tab lain
                </a>
            </div>
        </template>

        <template x-if="rx.tab === 'draft'">
            <form @submit.prevent="saveDraftPrescription()" class="space-y-3">
                <p x-show="!canDraftPrescription" class="text-xs text-amber-300">Mode resep ketat: minta apoteker mencatat dan memverifikasi resep dulu.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="rx-doctor" value="Nama dokter *" />
                        <x-text-input id="rx-doctor" x-model="rx.draft.doctor_name" maxlength="100" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="rx-sip" value="No. SIP dokter" />
                        <x-text-input id="rx-sip" x-model="rx.draft.doctor_sip" maxlength="50" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="rx-patient" value="Nama pasien *" />
                        <x-text-input id="rx-patient" x-model="rx.draft.patient_name" maxlength="100" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="rx-age" value="Umur pasien" />
                        <x-text-input id="rx-age" x-model="rx.draft.patient_age" inputmode="numeric" class="w-full" />
                    </div>
                </div>
                <div>
                    <x-input-label for="rx-clinic" value="Klinik / rumah sakit" />
                    <x-text-input id="rx-clinic" x-model="rx.draft.clinic_name" maxlength="150" class="w-full" />
                </div>
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button x-bind:disabled="!canDraftPrescription">Pakai Resep Ini</x-primary-button>
                </x-modal-actions>
            </form>
        </template>

        <p x-show="rx.error" x-text="rx.error" class="text-xs text-rose-400"></p>
    </div>
</x-modal>
