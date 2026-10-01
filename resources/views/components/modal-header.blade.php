@props([
    'title',
    'icon' => null,
    'tone' => 'emerald',
    'closeable' => false,
    'closeAction' => null,
])

@php
    $toneClasses = [
        'emerald' => 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400',
        'rose' => 'bg-rose-500/10 border-rose-500/20 text-rose-400',
        'amber' => 'bg-amber-500/10 border-amber-500/20 text-amber-400',
        'sky' => 'bg-sky-500/10 border-sky-500/20 text-sky-400',
        'slate' => 'bg-slate-800 border-slate-700/60 text-slate-300',
    ][$tone] ?? 'bg-slate-800 border-slate-700/60 text-slate-300';
@endphp

<div {{ $attributes->merge(['class' => 'flex items-start gap-3.5']) }}>
    @if ($icon)
        <div class="w-10 h-10 rounded-xl border {{ $toneClasses }} flex items-center justify-center shrink-0">
            <i data-lucide="{{ $icon }}" class="w-5 h-5"></i>
        </div>
    @endif

    <div class="min-w-0 flex-1 {{ $icon ? 'pt-0.5' : '' }}">
        <h3 class="font-bold text-sm sm:text-base text-slate-100 leading-snug">{{ $title }}</h3>
        @if ($slot->isNotEmpty())
            <div class="text-xs text-slate-400 mt-1 leading-relaxed">{{ $slot }}</div>
        @endif
    </div>

    @if ($closeable || $closeAction)
        @if ($closeAction)
            <x-icon-button icon="x" :label="__('Tutup')" wire:click="{{ $closeAction }}" class="-me-2 -mt-1.5 sm:-mt-1" />
        @else
            <x-icon-button icon="x" :label="__('Tutup')" x-on:click="$dispatch('close')" class="-me-2 -mt-1.5 sm:-mt-1" />
        @endif
    @endif
</div>
