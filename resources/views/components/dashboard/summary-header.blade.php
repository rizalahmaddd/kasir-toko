@props(['title', 'description'])

<div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800/80">
    <div class="min-w-0">
        <h1 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-slate-100">{{ $title }}</h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">{{ $description }}</p>
    </div>
    <p class="text-[11px] text-slate-500 dark:text-slate-400 shrink-0 tabular-nums">
        {{ __('Data per :time', ['time' => now()->translatedFormat('d M Y, H:i')]) }}
    </p>
</div>
