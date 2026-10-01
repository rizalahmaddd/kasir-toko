@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-[44px] sm:min-h-0 bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder:text-slate-400 focus:outline-none focus:border-emerald-500 disabled:opacity-50 transition']) }}>
