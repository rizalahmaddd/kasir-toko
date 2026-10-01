@props(['active', 'sub' => false])

@php
$base = $sub
    ? 'flex items-center gap-2 min-h-[44px] md:min-h-[32px] px-2.5 py-1.5 rounded-md text-xs font-medium transition focus:outline-none focus:ring-1 focus:ring-emerald-500/60'
    : 'flex items-center gap-2.5 min-h-[44px] md:min-h-[36px] px-2.5 py-2 rounded-lg text-sm font-medium transition focus:outline-none focus:ring-1 focus:ring-emerald-500/60';

$classes = ($active ?? false)
    ? $base . ' bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
    : $base . ' text-slate-400 border border-transparent hover:bg-slate-800/60 hover:text-slate-200';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
