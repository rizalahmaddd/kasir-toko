@props(['tone' => 'default', 'size' => 'xs'])

@php
    $toneClasses = match ($tone) {
        'emerald' => 'text-emerald-400 hover:text-emerald-300',
        'rose' => 'text-rose-400 hover:text-rose-300',
        'amber' => 'text-amber-300 hover:text-amber-200',
        default => 'text-slate-400 hover:text-slate-200',
    };
@endphp

<button {{ $attributes->merge([
    'type' => 'button',
    'class' => 'inline-flex items-center gap-1 min-h-[44px] sm:min-h-0 rounded font-semibold hover:underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40 disabled:opacity-50 transition cursor-pointer '.($size === 'sm' ? 'text-xs' : 'text-[11px]').' '.$toneClasses,
]) }}>
    {{ $slot }}
</button>
