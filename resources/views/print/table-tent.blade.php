<x-layouts.print :title="'QR Meja '.$table->name">
    <style>
        @page { size: A6 portrait; margin: 6mm; }
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

    <div class="paper-sheet bg-white text-slate-900 rounded-3xl border-2 border-slate-900 mx-auto p-5 text-center relative flex flex-col justify-between" style="width: 105mm; min-height: 144mm; box-sizing: border-box;">
        {{-- Header & Branding --}}
        <div>
            <div class="flex items-center justify-between gap-2 border-b border-slate-200 pb-2.5">
                <div class="text-left">
                    <p class="text-[10px] font-black uppercase tracking-wider text-slate-900 leading-tight">{{ \App\Support\Branding::companyName() }}</p>
                    @if (app(\App\Support\CurrentOutlet::class)->isMultiOutlet())
                        <p class="text-[9px] text-slate-500 font-medium leading-tight">{{ $outlet->name }}</p>
                    @endif
                </div>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[9px] font-extrabold uppercase tracking-wide">
                    Dine In
                </span>
            </div>

            {{-- Table Focal Badge --}}
            <div class="mt-3 flex items-center justify-center gap-2">
                <div class="inline-flex items-baseline gap-1.5 px-4 py-1 rounded-2xl bg-slate-900 text-white shadow-sm">
                    <span class="text-[11px] font-bold uppercase tracking-widest text-slate-400">MEJA</span>
                    <span class="text-3xl font-black tracking-tight text-white leading-none">{{ $table->name }}</span>
                </div>
                @if ($table->area)
                    <span class="px-2.5 py-1 rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-[10px] font-semibold">{{ $table->area }}</span>
                @endif
            </div>

            <p class="mt-2 text-base font-extrabold text-slate-900 leading-tight">
                Pindai untuk memesan
            </p>
            <p class="mt-0.5 text-[10px] text-slate-600 leading-normal">
                Buka kamera HP Anda, arahkan ke QR di bawah, lalu pilih menu langsung dari meja.
            </p>
        </div>

        {{-- QR Box with Scanner Frame --}}
        <div class="my-2 flex flex-col items-center">
            <div class="relative p-2.5 bg-white rounded-2xl border-2 border-slate-900 shadow-sm">
                {{-- Decorative Corner Markers --}}
                <div class="absolute -top-1.5 -left-1.5 w-4 h-4 border-t-3 border-l-3 border-emerald-600 rounded-tl"></div>
                <div class="absolute -top-1.5 -right-1.5 w-4 h-4 border-t-3 border-r-3 border-emerald-600 rounded-tr"></div>
                <div class="absolute -bottom-1.5 -left-1.5 w-4 h-4 border-b-3 border-l-3 border-emerald-600 rounded-bl"></div>
                <div class="absolute -bottom-1.5 -right-1.5 w-4 h-4 border-b-3 border-r-3 border-emerald-600 rounded-br"></div>

                <div class="w-44 h-44 [&>svg]:w-full [&>svg]:h-full" role="img" aria-label="QR pesanan Meja {{ $table->name }}">
                    {!! $qr !!}
                </div>
            </div>
        </div>

        {{-- Mini Steps & Footer --}}
        <div>
            <div class="grid grid-cols-3 gap-1.5 text-center mb-2">
                <div class="rounded-lg bg-slate-50 border border-slate-200 py-1.5 px-1">
                    <span class="block text-[10px] font-bold text-slate-900">1. Pindai</span>
                    <span class="block text-[8px] text-slate-500">Kamera HP</span>
                </div>
                <div class="rounded-lg bg-slate-50 border border-slate-200 py-1.5 px-1">
                    <span class="block text-[10px] font-bold text-slate-900">2. Pesan</span>
                    <span class="block text-[8px] text-slate-500">Pilih menu</span>
                </div>
                <div class="rounded-lg bg-slate-50 border border-slate-200 py-1.5 px-1">
                    <span class="block text-[10px] font-bold text-slate-900">3. Santap</span>
                    <span class="block text-[8px] text-slate-500">Diantar ke meja</span>
                </div>
            </div>

            <p class="text-[9px] text-slate-500 border-t border-slate-200 pt-1.5">
                Tanpa perlu instal aplikasi apa pun · Selamat menikmati hidangan
            </p>
        </div>
    </div>
</x-layouts.print>
