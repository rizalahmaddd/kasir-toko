@props(['active' => true, 'onLabel' => 'Aktif', 'offLabel' => 'Nonaktif'])

<span
    {{ $attributes->merge(['class' => 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border '
        . ($active
            ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
            : 'bg-slate-800 text-slate-400 border-slate-700')]) }}
>
    {{ $active ? $onLabel : $offLabel }}
</span>
