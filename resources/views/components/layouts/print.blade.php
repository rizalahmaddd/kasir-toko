@props([
    'title' => 'Cetak Dokumen',
    'orientation' => 'portrait',
])

@php
    $orientation = request('orientation', $orientation);
    if (! in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = 'portrait';
    }
@endphp

<!DOCTYPE html>
<html lang="id" class="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>{{ $title }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Dokumen cetak selalu putih/terang (DESIGN.md "Tema"): dibaca hasil cetak di atas
             kertas, bukan di layar gelap seperti sisa aplikasi. --}}
        <style id="page-orientation-style">
            @page {
                size: {{ $orientation }};
                margin: 12mm;
            }
        </style>
        <style>
            .doc-title-badge,
            .paper-sheet .doc-title-badge,
            .paper-sheet .bg-slate-900 {
                background-color: #0f172a !important;
                color: #ffffff !important;
            }
            @media print {
                .no-print { display: none !important; }
                body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
                #paper-container { padding: 0 !important; margin: 0 !important; width: 100% !important; max-width: 100% !important; }
                .paper-sheet { border: none !important; box-shadow: none !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
            }
        </style>
    </head>
    <body class="font-sans antialiased bg-slate-200 text-slate-900 min-h-screen">
        <div class="no-print sticky top-0 z-20 bg-slate-900 border-b border-slate-800 px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 shadow-md">
            <div class="flex items-center gap-3">
                <span class="text-xs text-slate-400 hidden sm:inline">{{ __('Pratinjau dokumen, tidak ikut tercetak.') }}</span>

                {{-- Pilihan Rotasi / Orientasi Kertas --}}
                <div class="inline-flex items-center bg-slate-950 p-0.5 rounded-lg border border-slate-800 text-xs select-none">
                    <button 
                        type="button" 
                        id="btn-portrait"
                        onclick="setOrientation('portrait')" 
                        class="px-2.5 py-1 rounded-md transition flex items-center gap-1.5 cursor-pointer {{ $orientation === 'portrait' ? 'bg-slate-800 text-slate-100 font-semibold shadow-sm' : 'text-slate-400 hover:text-slate-200' }}"
                        title="Format Kertas Tegak (Portrait)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <rect x="5" y="3" width="14" height="18" rx="2" stroke-width="2" />
                        </svg>
                        <span>Tegak (Portrait)</span>
                    </button>
                    <button 
                        type="button" 
                        id="btn-landscape"
                        onclick="setOrientation('landscape')" 
                        class="px-2.5 py-1 rounded-md transition flex items-center gap-1.5 cursor-pointer {{ $orientation === 'landscape' ? 'bg-slate-800 text-slate-100 font-semibold shadow-sm' : 'text-slate-400 hover:text-slate-200' }}"
                        title="Format Kertas Melebar (Landscape)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <rect x="3" y="5" width="18" height="14" rx="2" stroke-width="2" />
                        </svg>
                        <span>Melebar (Landscape)</span>
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" onclick="closePrintDoc()" class="bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold px-3.5 py-1.5 rounded-lg text-xs flex items-center gap-1.5 min-h-[38px] cursor-pointer transition">
                    <span>{{ __('Tutup') }}</span>
                </button>
                <button onclick="window.print()" class="bg-emerald-600 hover:bg-emerald-500 text-slate-950 font-bold px-3.5 py-1.5 rounded-lg text-xs flex items-center gap-1.5 min-h-[38px] cursor-pointer shadow-sm transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    <span>{{ __('Cetak / Simpan PDF') }}</span>
                </button>
            </div>
        </div>

        <div id="paper-container" class="mx-auto p-4 md:p-8 transition-all duration-200 {{ $orientation === 'landscape' ? 'max-w-5xl' : 'max-w-3xl' }}">
            {{ $slot }}
        </div>

        <script>
            function setOrientation(mode) {
                const styleEl = document.getElementById('page-orientation-style');
                const container = document.getElementById('paper-container');
                const btnPortrait = document.getElementById('btn-portrait');
                const btnLandscape = document.getElementById('btn-landscape');

                if (styleEl) {
                    styleEl.textContent = '@page { size: ' + mode + '; margin: 12mm; }';
                }

                if (container) {
                    if (mode === 'landscape') {
                        container.classList.remove('max-w-3xl');
                        container.classList.add('max-w-5xl');
                    } else {
                        container.classList.remove('max-w-5xl');
                        container.classList.add('max-w-3xl');
                    }
                }

                const activeClasses = ['bg-slate-800', 'text-slate-100', 'font-semibold', 'shadow-sm'];
                const inactiveClasses = ['text-slate-400', 'hover:text-slate-200'];

                if (btnPortrait && btnLandscape) {
                    if (mode === 'portrait') {
                        btnPortrait.classList.add(...activeClasses);
                        btnPortrait.classList.remove(...inactiveClasses);

                        btnLandscape.classList.remove(...activeClasses);
                        btnLandscape.classList.add(...inactiveClasses);
                    } else {
                        btnLandscape.classList.add(...activeClasses);
                        btnLandscape.classList.remove(...inactiveClasses);

                        btnPortrait.classList.remove(...activeClasses);
                        btnPortrait.classList.add(...inactiveClasses);
                    }
                }
            }

            function closePrintDoc() {
                window.close();
                setTimeout(function () {
                    if (window.history.length > 1) {
                        window.history.back();
                    } else if (document.referrer) {
                        window.location.href = document.referrer;
                    } else {
                        window.location.href = "{{ route('dashboard') }}";
                    }
                }, 150);
            }
        </script>
    </body>
</html>
