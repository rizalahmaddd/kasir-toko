@props([
    'title',
    'value',
    'icon',
    'tone' => 'slate',
    'subtitle' => null,
    'href' => null,
    'trend' => null,
    'trendLabel' => null,
])

{{-- Kartu angka dashboard: flat (DESIGN.md "Elevasi"), satu warna fungsional per kartu sesuai
     state nyata di angkanya, dan tautan ke halaman sumber angkanya kalau peran ini boleh membukanya. --}}
@php
    $iconTone = [
        'slate'   => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700/50',
        'emerald' => 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-500/20',
        'amber'   => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-500/20',
        'rose'    => 'bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-200 dark:border-rose-500/20',
        'sky'     => 'bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-200 dark:border-sky-500/20',
    ][$tone] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700/50';
    $tag = $href ? 'a' : 'div';
@endphp

{{-- Tautan ke fitur yang dimatikan di Pengaturan Fitur: seluruh elemen ikut disembunyikan. --}}
@if (\App\Support\Features::allowsUrl($href))
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->merge(['class' => 'group min-w-0 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-3.5 sm:p-5 block shadow-sm dark:shadow-none'.($href ? ' hover:border-slate-300 dark:hover:border-slate-700/80 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500' : '')]) }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-medium text-slate-500 dark:text-slate-400 leading-tight">{{ $title }}</span>
        <div class="w-8 h-8 rounded-lg flex items-center justify-center border shrink-0 {{ $iconTone }}">
            <i data-lucide="{{ $icon }}" class="w-4 h-4"></i>
        </div>
    </div>
    <div class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <span class="text-lg sm:text-2xl font-bold text-slate-900 dark:text-white tracking-tight tabular-nums break-words">{{ $value }}</span>
        <x-growth-badge :value="$trend" :title="$trendLabel" />
    </div>
    @if ($subtitle || $href)
        <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between gap-2">
            <span class="truncate">{{ $subtitle }}</span>
            @if ($href)
                <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 group-hover:text-slate-700 dark:group-hover:text-slate-300 shrink-0"></i>
            @endif
        </div>
    @endif
</{{ $tag }}>
@endif
