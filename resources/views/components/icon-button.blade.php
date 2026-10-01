@props(['icon', 'label', 'tone' => 'default'])

@php
    $toneClasses = match ($tone) {
        'edit' => 'hover:text-emerald-400 hover:bg-emerald-500/10',
        'danger' => 'hover:text-rose-400 hover:bg-rose-500/10',
        default => 'hover:text-slate-100 hover:bg-slate-800',
    };
@endphp

<button {{ $attributes->merge([
    'type' => 'button',
    'aria-label' => $label,
    'title' => $label,
    'class' => "inline-flex items-center justify-center shrink-0 w-11 h-11 sm:w-8 sm:h-8 rounded-lg text-slate-400 {$toneClasses} focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40 disabled:opacity-50 disabled:pointer-events-none transition cursor-pointer",
]) }}>
    <i data-lucide="{{ $icon }}" aria-hidden="true" class="w-4 h-4"></i>
</button>
