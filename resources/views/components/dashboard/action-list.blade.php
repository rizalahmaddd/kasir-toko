@props([
    'items',
    'title' => null,
    'emptyMessage' => null,
])

{{-- Antrean "perlu tindakan" di ringkasan modul. $items: [['label', 'count', 'href', 'tone' => amber|rose|sky, 'hint' => ?], ...];
     item bernilai nol tidak ditampilkan, dan tautan ke fitur yang dimatikan hanya tampil sebagai teks. --}}
@php
    $items = collect($items)->filter(fn (array $item) => ($item['count'] ?? 0) > 0)->values();
    $total = $items->sum('count');
    $toneClass = [
        'rose' => 'bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-200 dark:border-rose-500/20',
        'amber' => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-500/20',
        'sky' => 'bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-200 dark:border-sky-500/20',
    ];
@endphp

<x-dashboard.panel :title="$title ?? __('Perlu Tindakan')" icon="list-checks" :tone="$items->contains('tone', 'rose') ? 'rose' : ($total > 0 ? 'amber' : 'slate')"
    :subtitle="__('Dokumen yang menunggu keputusan atau tindak lanjut')" :count="$total > 0 ? (string) $total : null" {{ $attributes }}>
    @if ($items->isEmpty())
        <x-dashboard.empty icon="check-circle" :message="$emptyMessage ?? __('Tidak ada yang perlu ditindaklanjuti saat ini.')" />
    @else
        <ul class="divide-y divide-slate-200 dark:divide-slate-800/80">
            @foreach ($items as $item)
                <li wire:key="action-{{ \Illuminate\Support\Str::slug($item['label']) }}">
                    <x-feature-link :href="$item['href']" wire:navigate
                        class="-mx-2 px-2 py-2.5 min-h-[44px] rounded-lg flex items-center justify-between gap-3 text-xs hover:bg-slate-800/60 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                        <span class="min-w-0">
                            <span class="block font-semibold text-slate-800 dark:text-slate-200">{{ $item['label'] }}</span>
                            @if (! empty($item['hint']))
                                <span class="block text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ $item['hint'] }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 min-w-[2rem] px-2 py-1 rounded-md border text-center font-bold tabular-nums {{ $toneClass[$item['tone'] ?? 'amber'] ?? $toneClass['amber'] }}">{{ $item['count'] }}</span>
                    </x-feature-link>
                </li>
            @endforeach
        </ul>
    @endif
</x-dashboard.panel>
