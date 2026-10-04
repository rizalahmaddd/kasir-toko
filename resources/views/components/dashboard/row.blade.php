@props([
    'href' => null,
    'title',
    'meta' => null,
    'value' => null,
    'valueTone' => 'slate',
    'badge' => null,
    'badgeColor' => 'slate',
])

{{-- Satu baris dokumen di panel dashboard: nomor/nama di kiri, angka penentu tindakan di kanan. --}}
@php
    $valueClass = [
        'slate'   => 'text-slate-700 dark:text-slate-200',
        'amber'   => 'text-amber-600 dark:text-amber-400',
        'rose'    => 'text-rose-600 dark:text-rose-400',
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
    ][$valueTone] ?? 'text-slate-700 dark:text-slate-200';
    $tag = $href ? 'a' : 'div';
@endphp

@if (\App\Support\Features::allowsUrl($href))
<li {{ $attributes }}>
    <{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
        @class([
            'py-2.5 flex items-center justify-between gap-3 text-xs',
            '-mx-2 px-2 rounded-lg hover:bg-slate-800/60 transition-colors min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500' => $href,
        ])>
        <div class="min-w-0">
            <div class="font-semibold text-slate-800 dark:text-slate-200 truncate">{{ $title }}</div>
            @if ($meta)
                <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">{{ $meta }}</div>
            @endif
        </div>
        <div class="text-right shrink-0 flex flex-col items-end gap-1">
            @if ($value !== null)
                <span class="font-bold tabular-nums {{ $valueClass }}">{{ $value }}</span>
            @endif
            @if ($badge)
                <x-badge :color="$badgeColor">{{ $badge }}</x-badge>
            @endif
        </div>
    </{{ $tag }}>
</li>
@endif
