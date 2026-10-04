@if (session()->has('impersonator_id'))
    <div class="mb-4 rounded-xl border border-indigo-500/40 bg-indigo-950/40 backdrop-blur-md p-3 sm:p-4 text-indigo-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-lg">
        <div class="flex items-center gap-3">
            <span class="relative flex h-3 w-3 shrink-0">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-indigo-500"></span>
            </span>
            <div class="text-xs sm:text-sm">
                <span class="font-bold text-white uppercase tracking-wider text-[11px] bg-indigo-500/20 px-2 py-0.5 rounded border border-indigo-500/30 me-1.5">Mode Dukungan</span>
                Anda sedang masuk ke toko <strong class="text-white">{{ app(\App\Support\CurrentTenant::class)->get()?->name }}</strong> sebagai <strong class="text-white">{{ auth()->user()->name }}</strong>.
            </div>
        </div>
        <form action="{{ route('platform.impersonate.leave') }}" method="POST" class="shrink-0">
            @csrf
            <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-xs transition shadow-sm">
                <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                <span>Kembali ke Admin Platform</span>
            </button>
        </form>
    </div>
@endif
