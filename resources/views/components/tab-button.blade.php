@props(['active' => false, 'icon' => null, 'count' => null, 'size' => 'md'])

<button
    type="button"
    @unless ($attributes->has('role')) aria-pressed="{{ $active ? 'true' : 'false' }}" @endunless
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-2 min-h-[44px] rounded-lg border text-xs font-semibold whitespace-nowrap transition cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40',
        'px-4 py-2' => $size === 'md',
        'sm:min-h-[32px] px-3 py-1' => $size === 'sm',
        'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30' => $active,
        'border-transparent text-slate-400 hover:text-slate-200 hover:bg-slate-800/60' => ! $active,
    ]) }}
>
    @if ($icon)
        <i data-lucide="{{ $icon }}" aria-hidden="true" class="w-4 h-4 shrink-0"></i>
    @endif
    <span>{{ $slot }}</span>
    @if ($count !== null)
        <span @class([
            'px-1.5 rounded-full text-[10px] font-mono',
            'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300' => $active,
            'bg-slate-800 text-slate-400' => ! $active,
        ])>{{ $count }}</span>
    @endif
</button>
