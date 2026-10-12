<x-layouts.print :title="'QR Meja '.$outlet->name">
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        .paper-sheet { color: #0f172a !important; }
        .paper-sheet .text-slate-900, .paper-sheet .text-slate-800, .paper-sheet .text-slate-700 { color: #0f172a !important; }
        .paper-sheet .text-slate-600, .paper-sheet .text-slate-500 { color: #475569 !important; }
        .paper-sheet .text-slate-400 { color: #64748b !important; }
        .paper-sheet .bg-slate-900 { background-color: #0f172a !important; color: #ffffff !important; }
        .paper-sheet .border-slate-900 { border-color: #0f172a !important; }
        @media print {
            .no-print { display: none !important; }
            .paper-sheet { border: none !important; box-shadow: none !important; padding: 0 !important; width: 100% !important; margin: 0 !important; }
        }
    </style>

    <div class="paper-sheet bg-white text-slate-900 rounded-2xl border border-slate-300 p-4">
        <div class="no-print mb-4 flex items-center justify-between bg-slate-100 rounded-xl p-3 border border-slate-200">
            <div>
                <p class="text-xs font-bold text-slate-900">{{ $tables->count() }} meja aktif di {{ $outlet->name }}</p>
                <p class="text-[11px] text-slate-500">Potong mengikuti garis putus-putus untuk dijadikan stiker atau kartu akrilik meja.</p>
            </div>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-md bg-emerald-100 text-emerald-800">Format Lembar A4</span>
        </div>

        @if ($tables->isEmpty())
            <div class="py-20 text-center text-sm text-slate-500">
                <p class="font-bold text-slate-800 text-base">Belum ada meja aktif di outlet ini</p>
                <p class="mt-1 text-xs">Tambahkan meja di halaman Pengaturan Meja & QR untuk mencetak stiker QR.</p>
            </div>
        @else
            <div class="grid grid-cols-2 gap-3">
                @foreach ($tables as $table)
                    <div class="border-2 border-dashed border-slate-300 rounded-2xl p-3 bg-white relative hover:border-slate-400 transition" style="break-inside: avoid; page-break-inside: avoid;">
                        {{-- Scissors Cut Indicator --}}
                        <div class="absolute -top-2.5 left-6 bg-white px-1 text-[10px] text-slate-400 font-mono flex items-center gap-1 select-none">
                            ✂ <span class="text-[8px] uppercase tracking-wider">Garis Potong</span>
                        </div>

                        <div class="rounded-xl border border-slate-900/80 p-3 bg-slate-50/50 flex items-center gap-4">
                            {{-- QR Code Box with Scanner Corners --}}
                            <div class="relative shrink-0 p-1.5 bg-white rounded-xl border border-slate-900 shadow-xs">
                                <div class="w-32 h-32 [&>svg]:w-full [&>svg]:h-full" role="img" aria-label="QR pesanan Meja {{ $table['name'] }}">
                                    {!! $table['qr'] !!}
                                </div>
                            </div>

                            {{-- Table Info --}}
                            <div class="min-w-0 flex-1 text-left">
                                <div class="flex items-center gap-1.5">
                                    <span class="px-2 py-0.5 rounded-md bg-slate-900 text-white text-[10px] font-black uppercase tracking-wider">
                                        MEJA
                                    </span>
                                    <span class="text-3xl font-black text-slate-900 leading-none">
                                        {{ $table['name'] }}
                                    </span>
                                </div>

                                @if ($table['area'])
                                    <p class="mt-1 text-[10px] font-semibold text-emerald-700 truncate">{{ $table['area'] }}</p>
                                @endif

                                <div class="mt-2.5 pt-2 border-t border-slate-200">
                                    <p class="text-[11px] font-extrabold text-slate-900 leading-tight">Pindai untuk Memesan</p>
                                    <p class="text-[9px] text-slate-500 mt-0.5">{{ \App\Support\Branding::companyName() }}</p>
                                    <p class="text-[8px] text-slate-400 mt-0.5">Buka kamera HP Anda</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.print>
