@props(['size' => 'md', 'tone' => 'default', 'href' => null])

@php
    $sizeClasses = match ($size) {
        'xs' => 'sm:min-h-[30px] px-2.5 py-1 text-[11px]',
        'sm' => 'sm:min-h-[38px] px-4 py-2 text-xs',
        default => 'px-4 py-2 text-xs',
    };
    $toneClasses = match ($tone) {
        'danger' => 'border-rose-500/30 text-rose-400 focus:ring-rose-500',
        'emerald' => 'border-emerald-500/30 text-emerald-400 focus:ring-emerald-500',
        default => 'border-slate-700 text-slate-300 focus:ring-emerald-500',
    };
    $classes = 'inline-flex flex-row items-center justify-center gap-2 whitespace-nowrap min-h-[44px] '.$sizeClasses." bg-slate-800 border {$toneClasses} rounded-lg font-semibold hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-50 transition ease-in-out duration-150 cursor-pointer";
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
