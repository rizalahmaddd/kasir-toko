@props(['size' => 'md', 'variant' => 'filter', 'clearable' => false])

@php
    $heightClasses = match ($size) {
        'sm' => 'h-11 sm:h-9',
        default => 'h-11 sm:h-[38px]',
    };

    $surfaceClasses = $variant === 'form' ? 'bg-slate-950' : 'bg-slate-900';

    $inputAttributes = $attributes->except('class');
    $modelName = $attributes->wire('model')->value();
    if (! $inputAttributes->has('aria-label') && $inputAttributes->has('placeholder')) {
        $inputAttributes = $inputAttributes->merge(['aria-label' => rtrim($inputAttributes->get('placeholder'), '.… ')]);
    }
@endphp

<div {{ $attributes->only('class')->merge(['class' => 'relative']) }}>
    <i data-lucide="search" aria-hidden="true" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
    <input
        type="text"
        autocomplete="off"
        {{ $inputAttributes->merge(['class' => "w-full {$heightClasses} {$surfaceClasses} border border-slate-800 rounded-lg pl-9 ".($clearable ? 'pr-11' : 'pr-3').' text-xs text-slate-200 placeholder:text-slate-400 focus:outline-none focus:border-emerald-500 transition']) }}
    >
    @if ($clearable && $modelName)
        <x-icon-button icon="x" label="Hapus pencarian" wire:click="$set('{{ $modelName }}', '')" class="absolute right-0 sm:right-0.5 top-1/2 -translate-y-1/2" />
    @endif
</div>
