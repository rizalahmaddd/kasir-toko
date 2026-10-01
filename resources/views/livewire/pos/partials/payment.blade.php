<x-modal name="pos-payment" max-width="4xl">
    {{-- Input pembayaran --}}
    <template x-if="pay && !pay.result">
        <form @submit.prevent="submitPayment()" class="flex flex-col min-h-0 overflow-y-auto custom-scrollbar">
            <div class="flex items-center justify-between gap-3 px-4 sm:px-6 py-3 border-b border-slate-800/80">
                <h3 class="font-bold text-base text-slate-100">Pembayaran</h3>
                <x-icon-button icon="x" label="Tutup" x-on:click="$dispatch('close')" x-bind:disabled="pay.submitting" class="-me-2" />
            </div>

            <div class="grid md:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
                {{-- Ringkasan: titik fokus layar ini --}}
                <div class="p-4 sm:p-6 space-y-4 md:border-r border-slate-800/80 bg-slate-950/40">
                    <div class="rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 border border-slate-700/60 p-4 sm:p-5 shadow-lg shadow-slate-950/40">
                        <p class="text-xs font-medium text-slate-400">Total tagihan</p>
                        <p class="text-3xl sm:text-4xl font-extrabold text-slate-50 tabular-nums tracking-tight mt-1" x-text="rupiah(total)"></p>
                        <p class="text-[11px] text-slate-400 mt-1" x-text="`${quantity(itemCount)} barang${cart.customer ? ' · ' + cart.customer.name : ''}`"></p>
                    </div>

                    <ul x-show="pay.lines.length" class="space-y-1.5">
                        <template x-for="(line, index) in pay.lines" :key="index">
                            <li class="flex items-center gap-2 rounded-lg border border-slate-800 bg-slate-900 pl-3 pr-1 py-1">
                                <span class="text-xs text-slate-300 flex-1" x-text="methodLabel(line.method) + (line.reference ? ` · ${line.reference}` : '')"></span>
                                <span class="text-sm font-semibold text-slate-100 tabular-nums" x-text="rupiah(line.amount)"></span>
                                <x-icon-button icon="x" label="Hapus pembayaran ini" tone="danger" @click="removeSplit(index)" />
                            </li>
                        </template>
                    </ul>

                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between text-slate-400">
                            <dt>Dibayar</dt>
                            <dd class="tabular-nums text-slate-200 font-semibold" x-text="rupiah(payTotal)"></dd>
                        </div>
                        <div x-show="payShortfall > 0" class="flex justify-between">
                            <dt class="text-amber-400" x-text="pay.credit ? 'Dicatat kasbon' : 'Kurang'"></dt>
                            <dd class="tabular-nums font-bold text-amber-400" x-text="rupiah(payShortfall)"></dd>
                        </div>
                        <div class="flex justify-between items-baseline pt-2 border-t border-slate-800">
                            <dt class="text-slate-300 font-semibold">Kembalian</dt>
                            <dd class="tabular-nums text-2xl sm:text-3xl font-extrabold" :class="payChange > 0 ? 'text-emerald-400' : 'text-slate-500'" x-text="rupiah(payChange)"></dd>
                        </div>
                    </dl>

                    <div x-show="config.allowCredit && payShortfall > 0" class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 space-y-2">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" class="mt-0.5 rounded border-slate-700 bg-slate-950 text-emerald-600 focus:ring-emerald-500 w-5 h-5" :checked="pay.credit" @change="toggleCredit()">
                            <span>
                                <span class="block text-xs font-semibold text-amber-300">Catat sisa sebagai kasbon</span>
                                <span class="block text-[11px] text-slate-400" x-text="cart.customer ? `Atas nama ${cart.customer.name}. Pelunasan dicatat di menu Piutang.` : 'Pilih pelanggan dulu.'"></span>
                            </span>
                        </label>
                        <x-text-button tone="amber" size="sm" x-show="pay.credit" @click="openCustomers()" x-text="cart.customer ? 'Ganti pelanggan' : 'Pilih pelanggan'"></x-text-button>
                    </div>
                </div>

                {{-- Input --}}
                <div class="p-4 sm:p-6 space-y-4">
                    <div class="grid gap-1.5" :class="config.methods.length > 2 ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-2'" role="group" aria-label="Metode pembayaran">
                        <template x-for="method in config.methods" :key="method.value">
                            <button type="button" @click="selectMethod(method.value)" :aria-pressed="pay.method === method.value"
                                class="flex flex-col items-center justify-center gap-1 min-h-[56px] rounded-xl border text-xs font-semibold transition"
                                :class="pay.method === method.value ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-400' : 'border-slate-800 bg-slate-900 text-slate-300 hover:border-slate-700'">
                                <i :data-lucide="method.icon" class="w-5 h-5"></i>
                                <span x-text="method.label"></span>
                            </button>
                        </template>
                    </div>

                    <div>
                        <label for="pos-pay-amount" class="block font-medium text-xs text-slate-300 mb-1.5"
                            x-text="pay.method === 'cash' ? 'Uang diterima' : `Nominal ${methodLabel(pay.method)}`"></label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-lg text-slate-400 pointer-events-none">Rp</span>
                            <input id="pos-pay-amount" type="text" autocomplete="off"
                                :inputmode="window.matchMedia('(pointer: coarse)').matches ? 'none' : 'numeric'"
                                :value="pay.amount === '' ? '' : quantity(pay.amount)"
                                @input="pay.amount = $event.target.value.replace(/\D/g, '').slice(0, 12); $event.target.value = pay.amount === '' ? '' : quantity(pay.amount)"
                                x-init="$nextTick(() => window.matchMedia('(pointer: coarse)').matches || $el.focus())"
                                placeholder="0"
                                class="w-full h-16 bg-slate-950 border border-slate-800 rounded-xl pl-12 pr-4 text-right text-3xl font-extrabold text-slate-50 tabular-nums placeholder:text-slate-600 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
                        </div>
                    </div>

                    <div x-show="pay.method === 'qris'" class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                        <template x-if="config.qris">
                            <div class="flex items-center gap-3">
                                <div class="w-28 h-28 shrink-0 rounded-lg bg-white p-1.5 flex items-center justify-center">
                                    <div x-show="qrisView.svg && !qrisView.loading && payCurrent > 0" x-html="qrisView.svg" class="w-full h-full [&>svg]:w-full [&>svg]:h-full"></div>
                                    <x-spinner x-show="qrisView.loading" class="w-5 h-5 text-slate-500" />
                                    <span x-show="payCurrent <= 0" class="text-[10px] text-slate-500 text-center leading-tight">Isi nominal dulu</span>
                                </div>
                                <div class="min-w-0 text-xs space-y-1">
                                    <p class="font-semibold text-slate-100">QRIS <span class="tabular-nums" x-text="rupiah(payCurrent)"></span></p>
                                    <p class="text-slate-400" x-text="qrisView.merchant ? `a.n. ${qrisView.merchant}` : ''"></p>
                                    <p class="text-slate-400" x-show="config.display.enabled">QR yang sama tampil di layar pelanggan. Tekan tombol di bawah setelah notifikasi uang masuk muncul di aplikasi merchant.</p>
                                    <p class="text-slate-400" x-show="!config.display.enabled">Tunjukkan QR ini ke pembeli. Tekan tombol di bawah setelah notifikasi uang masuk muncul.</p>
                                </div>
                            </div>
                        </template>
                        <template x-if="!config.qris">
                            <p class="text-[11px] text-amber-400 flex items-start gap-2">
                                <i data-lucide="info" class="w-3.5 h-3.5 shrink-0 mt-px"></i>
                                <span>QRIS bernominal belum diatur, jadi pembeli memindai QRIS fisik dan mengetik nominalnya sendiri. Unggah QRIS toko di Pengaturan Kasir.</span>
                            </p>
                        </template>
                    </div>

                    <div x-show="pay.method !== 'cash'">
                        <x-input-label for="pos-pay-ref" value="No. referensi (opsional)" />
                        <x-text-input id="pos-pay-ref" x-model="pay.reference" maxlength="100" class="w-full" placeholder="Mis. 4 digit akhir kartu atau ID transaksi" />
                    </div>

                    <div x-show="pay.method === 'cash' && cashSuggestions.length" class="grid grid-cols-3 gap-1.5">
                        <template x-for="(value, index) in cashSuggestions" :key="value">
                            <button type="button" @click="setAmount(value)"
                                class="min-h-[44px] rounded-lg border text-xs font-bold tabular-nums transition"
                                :class="payCurrent === value ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-400' : 'border-slate-800 bg-slate-900 text-slate-200 hover:border-slate-700'"
                                x-text="index === 0 ? 'Uang pas' : rupiah(value)"></button>
                        </template>
                    </div>

                    <div class="grid grid-cols-3 gap-1.5 [@media(pointer:fine)]:hidden" aria-label="Papan angka">
                        <template x-for="key in ['1','2','3','4','5','6','7','8','9','000','0','back']" :key="key">
                            <button type="button" @click="keypad(key)"
                                class="h-14 rounded-xl bg-slate-800/70 border border-slate-700/60 text-xl font-bold text-slate-100 active:bg-slate-700 flex items-center justify-center"
                                :aria-label="key === 'back' ? 'Hapus satu angka' : key">
                                <span x-show="key !== 'back'" x-text="key"></span>
                                <i x-show="key === 'back'" data-lucide="delete" class="w-6 h-6"></i>
                            </button>
                        </template>
                    </div>

                    <x-text-button size="sm" tone="emerald" @click="addSplit()" x-show="payCurrent > 0 && payCurrent < payRemaining">
                        <i data-lucide="split" class="w-3.5 h-3.5"></i>
                        <span>Bayar sisanya dengan metode lain</span>
                    </x-text-button>
                </div>
            </div>

            <div class="sticky bottom-0 px-4 sm:px-6 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] border-t border-slate-800/80 bg-slate-900 space-y-2.5">
                <div x-show="pay.error" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300 flex items-start gap-2">
                    <i data-lucide="circle-alert" class="w-4 h-4 shrink-0 mt-px"></i>
                    <span class="flex-1" x-text="pay.error"></span>
                    <x-text-button tone="rose" x-show="pay.errorAction === 'reload'" @click="window.location.reload()">Muat ulang</x-text-button>
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:items-center gap-2.5">
                    <p class="hidden sm:block text-[11px] text-slate-400 mr-auto" x-show="!payProblem">Tekan Enter untuk menyelesaikan.</p>
                    <p class="text-xs font-semibold text-amber-400 sm:mr-auto text-center" x-show="payProblem" x-text="payProblem"></p>
                    <x-secondary-button x-on:click="$dispatch('close')" x-bind:disabled="pay.submitting">Kembali</x-secondary-button>
                    <x-primary-button class="!min-h-[3.25rem] sm:min-w-[14rem] !text-sm" x-bind:disabled="pay.submitting || !!payProblem">
                        <template x-if="pay.submitting"><x-spinner /></template>
                        <span x-text="pay.submitting ? 'Memproses…' : (payShortfall > 0 ? 'Simpan dengan Kasbon' : (pay.method === 'qris' && payCurrent > 0 ? 'Pembayaran QRIS Diterima' : 'Selesaikan Pembayaran'))"></span>
                    </x-primary-button>
                </div>
            </div>
        </form>
    </template>

    {{-- Hasil --}}
    <template x-if="pay && pay.result">
        <div class="p-6 sm:p-8 text-center space-y-5 overflow-y-auto custom-scrollbar">
            <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400">
                <i data-lucide="check" class="w-7 h-7"></i>
            </span>
            <div>
                <p class="text-sm font-semibold text-slate-300">Transaksi tersimpan</p>
                <p class="text-xs text-slate-400 font-mono mt-0.5" x-text="pay.result.number"></p>
            </div>

            <div class="max-w-sm mx-auto rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 border border-slate-700/60 p-5 shadow-lg shadow-slate-950/40">
                <p class="text-xs text-slate-400" x-text="pay.result.due > 0 ? 'Sisa kasbon' : 'Kembalian'"></p>
                <p class="text-4xl sm:text-5xl font-extrabold tabular-nums tracking-tight mt-1"
                    :class="pay.result.due > 0 ? 'text-amber-400' : 'text-emerald-400'"
                    x-text="rupiah(pay.result.due > 0 ? pay.result.due : pay.result.change)"></p>
                <p class="text-[11px] text-slate-400 mt-2" x-text="`Total ${rupiah(pay.result.total)} · diterima ${rupiah(pay.result.paid + pay.result.change)}`"></p>
            </div>

            <div class="grid sm:grid-cols-3 gap-2 max-w-xl mx-auto">
                <x-secondary-button @click="printReceipt()">
                    <i data-lucide="printer" class="w-4 h-4"></i>
                    <span>Cetak Struk</span>
                </x-secondary-button>
                <a :href="pay.result.whatsapp_url" target="_blank" rel="noopener"
                    class="inline-flex items-center justify-center gap-2 min-h-[44px] px-4 py-2 text-xs font-semibold rounded-lg bg-slate-800 border border-slate-700 text-slate-300 hover:bg-slate-700">
                    <i data-lucide="message-circle" class="w-4 h-4"></i>
                    <span>Kirim via WhatsApp</span>
                </a>
                <x-primary-button type="button" @click="newTransaction()" x-init="$nextTick(() => $el.focus())">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>Transaksi Baru</span>
                </x-primary-button>
            </div>
        </div>
    </template>
</x-modal>
