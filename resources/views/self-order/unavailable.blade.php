<x-layouts.self-order :title="$storeName.' - Pemesanan Tidak Tersedia'">
    <main class="mx-auto max-w-md min-h-dvh flex flex-col items-center justify-center px-6 py-12 text-center bg-slate-950 text-slate-100">
        <div class="w-full bg-slate-900 rounded-3xl border border-slate-700/80 p-8 shadow-sm">
            <div class="w-16 h-16 rounded-2xl bg-amber-500/15 text-amber-500 border border-amber-500/30 flex items-center justify-center mx-auto mb-5 shadow-2xs">
                <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="3" rx="2"/>
                    <path d="M3 9h18"/>
                    <path d="M9 21V9"/>
                    <line x1="8" y1="14" x2="8.01" y2="14"/>
                </svg>
            </div>

            <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">{{ $storeName }}</p>
            <h1 class="text-xl font-bold text-slate-100 leading-snug">Pemesanan QR Sedang Ditutup</h1>
            <p class="mt-3 text-xs leading-relaxed text-slate-300 bg-slate-800 p-3.5 rounded-xl border border-slate-700">{{ $message }}</p>

            <div class="mt-6 pt-6 border-t border-slate-800 space-y-2">
                <p class="text-xs font-semibold text-slate-200">Ingin memesan langsung?</p>
                <p class="text-[11px] text-slate-400 leading-relaxed">Silakan langsung menuju ke meja kasir untuk memesan makanan dan minuman Anda.</p>
            </div>
        </div>

        <p class="mt-6 text-[11px] text-slate-400">Terima kasih atas pengertian Anda.</p>
    </main>
</x-layouts.self-order>
