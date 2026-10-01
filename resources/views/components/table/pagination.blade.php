@props(['paginator'])

<div class="flex flex-col sm:flex-row items-center justify-between gap-4 p-4 text-xs text-slate-400 select-none">
    {{-- Info Jumlah Data --}}
    <div class="flex items-center gap-1.5 order-2 sm:order-1">
        <span>Menampilkan</span>
        <span class="font-semibold text-slate-200">{{ $paginator->firstItem() ?? 0 }}</span>
        <span>-</span>
        <span class="font-semibold text-slate-200">{{ $paginator->lastItem() ?? 0 }}</span>
        <span>dari</span>
        <span class="font-semibold text-slate-200">{{ $paginator->total() }}</span>
        <span>data</span>
    </div>

    {{-- Kontrol Per Halaman & Tombol Navigasi --}}
    <div class="flex items-center gap-4 order-1 sm:order-2 w-full sm:w-auto justify-between sm:justify-end">
        {{-- Dropdown Pilihan Per-Halaman --}}
        <div class="inline-flex items-center gap-1.5">
            <span class="text-slate-400 hidden xs:inline">Baris:</span>
            <x-select
                wire:model.live="perPage"
                title="Pilih jumlah baris per halaman"
                variant="compact"
            >
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </x-select>
        </div>

        {{-- Navigasi Halaman --}}
        @if ($paginator->hasPages())
            <nav role="navigation" aria-label="Pagination" class="inline-flex items-center gap-1">
                {{-- Tombol Sebelumnya --}}
                @if ($paginator->onFirstPage())
                    <span class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg border border-slate-800 bg-slate-950/40 text-slate-600 cursor-not-allowed">
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </span>
                @else
                    <button
                        type="button"
                        wire:click="previousPage"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg border border-slate-700/80 bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white transition cursor-pointer"
                        title="Halaman sebelumnya"
                    >
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </button>
                @endif

                {{-- Di mobile nomor halaman diganti teks posisi; cukup tombol sebelum/berikut yang besar --}}
                <span class="sm:hidden px-2 text-slate-300 font-medium">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

                {{-- Nomor Halaman Berjendela: elements() milik paginator protected, jadi jendelanya dirakit ulang di sini --}}
                @php
                    $window = \Illuminate\Pagination\UrlWindow::make($paginator);
                    $elements = array_filter([
                        $window['first'],
                        is_array($window['slider']) ? '...' : null,
                        $window['slider'],
                        is_array($window['last']) ? '...' : null,
                        $window['last'],
                    ]);
                @endphp
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="hidden sm:inline-flex items-center justify-center w-8 h-8 text-slate-500">...</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="hidden sm:inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500 text-white font-bold shadow-sm">
                                    {{ $page }}
                                </span>
                            @else
                                <button
                                    type="button"
                                    wire:click="gotoPage({{ $page }})"
                                    wire:loading.attr="disabled"
                                    class="hidden sm:inline-flex items-center justify-center w-8 h-8 rounded-lg border border-slate-800 bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white transition cursor-pointer"
                                >
                                    {{ $page }}
                                </button>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Tombol Selanjutnya --}}
                @if ($paginator->hasMorePages())
                    <button
                        type="button"
                        wire:click="nextPage"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg border border-slate-700/80 bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white transition cursor-pointer"
                        title="Halaman berikutnya"
                    >
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                @else
                    <span class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg border border-slate-800 bg-slate-950/40 text-slate-600 cursor-not-allowed">
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </span>
                @endif
            </nav>
        @else
            <span class="text-[11px] text-slate-500 font-medium px-2 py-1 rounded bg-slate-950/40 border border-slate-800/60">
                Hal 1 / 1
            </span>
        @endif
    </div>
</div>
