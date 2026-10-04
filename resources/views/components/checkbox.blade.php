@props(['label' => null])

@php
    $inputClasses = 'w-4 h-4 shrink-0 rounded border-slate-700 bg-slate-950 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-slate-900 disabled:opacity-50 transition cursor-pointer';
@endphp

@if ($label)
    <label class="inline-flex items-center gap-2 min-h-[44px] sm:min-h-0 cursor-pointer select-none">
        <input type="checkbox" {{ $attributes->merge(['class' => $inputClasses]) }}>
        <span class="text-xs font-medium text-slate-300">{{ $label }}</span>
    </label>
@else
    <input type="checkbox" {{ $attributes->merge(['class' => $inputClasses]) }}>
@endif
