@props(['size' => 'md'])

@php
    $sizeClasses = match ($size) {
        'xs' => 'sm:min-h-[30px] px-2.5 py-1 text-[11px]',
        'sm' => 'sm:min-h-[38px] px-4 py-2 text-xs',
        default => 'px-4 py-2 text-xs',
    };
@endphp

<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex flex-row items-center justify-center gap-2 whitespace-nowrap min-h-[44px] '.$sizeClasses.' bg-emerald-600 border border-transparent rounded-lg font-bold text-slate-950 hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-50 transition ease-in-out duration-150 cursor-pointer']) }}>
    {{ $slot }}
</button>
