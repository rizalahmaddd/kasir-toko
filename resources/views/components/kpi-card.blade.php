@props([
    'title',
    'value',
    'icon' => 'bar-chart-2',
    'color' => 'emerald',
    'subtitle' => null,
    'badge' => null,
    'badgeColor' => null,
    'href' => null,
])

@php
    $colorMap = [
        'emerald' => [
            'icon' => 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/20',
            'badge' => 'text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/20',
        ],
        'amber' => [
            'icon' => 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20',
            'badge' => 'text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20',
        ],
        'rose' => [
            'icon' => 'text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-500/10 border-rose-200 dark:border-rose-500/20',
            'badge' => 'text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-500/10 border-rose-200 dark:border-rose-500/20',
        ],
        'sky' => [
            'icon' => 'text-sky-600 dark:text-sky-400 bg-sky-50 dark:bg-sky-500/10 border-sky-200 dark:border-sky-500/20',
            'badge' => 'text-sky-700 dark:text-sky-400 bg-sky-50 dark:bg-sky-500/10 border-sky-200 dark:border-sky-500/20',
        ],
        'purple' => [
            'icon' => 'text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-500/10 border-purple-200 dark:border-purple-500/20',
            'badge' => 'text-purple-700 dark:text-purple-400 bg-purple-50 dark:bg-purple-500/10 border-purple-200 dark:border-purple-500/20',
        ],
        'slate' => [
            'icon' => 'text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700',
            'badge' => 'text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700',
        ],
    ];

    $scheme = $colorMap[$color] ?? $colorMap['emerald'];
    $badgeScheme = $badgeColor && isset($colorMap[$badgeColor]) ? $colorMap[$badgeColor]['badge'] : $scheme['badge'];

    $href = \App\Support\Features::allowsUrl($href) ? $href : null;
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href)
        href="{{ $href }}"
        wire:navigate
    @endif
    {{ $attributes->merge([
        'class' => 'group relative min-w-0 bg-slate-900/80 rounded-xl border border-slate-800/80 p-3 sm:px-4 sm:py-3 transition-all duration-200 flex flex-col justify-between ' .
                   ($href ? 'hover:border-slate-700/90 dark:hover:border-slate-700 cursor-pointer shadow-sm hover:shadow-md' : 'shadow-sm')
    ]) }}
>
    <div>
        {{-- Baris Atas: Label & Ikon --}}
        <div class="flex items-center justify-between gap-2">
            <span class="text-[11px] font-semibold text-slate-400 tracking-wide leading-tight truncate">
                {{ $title }}
            </span>
            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 border {{ $scheme['icon'] }} transition-transform group-hover:scale-105 duration-200">
                <i data-lucide="{{ $icon }}" class="w-3.5 h-3.5"></i>
            </div>
        </div>

        {{-- Baris Utama: Nilai Metrik --}}
        <div class="mt-1.5 flex items-baseline gap-2 flex-wrap">
            <span class="text-base sm:text-xl font-bold font-mono text-slate-100 tracking-tight tabular-nums min-w-0 break-words">
                {{ $value }}
            </span>

            @if ($badge)
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold border {{ $badgeScheme }}">
                    {{ $badge }}
                </span>
            @endif
        </div>
    </div>

    {{-- Baris Bawah: Subtitle / Keterangan Tambahan --}}
    @if ($subtitle || $href)
        <div class="mt-2 pt-1.5 border-t border-slate-800/40 dark:border-slate-800/60 flex items-center justify-between text-[11px] text-slate-400 leading-tight">
            <span class="truncate">{{ $subtitle ?? '' }}</span>
            @if ($href)
                <i data-lucide="arrow-up-right" class="w-3 h-3 text-slate-500 group-hover:text-emerald-400 transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 shrink-0 ml-1"></i>
            @endif
        </div>
    @endif
</{{ $tag }}>
