@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-xs font-medium text-emerald-400 flex items-center gap-2.5 shadow-sm']) }}>
        <svg class="w-4 h-4 shrink-0 text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span>{{ $status }}</span>
    </div>
@endif
