<aside x-cloak aria-label="Keranjang"
    :class="cartOpen ? '' : 'max-md:translate-y-full max-md:invisible'"
    @keydown.escape.window="cartOpen = false"
    class="fixed inset-0 z-40 flex flex-col bg-slate-950 transition-[transform,visibility] duration-200 md:static md:z-auto md:w-[21rem] lg:w-[24rem] xl:w-[26rem] md:shrink-0 md:border-l md:border-slate-800 md:bg-slate-900/60 pt-[env(safe-area-inset-top)] md:pt-0">

    <div class="shrink-0 flex items-center gap-2 px-3 sm:px-4 h-14 border-b border-slate-800/80 bg-slate-900/40">
        <button type="button" @click="cartOpen = false" aria-label="Tutup keranjang"
            class="md:hidden inline-flex items-center justify-center w-10 h-10 -ms-1 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800">
            <i data-lucide="chevron-down" class="w-5 h-5"></i>
        </button>

        <button type="button" @click="openCustomers()"
            class="flex-1 min-w-0 flex items-center gap-2.5 h-10 sm:h-11 px-3 rounded-xl border border-slate-800 bg-slate-900 hover:border-slate-700 text-left transition shadow-inner cursor-pointer"
            :class="cart.customer ? 'border-emerald-500/40 bg-emerald-950/15' : ''">
            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                :class="cart.customer ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-800 text-slate-400'">
                <i data-lucide="user-round" class="w-3.5 h-3.5"></i>
            </div>
            <span class="min-w-0 flex-1">
                <span class="block text-xs font-bold text-slate-100 truncate" x-text="cart.customer ? cart.customer.name : 'Pelanggan Umum'"></span>
                <span x-show="cart.customer?.due > 0" class="block text-[10px] text-amber-400 font-semibold truncate" x-text="`Kasbon ${rupiah(cart.customer?.due)}`"></span>
            </span>
            <i data-lucide="chevrons-up-down" class="w-3.5 h-3.5 text-slate-500 shrink-0"></i>
        </button>

        <x-icon-button icon="trash-2" label="Kosongkan keranjang" tone="danger" x-show="cart.items.length" @click="$dispatch('open-modal', 'pos-clear')" />
    </div>

    <div x-show="cart.customerOrder" x-cloak class="shrink-0 flex items-center gap-2.5 px-3 sm:px-4 py-2 border-b border-slate-800/80 bg-sky-500/5">
        <i data-lucide="clipboard-list" class="w-4 h-4 shrink-0 text-sky-400"></i>
        <p class="min-w-0 flex-1 text-[11px] text-slate-200">Pelunasan <span class="font-mono font-semibold" x-text="cart.customerOrder?.number"></span> · DP <span class="tabular-nums" x-text="rupiah(cart.customerOrder?.deposit || 0)"></span></p>
        <x-icon-button icon="x" label="Lepas pesanan" @click="clearCustomerOrder()" />
    </div>

    <div x-show="config.orderType.enabled" x-cloak class="shrink-0 flex items-center gap-2 px-3 sm:px-4 py-2 border-b border-slate-800/80">
        <x-segmented class="flex-1 [&>*]:flex-1" aria-label="Tipe pesanan">
            <template x-for="option in config.orderType.options" :key="option.value">
                <x-tab-button x-bind:aria-pressed="cart.orderType === option.value" @click="cart.orderType = option.value"
                    x-bind:class="cart.orderType === option.value ? '!bg-emerald-500/10 !text-emerald-600 dark:!text-emerald-400 !border-emerald-500/30' : ''">
                    <span x-text="option.label"></span>
                </x-tab-button>
            </template>
        </x-segmented>
        <label x-show="cart.orderType === 'dine_in'" class="shrink-0 w-24">
            <span class="sr-only">Nomor meja</span>
            <x-text-input x-model="cart.table" maxlength="30" class="w-full !h-10 text-center text-sm font-semibold" placeholder="Meja" />
        </label>
    </div>

    <div x-show="needsPrescription || cart.prescription || cart.prescriptionDraft" x-cloak
        class="shrink-0 flex items-center gap-2.5 px-3 sm:px-4 py-2 border-b border-slate-800/80"
        :class="cart.prescription || cart.prescriptionDraft ? 'bg-emerald-500/5' : 'bg-rose-500/10'">
        <i data-lucide="file-heart" class="w-4 h-4 shrink-0" :class="cart.prescription || cart.prescriptionDraft ? 'text-emerald-400' : 'text-rose-400'"></i>
        <div class="min-w-0 flex-1 text-[11px] leading-snug">
            <template x-if="cart.prescription">
                <span class="text-slate-200"><span class="font-mono font-semibold" x-text="cart.prescription.number"></span> · <span x-text="cart.prescription.patient"></span></span>
            </template>
            <template x-if="!cart.prescription && cart.prescriptionDraft">
                <span class="text-slate-200" x-text="`Resep dr. ${cart.prescriptionDraft.doctor_name} · ${cart.prescriptionDraft.patient_name}`"></span>
            </template>
            <template x-if="!cart.prescription && !cart.prescriptionDraft">
                <span class="font-semibold text-rose-300">Ada obat wajib resep di keranjang</span>
            </template>
        </div>
        <x-text-button size="sm" tone="emerald" @click="openPrescription()" x-text="cart.prescription || cart.prescriptionDraft ? 'Ganti' : 'Tautkan resep'"></x-text-button>
        <x-icon-button icon="x" label="Lepas resep" x-show="cart.prescription || cart.prescriptionDraft" @click="clearPrescription()" />
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain custom-scrollbar">
        <template x-if="!cart.items.length">
            <div class="h-full flex flex-col items-center justify-center text-center p-8 gap-3 select-none">
                <div class="w-14 h-14 rounded-2xl bg-slate-900 border border-slate-800 text-slate-500 flex items-center justify-center shadow-inner">
                    <i data-lucide="shopping-basket" class="w-7 h-7"></i>
                </div>
                <div>
                    <p class="text-sm font-bold text-slate-200">Keranjang Kasir Kosong</p>
                    <p class="text-xs text-slate-400 max-w-[16rem] mt-1 leading-relaxed">Pilih produk di katalog atau scan barcode langsung untuk mulai transaksi.</p>
                </div>
            </div>
        </template>

        <ul class="divide-y divide-slate-800/60">
            <template x-for="line in cart.items" :key="line.key">
                <li class="px-3 sm:px-4 py-2.5 flex items-start gap-2.5 hover:bg-slate-800/50 transition-colors">
                    <button type="button" @click="editLine(line)" class="flex-1 min-w-0 text-left rounded-lg -m-1 p-1 hover:bg-slate-800/40 focus:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500/50">
                        <span class="block text-[13px] font-bold text-slate-100 leading-snug line-clamp-2">
                            <span x-show="line.rx" class="inline-flex align-middle px-1 rounded bg-rose-500/15 text-rose-400 text-[9px] font-extrabold mr-0.5" title="Wajib resep">R</span>
                            <span x-text="line.name"></span>
                        </span>
                        <span x-show="line.serials?.length" class="block text-[11px] text-slate-300 font-mono leading-snug truncate" x-text="`SN: ${(line.serials || []).join(', ')}`"></span>
                        <span x-show="line.serial && (line.serials || []).length !== Number(line.quantity)" class="block text-[11px] font-semibold text-amber-400">Pilih nomor seri</span>
                        <span x-show="line.modifiers?.length" class="block text-[11px] text-sky-300/90 leading-snug truncate" x-text="modifierNames(line)"></span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="text-[11px] text-slate-400 tabular-nums" x-text="`${rupiah(linePrice(line) + (line.modifiers || []).reduce((s, m) => s + m.price, 0))} × ${quantity(line.quantity)} ${line.unit}`"></span>
                            <span x-show="lineTiered(line)" class="px-1.5 rounded bg-amber-500/10 text-amber-400 text-[10px] font-bold">GROSIR</span>
                            <span x-show="autoDiscounts[line.key] > 0" class="px-1.5 rounded bg-amber-500/10 text-amber-400 text-[10px] font-bold" x-text="`ED -${rupiah(autoDiscounts[line.key])}`"></span>
                            <span x-show="line.discount > 0" class="px-1.5 py-0.2 rounded bg-emerald-500/10 text-emerald-400 text-[10px] font-bold tabular-nums" x-text="`-${rupiah(Math.min(line.discount, lineGross(line)))}`"></span>
                        </div>
                        <span x-show="line.note" class="block text-[11px] text-slate-400 italic truncate mt-0.5" x-text="line.note"></span>
                        <span x-show="line.track && !config.allowNegative && line.stock !== null && line.quantity > line.stock" class="block text-[11px] font-semibold text-rose-400 mt-0.5" x-text="`Stok tinggal ${quantity(Math.max(0, line.stock))}`"></span>
                    </button>

                    <div class="flex flex-col items-end gap-1.5 shrink-0">
                        <span class="text-[13px] font-extrabold text-slate-100 tabular-nums" x-text="rupiah(lineTotal(line))"></span>
                        <div class="flex items-center rounded-lg border border-slate-700/80 bg-slate-950 shadow-inner">
                            <button type="button" @click="decrement(line)" :aria-label="`Kurangi ${line.name}`"
                                class="w-8 h-8 inline-flex items-center justify-center text-slate-400 hover:text-slate-100 hover:bg-slate-800/80 rounded-l-lg transition active:scale-95 cursor-pointer">
                                <i :data-lucide="line.quantity <= 1 ? 'trash-2' : 'minus'" class="w-3.5 h-3.5" :class="line.quantity <= 1 ? 'text-rose-400' : ''"></i>
                            </button>
                            <button type="button" @click="editLine(line)" class="min-w-[2.25rem] px-1 h-8 text-xs font-bold text-slate-100 tabular-nums hover:bg-slate-800/60 transition cursor-pointer" x-text="quantity(line.quantity)" :aria-label="`Ubah jumlah ${line.name}`"></button>
                            <button type="button" @click="increment(line)" :aria-label="`Tambah ${line.name}`"
                                class="w-8 h-8 inline-flex items-center justify-center text-slate-400 hover:text-emerald-400 hover:bg-slate-800/80 rounded-r-lg transition active:scale-95 cursor-pointer">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>
                </li>
            </template>
        </ul>
    </div>

    {{-- Panel ringkasan kasir & tombol bayar --}}
    <div class="shrink-0 border-t border-slate-800 bg-slate-900 px-3.5 sm:px-4 pt-3.5 pb-[calc(0.75rem+env(safe-area-inset-bottom))] md:pb-3.5 space-y-3">
        <dl class="space-y-1.5 text-xs">
            <div class="flex justify-between text-slate-400">
                <dt>Subtotal <span x-show="cart.items.length" x-text="`(${quantity(itemCount)} barang)`" class="text-slate-500"></span></dt>
                <dd class="tabular-nums font-semibold text-slate-200" x-text="rupiah(subtotal)"></dd>
            </div>
            <div class="flex justify-between items-center text-slate-400">
                <dt>
                    <button type="button" @click="openDiscount()" x-show="config.canDiscount" class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400 hover:underline font-semibold transition cursor-pointer">
                        <i data-lucide="badge-percent" class="w-3.5 h-3.5"></i>
                        <span x-text="discountAmount > 0 ? (cart.discountType === 'percent' ? `Diskon ${quantity(cart.discountValue)}%` : 'Diskon') : 'Beri Diskon'"></span>
                    </button>
                    <span x-show="!config.canDiscount && discountAmount > 0">Diskon</span>
                </dt>
                <dd x-show="discountAmount > 0" class="tabular-nums font-bold text-emerald-600 dark:text-emerald-400" x-text="`-${rupiah(discountAmount)}`"></dd>
            </div>
            <div x-show="serviceAmount > 0" class="flex justify-between text-slate-400">
                <dt x-text="`Service ${quantity(serviceRate)}%`"></dt>
                <dd class="tabular-nums font-semibold text-slate-200" x-text="rupiah(serviceAmount)"></dd>
            </div>
            <div x-show="config.taxRate > 0" class="flex justify-between text-slate-400">
                <dt x-text="`${config.taxLabel} ${quantity(config.taxRate)}%`"></dt>
                <dd class="tabular-nums font-semibold text-slate-200" x-text="rupiah(taxAmount)"></dd>
            </div>
        </dl>

        <div class="pt-2 border-t border-slate-800 flex items-baseline justify-between gap-3">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400" x-text="cart.customerOrder ? 'Sisa bayar' : 'Total'"></span>
            <span class="text-2xl sm:text-3xl font-extrabold text-slate-50 tabular-nums tracking-tight" x-text="rupiah(amountDue)"></span>
        </div>
        <p x-show="cart.customerOrder" x-cloak class="-mt-2 text-[11px] text-slate-400 text-right tabular-nums" x-text="`Total ${rupiah(total)} − DP ${rupiah(cart.customerOrder?.deposit || 0)}`"></p>

        <div class="grid grid-cols-[auto_1fr] gap-2 pt-0.5">
            <x-secondary-button size="sm" @click="openHold()" ::disabled="!cart.items.length" class="px-3" title="Tunda transaksi">
                <i data-lucide="pause" class="w-4 h-4"></i>
                <span>Tunda</span>
            </x-secondary-button>
            <x-primary-button size="sm" type="button" @click="openPay()" ::disabled="!cart.items.length" class="font-bold flex items-center justify-center gap-2">
                <i data-lucide="banknote" class="w-4 h-4"></i>
                <span>Bayar</span>
                <span class="hidden lg:inline text-[10px] font-mono opacity-75 ml-0.5">F9</span>
            </x-primary-button>
        </div>
    </div>
</aside>
