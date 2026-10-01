@props(['activities'])

{{-- Jejak audit terbaru; pemanggil hanya memasangnya untuk akun yang lolos ActivityPolicy. --}}
<x-dashboard.panel :title="__('Aktivitas Terbaru')" icon="history" :href="route('reports.activity-log')" {{ $attributes }}>
    @if ($activities->isEmpty())
        <x-dashboard.empty icon="history" :message="__('Belum ada aktivitas tercatat.')" />
    @else
        <ul class="text-xs divide-y divide-slate-200 dark:divide-slate-800/80">
            @foreach ($activities as $activity)
                <li class="py-3 flex items-start justify-between gap-3" wire:key="activity-{{ $activity->id }}">
                    <div class="min-w-0 space-y-1">
                        <p class="text-slate-800 dark:text-slate-200 font-medium leading-relaxed">{{ \App\Support\NumberFormatter::narrative($activity->description) }}</p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            {{ $activity->causer?->name ?? __('Sistem') }} &middot; {{ $activity->created_at->diffForHumans() }}
                        </p>
                    </div>
                    <x-badge color="slate" class="shrink-0 mt-0.5">
                        {{ \App\Livewire\Reports\ActivityLogReport::LOG_NAMES[$activity->log_name] ?? $activity->log_name }}
                    </x-badge>
                </li>
            @endforeach
        </ul>
    @endif
</x-dashboard.panel>
