<x-layouts.print :title="'QR Take Away '.$outlet->name">
    <style>
        @page { size: A5 portrait; margin: 8mm; }
        .paper-sheet { color: #0f172a !important; }
        .paper-sheet .text-slate-900, .paper-sheet .text-slate-800, .paper-sheet .text-slate-700 { color: #0f172a !important; }
        .paper-sheet .text-slate-600, .paper-sheet .text-slate-500 { color: #475569 !important; }
        .paper-sheet .text-slate-400 { color: #64748b !important; }
        .paper-sheet .bg-slate-900 { background-color: #0f172a !important; color: #ffffff !important; }
        .paper-sheet .border-slate-900 { border-color: #0f172a !important; }
        @media print {
            .paper-sheet { border: none !important; box-shadow: none !important; width: 100% !important; height: 100% !important; margin: 0 !important; }
        }
    </style>

    <div class="paper-sheet bg-white text-slate-900 rounded-3xl border-2 border-slate-900 mx-auto p-7 text-center relative flex flex-col justify-between" style="width: 148mm; min-height: 204mm; box-sizing: border-box;">
        {{-- Header & Branding --}}
        <div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-900 text-white text-[11px] font-bold tracking-wider uppercase mb-3">
                <svg class="w-3.5 h-3.5 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>
                <span>Pesan Mandiri · Bawa Pulang</span>
            </div>

            <h1 class="text-2xl font-black tracking-tight text-slate-900 uppercase">
                {{ \App\Support\Branding::companyName() }}
            </h1>
            @if (app(\App\Support\CurrentOutlet::class)->isMultiOutlet())
                <p class="text-xs font-semibold text-slate-500 mt-0.5 tracking-wide">{{ $outlet->name }}</p>
            @endif

            <div class="mt-4">
                <p class="text-3xl font-extrabold tracking-tight text-slate-900 leading-tight">
                    Pesan bawa pulang<br><span class="text-emerald-700">tanpa perlu antre</span>
                </p>
                <p class="mt-1.5 text-xs text-slate-600 max-w-xs mx-auto leading-relaxed">
                    Buka menu, pesan langsung dari ponsel Anda, dan pantau status pembuatan pesanan secara real-time.
                </p>
            </div>
        </div>

        {{-- QR Showcase with Scanner Framing --}}
        <div class="my-5 flex flex-col items-center">
            <div class="relative p-4 bg-white rounded-2xl border-2 border-slate-900 shadow-md">
                {{-- Decorative Scanner Corner Brackets --}}
                <div class="absolute -top-2 -left-2 w-5 h-5 border-t-4 border-l-4 border-emerald-600 rounded-tl-md"></div>
                <div class="absolute -top-2 -right-2 w-5 h-5 border-t-4 border-r-4 border-emerald-600 rounded-tr-md"></div>
                <div class="absolute -bottom-2 -left-2 w-5 h-5 border-b-4 border-l-4 border-emerald-600 rounded-bl-md"></div>
                <div class="absolute -bottom-2 -right-2 w-5 h-5 border-b-4 border-r-4 border-emerald-600 rounded-br-md"></div>

                <div class="w-60 h-60 [&>svg]:w-full [&>svg]:h-full" role="img" aria-label="QR pesanan take away">
                    {!! $qr !!}
                </div>
            </div>

            <div class="mt-3 inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-slate-100 border border-slate-300 text-slate-800 text-xs font-bold tracking-wide">
                <svg class="w-4 h-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
                <span>Arahkan kamera HP ke kode QR</span>
            </div>
        </div>

        {{-- 3 Visual Steps --}}
        <div>
            <div class="grid grid-cols-3 gap-2 text-left mb-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-2.5">
                    <div class="w-6 h-6 rounded-lg bg-emerald-600 text-white flex items-center justify-center text-xs font-black mb-1.5">1</div>
                    <p class="text-xs font-bold text-slate-900 leading-tight">Pindai QR</p>
                    <p class="text-[10px] text-slate-500 leading-snug mt-0.5">Buka kamera ponsel Anda</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-2.5">
                    <div class="w-6 h-6 rounded-lg bg-emerald-600 text-white flex items-center justify-center text-xs font-black mb-1.5">2</div>
                    <p class="text-xs font-bold text-slate-900 leading-tight">Pilih Menu</p>
                    <p class="text-[10px] text-slate-500 leading-snug mt-0.5">Pilih & kirim pesanan Anda</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-2.5">
                    <div class="w-6 h-6 rounded-lg bg-emerald-600 text-white flex items-center justify-center text-xs font-black mb-1.5">3</div>
                    <p class="text-xs font-bold text-slate-900 leading-tight">Ambil Pesanan</p>
                    <p class="text-[10px] text-slate-500 leading-snug mt-0.5">Tunggu nomor Anda dipanggil</p>
                </div>
            </div>

            {{-- Footer Note --}}
            <div class="pt-2 border-t border-slate-200 flex items-center justify-between text-[10px] text-slate-500">
                <span>Tidak perlu install aplikasi apa pun</span>
                <span class="font-semibold text-slate-700">Terima kasih atas kunjungan Anda</span>
            </div>
        </div>
    </div>
</x-layouts.print>
