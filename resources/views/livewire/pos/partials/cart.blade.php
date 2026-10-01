<aside x-cloak aria-label="Keranjang"
    :class="cartOpen ? '' : 'max-md:translate-y-full max-md:invisible'"
    @keydown.escape.window="cartOpen = false"
    class="fixed inset-0 z-40 flex flex-col bg-slate-950 transition-[transform,visibility] duration-200 md:static md:z-auto md:w-[21rem] lg:w-[24rem] xl:w-[26rem] md:shrink-0 md:border-l md:border-slate-800 md:bg-slate-900/60 pt-[env(safe-area-inset-top)] md:pt-0">

    <div class="shrink-0 flex items-center gap-2 px-3 sm:px-4 h-14 border-b border-slate-800/80">
        <button type="button" @click="cartOpen = false" aria-label="Tutup keranjang"
            class="md:hidden inline-flex items-center justify-center w-11 h-11 -ms-2 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800">
            <i data-lucide="chevron-down" class="w-5 h-5"></i>
        </button>

        <button type="button" @click="openCustomers()"
            class="flex-1 min-w-0 flex items-center gap-2.5 h-11 px-2.5 rounded-lg border border-slate-800 bg-slate-900 hover:border-slate-700 text-left">
            <i data-lucide="user-round" class="w-4 h-4 text-slate-400 shrink-0"></i>
            <span class="min-w-0 flex-1">
                <span class="block text-xs font-semibold text-slate-100 truncate" x-text="cart.customer ? cart.customer.name : 'Pelanggan umum'"></span>
                <span x-show="cart.customer?.due > 0" class="block text-[10px] text-amber-400 truncate" x-text="`Kasbon belum lunas ${rupiah(cart.customer?.due)}`"></span>
            </span>
            <i data-lucide="chevrons-up-down" class="w-3.5 h-3.5 text-slate-500 shrink-0"></i>
        </button>

        <x-icon-button icon="trash-2" label="Kosongkan keranjang" tone="danger" x-show="cart.items.length" @click="$dispatch('open-modal', 'pos-clear')" />
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain custom-scrollbar">
        <template x-if="!cart.items.length">
            <div class="h-full flex flex-col items-center justify-center text-center p-8 gap-3">
                <span class="w-12 h-12 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center">
                    <i data-lucide="shopping-basket" class="w-6 h-6"></i>
                </span>
                <p class="text-sm font-bold text-slate-200">Keranjang kosong</p>
                <p class="text-xs text-slate-400 max-w-[16rem]">Ketuk produk di katalog, atau scan barcode langsung tanpa perlu klik kolom pencarian.</p>
            </div>
        </template>

        <ul class="divide-y divide-slate-800/60">
            <template x-for="line in cart.items" :key="line.key">
                <li class="px-3 sm:px-4 py-2.5 flex items-start gap-2">
                    <button type="button" @click="editLine(line)" class="flex-1 min-w-0 text-left rounded-lg -m-1 p-1 hover:bg-slate-800/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/50">
                        <span class="block text-[13px] font-semibold text-slate-100 leading-snug line-clamp-2" x-text="line.name"></span>
                        <span class="block text-[11px] text-slate-400 tabular-nums mt-0.5" x-text="`${rupiah(line.price)} × ${quantity(line.quantity)} ${line.unit}`"></span>
                        <span x-show="line.discount > 0" class="block text-[11px] text-emerald-400 tabular-nums" x-text="`Diskon -${rupiah(lineDiscount(line))}`"></span>
                        <span x-show="line.note" class="block text-[11px] text-slate-400 italic truncate" x-text="line.note"></span>
                        <span x-show="line.track && !config.allowNegative && line.stock !== null && line.quantity > line.stock" class="block text-[11px] text-rose-400" x-text="`Stok tinggal ${quantity(Math.max(0, line.stock))}`"></span>
                    </button>

                    <div class="flex flex-col items-end gap-1.5 shrink-0">
                        <span class="text-[13px] font-bold text-slate-100 tabular-nums" x-text="rupiah(lineTotal(line))"></span>
                        <div class="flex items-center rounded-lg border border-slate-800 bg-slate-900">
                            <button type="button" @click="decrement(line)" :aria-label="`Kurangi ${line.name}`"
                                class="w-11 h-10 sm:w-9 sm:h-9 inline-flex items-center justify-center text-slate-300 hover:text-slate-100 hover:bg-slate-800 rounded-l-lg">
                                <i :data-lucide="line.quantity <= 1 ? 'trash-2' : 'minus'" class="w-4 h-4"></i>
                            </button>
                            <button type="button" @click="editLine(line)" class="min-w-[2.5rem] px-1 h-10 sm:h-9 text-sm font-bold text-slate-100 tabular-nums" x-text="quantity(line.quantity)" :aria-label="`Ubah jumlah ${line.name}`"></button>
                            <button type="button" @click="increment(line)" :aria-label="`Tambah ${line.name}`"
                                class="w-11 h-10 sm:w-9 sm:h-9 inline-flex items-center justify-center text-slate-300 hover:text-slate-100 hover:bg-slate-800 rounded-r-lg">
                                <i data-lucide="plus" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                </li>
            </template>
        </ul>
    </div>

    {{-- Panel fokal: ringkasan & tombol bayar --}}
    <div class="shrink-0 border-t border-slate-800 bg-slate-900 shadow-[0_-12px_32px_-12px_rgba(2,6,23,0.6)] px-3 sm:px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] md:pb-3 space-y-2.5">
        <dl class="space-y-1 text-xs">
            <div class="flex justify-between text-slate-400">
                <dt>Subtotal <span x-show="cart.items.length" x-text="`(${quantity(itemCount)} barang)`"></span></dt>
                <dd class="tabular-nums text-slate-300" x-text="rupiah(subtotal)"></dd>
            </div>
            <div class="flex justify-between items-center text-slate-400">
                <dt>
                    <button type="button" @click="openDiscount()" x-show="config.canDiscount" class="inline-flex items-center gap-1 min-h-[32px] text-emerald-400 hover:text-emerald-300 font-semibold">
                        <i data-lucide="badge-percent" class="w-3.5 h-3.5"></i>
                        <span x-text="discountAmount > 0 ? (cart.discountType === 'percent' ? `Diskon ${quantity(cart.discountValue)}%` : 'Diskon') : 'Tambah diskon'"></span>
                    </button>
                    <span x-show="!config.canDiscount && discountAmount > 0">Diskon</span>
                </dt>
                <dd x-show="discountAmount > 0" class="tabular-nums text-emerald-400" x-text="`-${rupiah(discountAmount)}`"></dd>
            </div>
            <div x-show="config.taxRate > 0" class="flex justify-between text-slate-400">
                <dt x-text="`${config.taxLabel} ${quantity(config.taxRate)}%`"></dt>
                <dd class="tabular-nums text-slate-300" x-text="rupiah(taxAmount)"></dd>
            </div>
        </dl>

        <div class="flex items-baseline justify-between gap-3 pt-1">
            <span class="text-sm font-semibold text-slate-300">Total</span>
            <span class="text-2xl sm:text-[1.75rem] font-extrabold text-slate-50 tabular-nums tracking-tight" x-text="rupiah(total)"></span>
        </div>

        <div class="grid grid-cols-[auto_1fr] gap-2">
            <x-secondary-button @click="openHold()" ::disabled="!cart.items.length" class="!min-h-[3.5rem] px-3.5" title="Tunda transaksi">
                <i data-lucide="pause" class="w-4 h-4"></i>
                <span>Tunda</span>
            </x-secondary-button>
            <x-primary-button type="button" @click="openPay()" ::disabled="!cart.items.length" class="!min-h-[3.5rem] !text-base">
                <i data-lucide="banknote" class="w-5 h-5"></i>
                <span>Bayar</span>
                <span class="hidden lg:inline text-[10px] font-mono font-semibold opacity-70">F9</span>
            </x-primary-button>
        </div>
    </div>
</aside>
