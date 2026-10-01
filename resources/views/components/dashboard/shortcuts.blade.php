@props(['links'])

{{-- Pintasan ke halaman kerja harian peran ini. $links: [['label', 'icon', 'href', 'hint'], ...];
     pemanggil hanya mengirim tautan yang lolos policy halamannya. --}}
@php
    $links = array_values(array_filter($links, fn (array $link) => \App\Support\Features::allowsUrl($link['href'])));
@endphp

@if (count($links) > 0)
    <nav aria-label="{{ __('Pintasan') }}" class="grid grid-cols-1 gap-3 {{ [1 => '', 2 => 'sm:grid-cols-2'][count($links)] ?? 'sm:grid-cols-3' }}">
        @foreach ($links as $link)
            <a href="{{ $link['href'] }}" wire:navigate
                class="group bg-white dark:bg-slate-900 hover:bg-slate-800/60 px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 transition-colors flex items-center gap-3 min-h-[44px] shadow-sm dark:shadow-none focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                <div class="p-2 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700/50 shrink-0">
                    <i data-lucide="{{ $link['icon'] }}" class="w-4 h-4"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-semibold text-slate-700 dark:text-slate-200 group-hover:text-slate-900 dark:group-hover:text-white">{{ $link['label'] }}</div>
                    @if (! empty($link['hint']))
                        <div class="text-[11px] text-slate-500 dark:text-slate-400 truncate">{{ $link['hint'] }}</div>
                    @endif
                </div>
                <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400 dark:text-slate-500 group-hover:text-slate-600 dark:group-hover:text-slate-300 shrink-0"></i>
            </a>
        @endforeach
    </nav>
@endif
