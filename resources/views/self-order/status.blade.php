<x-layouts.self-order :title="$storeName.' - Status Pesanan'" :logo="$logo">
    <div x-data="selfOrderStatus(@js($config))" x-cloak class="mx-auto max-w-md min-h-dvh flex flex-col justify-between px-4 pb-10 pt-[calc(1rem+env(safe-area-inset-top))] bg-slate-950 text-slate-100">
        <div class="space-y-3.5">
            {{-- Header Store Info & Order Code --}}
            <div class="flex items-center justify-between gap-3 px-1">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 truncate">{{ $storeName }}</span>
                </div>
                <span class="shrink-0 rounded-lg bg-slate-900 border border-slate-800 px-2.5 py-1 text-[11px] font-mono font-medium text-slate-400" x-text="state.number"></span>
            </div>

            {{-- Main Tracker Hero Card --}}
            <section class="rounded-3xl border border-slate-700/80 bg-slate-900 p-5 sm:p-6 shadow-xs relative overflow-hidden" aria-live="polite">
                {{-- Queue / Table & Status Badge --}}
                <div class="flex items-start justify-between gap-3 border-b border-slate-800/80 pb-3.5">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400" x-text="state.table ? 'Tempat Duduk' : 'Nomor Antrean'"></p>
                        <template x-if="state.table">
                            <p class="text-2xl sm:text-3xl font-extrabold text-slate-100 tracking-tight mt-0.5 leading-none" x-text="'Meja ' + state.table"></p>
                        </template>
                        <template x-if="!state.table">
                            <p class="text-2xl sm:text-3xl font-extrabold tabular-nums text-slate-100 tracking-tight mt-0.5 leading-none" x-text="'#' + state.queue_number"></p>
                        </template>
                    </div>

                    {{-- Status Badge (No wrapping, single line) --}}
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold whitespace-nowrap border"
                        :class="stopped 
                            ? 'bg-rose-500/10 text-rose-400 border-rose-500/30' 
                            : (state.status === 'ready' 
                                ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' 
                                : 'bg-amber-500/10 text-amber-400 border-amber-500/30')">
                        <span class="w-1.5 h-1.5 rounded-full" :class="stopped ? 'bg-rose-500' : (state.status === 'ready' ? 'bg-emerald-500 animate-ping' : 'bg-amber-500 animate-pulse')"></span>
                        <span x-text="state.status_label || (stopped ? 'Selesai' : 'Diproses')"></span>
                    </span>
                </div>

                {{-- Headline & Detail --}}
                <div class="mt-4">
                    <h1 class="text-lg sm:text-xl font-bold leading-snug"
                        :class="stopped ? 'text-rose-400' : (state.status === 'ready' ? 'text-emerald-400' : 'text-slate-100')"
                        x-text="headline"></h1>
                    <p class="mt-1.5 text-xs sm:text-sm leading-relaxed text-slate-300" x-text="detail"></p>
                </div>

                {{-- Connected Stepper Timeline --}}
                <div x-show="!stopped" class="mt-5 pt-4 border-t border-slate-800/80">
                    <div class="space-y-3">
                        <template x-for="(step, sIndex) in steps" :key="step.label">
                            <div class="relative flex items-center gap-3">
                                {{-- Connecting line between step nodes --}}
                                <template x-if="sIndex < steps.length - 1">
                                    <div class="absolute left-3.5 top-6 bottom-0 w-0.5 -mb-3 transition-colors"
                                        :class="step.done ? 'bg-emerald-600' : 'bg-slate-800'"></div>
                                </template>

                                {{-- Node Circle --}}
                                <div class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold transition-all"
                                    :class="step.done 
                                        ? 'bg-emerald-600 text-white shadow-2xs' 
                                        : (step.active 
                                            ? 'bg-emerald-500/20 text-emerald-400 border-2 border-emerald-500 ring-4 ring-emerald-500/10' 
                                            : 'bg-slate-800 text-slate-500 border border-slate-700/80')">
                                    <template x-if="step.done">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                    </template>
                                    <template x-if="!step.done">
                                        <span class="text-[11px]" x-text="sIndex + 1"></span>
                                    </template>
                                </div>

                                {{-- Step Label & Active Badge --}}
                                <div class="min-w-0 flex-1 flex items-center justify-between py-1">
                                    <span class="text-xs sm:text-sm transition-colors"
                                        :class="step.active ? 'text-slate-100 font-bold' : (step.done ? 'text-slate-300 font-medium' : 'text-slate-500 font-medium')"
                                        x-text="step.label"></span>
                                    <template x-if="step.active">
                                        <span class="rounded-full bg-emerald-500/15 px-2.5 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-500/30">Saat Ini</span>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </section>

            {{-- Connection Offline Banner --}}
            <p x-show="fetchFailed" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs font-medium text-amber-300">Koneksi terputus. Status otomatis diperbarui saat internet kembali terhubung.</p>

            {{-- QRIS Official Payment Card --}}
            <template x-if="state.show_qris">
                <section class="rounded-3xl border border-slate-700/80 bg-slate-900 p-5 text-center shadow-xs">
                    <div class="inline-flex items-center gap-2 rounded-full bg-slate-800 px-3 py-1 border border-slate-700/80 mb-2">
                        <span class="rounded bg-emerald-600 px-1.5 py-0.2 text-[10px] font-black uppercase tracking-wider text-white">QRIS</span>
                        <span class="text-xs font-semibold text-slate-300">Pembayaran Cepat</span>
                    </div>

                    <p class="text-xs text-slate-400">Total Pembayaran</p>
                    <p class="text-2xl sm:text-3xl font-extrabold tabular-nums text-slate-100 tracking-tight mt-0.5" x-text="rupiah(state.total)"></p>

                    {{-- QR Code Image Box --}}
                    <div class="relative mx-auto mt-3.5 inline-block rounded-2xl bg-white p-3 border border-slate-200 shadow-sm">
                        <img :src="config.qrisUrl" alt="QR pembayaran QRIS" class="h-48 w-48 sm:h-52 sm:w-52 max-w-full rounded-lg object-contain">
                    </div>

                    <template x-if="config.merchant">
                        <p class="mt-3 text-xs font-semibold text-slate-200" x-text="'Penerima: ' + config.merchant"></p>
                    </template>
                    <p class="mt-1 text-[11px] leading-relaxed text-slate-400 max-w-xs mx-auto">
                        Scan dengan GoPay, OVO, Dana, BCA, atau aplikasi m-banking apa pun. Nominal terisi otomatis.
                    </p>
                </section>
            </template>

            {{-- Sound Notification Request Card --}}
            <section x-show="!finished && !alertsOn" class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 shadow-2xs">
                <div class="flex items-start gap-3">
                    <div class="w-9 h-9 rounded-xl bg-emerald-600 text-white flex items-center justify-center shrink-0">
                        <svg class="w-4.5 h-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-slate-100">Nyalakan Notifikasi Suara?</p>
                        <p class="text-[11px] text-slate-400 mt-0.5 leading-snug">Dapatkan notifikasi audio saat pesanan Anda siap disajikan.</p>
                        <button type="button" @click="enableAlerts()"
                            class="mt-2.5 inline-flex h-8 items-center justify-center rounded-lg bg-emerald-600 px-3.5 text-xs font-semibold text-white shadow-2xs hover:bg-emerald-700 active:scale-95 transition">
                            Aktifkan Notifikasi Suara
                        </button>
                    </div>
                </div>
            </section>

            {{-- Digital Receipt Details Card --}}
            <section class="rounded-3xl border border-slate-700/80 bg-slate-900 p-5 shadow-xs">
                <div class="flex items-center justify-between border-b border-slate-800/80 pb-3">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-300">Rincian Menu</h2>
                    <span class="rounded-full bg-slate-800 px-2 py-0.5 text-[11px] font-semibold text-slate-400 tabular-nums" x-text="state.items?.length + ' Menu'"></span>
                </div>

                <ul class="divide-y divide-slate-800/60 text-xs sm:text-sm">
                    <template x-for="(item, index) in state.items" :key="index">
                        <li class="py-3 flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <span class="font-semibold text-slate-100 leading-snug" x-text="item.quantity + '× ' + item.name"></span>
                                <span x-show="item.modifiers?.length" class="block text-[11px] text-slate-400 mt-0.5" x-text="item.modifiers.join(', ')"></span>
                                <span x-show="item.note" class="block text-[11px] text-amber-400/90 italic mt-0.5" x-text="'Catatan: ' + item.note"></span>
                            </div>
                            <span class="shrink-0 tabular-nums font-semibold text-slate-200" x-text="rupiah(item.total)"></span>
                        </li>
                    </template>
                </ul>

                <dl class="space-y-2 border-t border-slate-800/80 pt-3 text-xs">
                    <div x-show="state.service_charge_amount > 0" class="flex justify-between text-slate-400">
                        <dt>Service charge</dt>
                        <dd class="tabular-nums font-medium text-slate-200" x-text="rupiah(state.service_charge_amount)"></dd>
                    </div>
                    <div x-show="state.tax_amount > 0" class="flex justify-between text-slate-400">
                        <dt>Pajak</dt>
                        <dd class="tabular-nums font-medium text-slate-200" x-text="rupiah(state.tax_amount)"></dd>
                    </div>
                    <div class="flex items-baseline justify-between border-t border-slate-800 pt-2 text-sm">
                        <dt class="font-bold text-slate-100">Total</dt>
                        <dd class="tabular-nums font-extrabold text-emerald-400" x-text="rupiah(state.total)"></dd>
                    </div>
                    <div class="flex items-center justify-between pt-0.5 text-xs">
                        <dt class="text-slate-400">Status Bayar</dt>
                        <dd class="font-semibold text-slate-200" x-text="state.payment_label"></dd>
                    </div>
                </dl>
            </section>

            <p x-show="error" role="alert" class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs font-semibold text-rose-300" x-text="error"></p>
        </div>

        {{-- Bottom Actions (Clean, Solid, No Gradient Glow) --}}
        <div class="mt-6 space-y-2.5">
            <a href="{{ $config['menuUrl'] }}"
                class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-emerald-600 text-sm font-bold text-white shadow-md shadow-slate-950/20 hover:bg-emerald-700 active:scale-[0.99] transition-all">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                <span x-text="finished ? 'Pesan Menu Lagi' : 'Tambah Menu Lain'"></span>
            </a>

            <template x-if="state.cancellable">
                <div>
                    <button type="button" x-show="!confirmCancel" @click="confirmCancel = true"
                        class="flex h-9 w-full items-center justify-center rounded-xl text-xs font-medium text-slate-400 hover:text-rose-400 transition">
                        Batalkan Pesanan Ini
                    </button>

                    <div x-show="confirmCancel" class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-3.5 space-y-2.5">
                        <p class="text-xs text-rose-200 leading-relaxed" x-text="state.payment_method === 'qris' ? 'Jika Anda sudah membayar via QRIS, harap hubungi kasir agar mutasi diverifikasi.' : 'Pesanan akan dihapus dari antrean kasir dan dapur.'"></p>
                        <div class="flex gap-2">
                            <button type="button" @click="confirmCancel = false" class="inline-flex h-9 flex-1 items-center justify-center rounded-xl border border-slate-700 bg-slate-800 text-xs font-semibold text-slate-200 hover:bg-slate-700">Tidak Jadi</button>
                            <button type="button" @click="cancel()" :disabled="cancelling" class="inline-flex h-9 flex-1 items-center justify-center rounded-xl bg-rose-600 text-xs font-semibold text-white hover:bg-rose-700 disabled:opacity-50" x-text="cancelling ? 'Membatalkan...' : 'Ya, Batalkan'"></button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</x-layouts.self-order>
