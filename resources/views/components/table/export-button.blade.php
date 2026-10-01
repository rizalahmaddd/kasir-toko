@props([
    'action' => 'export',
    'label' => 'Export',
    'title' => 'Ekspor Seluruh Data',
])

<div x-data="{ open: false, format: 'xlsx' }" class="relative inline-block">
    <button type="button" @click="open = true"
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 h-11 sm:h-[38px] px-3.5 text-xs font-semibold rounded-lg border border-slate-700/80 hover:border-slate-600 bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-white transition shadow-sm cursor-pointer']) }}
        title="Buka dialog ekspor untuk memilih format Excel, PDF, atau CSV">
        <i data-lucide="download" class="w-3.5 h-3.5 text-emerald-400"></i>
        <span>{{ $label }}</span>
        <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400 -mr-0.5"></i>
    </button>

    {{-- Dialog Modal Teleport --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-6 overflow-y-auto"
            style="display: none;" @keydown.escape.window="open = false">
            {{-- Backdrop --}}
            <div x-show="open" x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-slate-950/50 backdrop-blur-[2px]" @click="open = false"></div>

            {{-- Dialog Window --}}
            <div x-show="open" x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                x-transition:leave-end="opacity-0 scale-95 translate-y-2"
                class="relative bg-slate-900 border border-slate-800 rounded-t-2xl sm:rounded-2xl shadow-2xl shadow-slate-950/90 w-full sm:max-w-lg p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:p-6 text-left overflow-hidden z-10 space-y-5">
                {{-- Header --}}
                <div class="flex items-start justify-between gap-3 border-b border-slate-800/80 pb-4">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                            <i data-lucide="download" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-base text-slate-100">{{ $title }}</h3>
                            <p class="text-xs text-slate-400 mt-0.5">Pilih format berkas yang ingin Anda unduh</p>
                        </div>
                    </div>
                    <button type="button" @click="open = false"
                        class="inline-flex items-center justify-center w-11 h-11 -me-2 -mt-2 sm:w-8 sm:h-8 sm:me-0 sm:mt-0 text-slate-400 hover:text-slate-200 rounded-lg hover:bg-slate-800 transition"
                        title="Tutup dialog">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>

                {{-- Banner Full Export --}}
                <div
                    class="p-3 bg-emerald-950/30 border border-emerald-500/30 rounded-xl flex items-center gap-2.5 text-xs text-emerald-300">
                    <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                    <span>Ekspor ini mencakup <strong>seluruh data (tidak dibatasi halaman/tanpa paginasi)</strong>
                        sesuai filter &amp; kata kunci aktif.</span>
                </div>

                {{-- Format Options --}}
                <div class="space-y-2.5">
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider">Format
                        Berkas</label>

                    {{-- Option: Excel (.xlsx) --}}
                    <div @click="format = 'xlsx'"
                        :class="format === 'xlsx' ? 'border-emerald-500/60 bg-emerald-950/20 ring-1 ring-emerald-500/40' :
                            'border-slate-800 hover:border-slate-700 bg-slate-950/40'"
                        class="p-3.5 rounded-xl border transition-all cursor-pointer flex items-center justify-between gap-3 group">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center shrink-0">
                                <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="font-bold text-xs text-slate-100 group-hover:text-emerald-300 transition">Microsoft
                                        Excel (.xlsx)</span>
                                    <span
                                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Rekomendasi</span>
                                </div>
                                <p class="text-[11px] text-slate-400 mt-0.5">Spreadsheet rapi lengkap dengan warna
                                    header dan lebar kolom otomatis.</p>
                            </div>
                        </div>
                        <div class="shrink-0">
                            <div :class="format === 'xlsx' ? 'border-emerald-500 bg-emerald-500' :
                                'border-slate-700 bg-slate-900'"
                                class="w-4 h-4 rounded-full border flex items-center justify-center transition">
                                <div x-show="format === 'xlsx'" class="w-1.5 h-1.5 rounded-full bg-slate-950"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Option: PDF (.pdf) --}}
                    <div @click="format = 'pdf'"
                        :class="format === 'pdf' ? 'border-rose-500/60 bg-rose-950/20 ring-1 ring-rose-500/40' :
                            'border-slate-800 hover:border-slate-700 bg-slate-950/40'"
                        class="p-3.5 rounded-xl border transition-all cursor-pointer flex items-center justify-between gap-3 group">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-400 flex items-center justify-center shrink-0">
                                <i data-lucide="file-text" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <span
                                    class="font-bold text-xs text-slate-100 group-hover:text-rose-300 transition">Dokumen
                                    PDF (.pdf)</span>
                                <p class="text-[11px] text-slate-400 mt-0.5">Dokumen siap cetak dengan kop resmi
                                    {{ \App\Support\Branding::companyName() }} dan penomoran halaman.</p>
                            </div>
                        </div>
                        <div class="shrink-0">
                            <div :class="format === 'pdf' ? 'border-rose-500 bg-rose-500' : 'border-slate-700 bg-slate-900'"
                                class="w-4 h-4 rounded-full border flex items-center justify-center transition">
                                <div x-show="format === 'pdf'" class="w-1.5 h-1.5 rounded-full bg-slate-950"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Option: CSV (.csv) --}}
                    <div @click="format = 'csv'"
                        :class="format === 'csv' ? 'border-sky-500/60 bg-sky-950/20 ring-1 ring-sky-500/40' :
                            'border-slate-800 hover:border-slate-700 bg-slate-950/40'"
                        class="p-3.5 rounded-xl border transition-all cursor-pointer flex items-center justify-between gap-3 group">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-lg bg-sky-500/15 border border-sky-500/30 text-sky-400 flex items-center justify-center shrink-0">
                                <i data-lucide="table" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <span class="font-bold text-xs text-slate-100 group-hover:text-sky-300 transition">Tabel
                                    CSV (.csv)</span>
                                <p class="text-[11px] text-slate-400 mt-0.5">Teks data murni ber-BOM UTF-8, ringan dan
                                    kompatibel untuk integrasi sistem.</p>
                            </div>
                        </div>
                        <div class="shrink-0">
                            <div :class="format === 'csv' ? 'border-sky-500 bg-sky-500' : 'border-slate-700 bg-slate-900'"
                                class="w-4 h-4 rounded-full border flex items-center justify-center transition">
                                <div x-show="format === 'csv'" class="w-1.5 h-1.5 rounded-full bg-slate-950"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="pt-3 border-t border-slate-800/80 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2.5 sm:gap-3 [&>*]:w-full sm:[&>*]:w-auto [&>*]:justify-center">
                    <button type="button" @click="open = false"
                        class="h-11 sm:h-[38px] px-4 rounded-lg border border-slate-700 text-xs font-semibold text-slate-300 hover:text-white hover:bg-slate-800 transition">
                        Batal
                    </button>
                    <button type="button" wire:click="{{ $action }}(format)" wire:loading.attr="disabled"
                        @click="setTimeout(() => { open = false; }, 1200)"
                        class="h-11 sm:h-[38px] px-5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-slate-950 text-xs font-bold transition flex items-center gap-2 shadow-sm cursor-pointer disabled:opacity-50">
                        <span wire:loading.remove wire:target="{{ $action }}">
                            <span x-text="'Unduh ' + format.toUpperCase()">Unduh Berkas</span>
                        </span>
                        <span wire:loading wire:target="{{ $action }}" class="inline-flex items-center gap-1.5">
                            <svg class="animate-spin -ml-0.5 mr-1 h-3.5 w-3.5 text-slate-950" fill="none"
                                viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10"
                                    stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                            <span>Memproses...</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
