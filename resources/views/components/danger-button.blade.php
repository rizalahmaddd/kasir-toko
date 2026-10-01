@props(['size' => 'md'])

@php
    $sizeClasses = match ($size) {
        'xs' => 'sm:min-h-[30px] px-2.5 py-1 text-[11px]',
        'sm' => 'sm:min-h-[38px] px-4 py-2 text-xs',
        default => 'px-4 py-2 text-xs',
    };
@endphp

<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex flex-row items-center justify-center gap-2 whitespace-nowrap min-h-[44px] '.$sizeClasses.' bg-rose-600 border border-transparent rounded-lg font-bold text-white hover:bg-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-50 transition ease-in-out duration-150 cursor-pointer']) }}>
    {{ $slot }}
</button>
