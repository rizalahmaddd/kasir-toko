@props([
    'title',
    'icon',
    'tone' => 'slate',
    'subtitle' => null,
    'count' => null,
    'href' => null,
    'linkLabel' => 'Lihat semua',
])

{{-- Wadah daftar di dashboard peran. $count hanya diisi kalau jumlahnya bermakna (mis. "3 pelanggan"). --}}
@php
    $iconTone = [
        'slate'   => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700/50',
        'emerald' => 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-500/20',
        'amber'   => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-500/20',
        'rose'    => 'bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-200 dark:border-rose-500/20',
        'sky'     => 'bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-200 dark:border-sky-500/20',
    ][$tone] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700/50';
@endphp

@if (\App\Support\Features::allowsUrl($href))
<section {{ $attributes->merge(['class' => 'min-w-0 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 sm:p-5 space-y-3 shadow-sm dark:shadow-none']) }}>
    <div class="flex items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800/80">
        <div class="flex items-center gap-2.5 min-w-0">
            <div class="p-1.5 rounded-lg border shrink-0 {{ $iconTone }}">
                <i data-lucide="{{ $icon }}" class="w-4 h-4"></i>
            </div>
            <div class="min-w-0">
                <h3 class="font-bold text-slate-900 dark:text-slate-100 text-sm tracking-tight">{{ $title }}</h3>
                @if ($subtitle)
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($count !== null)
                <span class="px-2 py-0.5 rounded-md text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700/60 tabular-nums">{{ $count }}</span>
            @endif
            @if ($href)
                <a href="{{ $href }}" wire:navigate class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition min-h-[44px] sm:min-h-0 inline-flex items-center">{{ $linkLabel }}</a>
            @endif
        </div>
    </div>
    {{ $slot }}
</section>
@endif
