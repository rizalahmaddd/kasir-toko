@props(['color' => 'slate'])

@php
    // DESIGN.md "Palet Warna": emerald/rose/amber tetap warna fungsional (status nyata),
    // sky dipakai seperlunya untuk status netral-informatif seperti "Disetujui".
    $palette = [
        'slate' => 'bg-slate-800 text-slate-400 border-slate-700',
        'emerald' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
        'amber' => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
        'rose' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
        'sky' => 'bg-sky-500/10 text-sky-400 border-sky-500/30',
    ][in_array($color, ['indigo', 'purple', 'violet', 'blue'], true) ? 'sky' : $color] ?? 'bg-slate-800 text-slate-400 border-slate-700';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold border {$palette}"]) }}>
    {{ $slot }}
</span>
