@props([
    'sortable' => false,
    'field' => null,
    'sortField' => null,
    'sortDirection' => 'asc',
    'align' => 'left',
])

@php
    $alignClass = match($align) {
        'right' => 'text-right justify-end',
        'center' => 'text-center justify-center',
        default => 'text-left justify-start',
    };
    $currentSortField = $sortField ?? ($this->sortField ?? null);
    $currentSortDirection = $sortDirection ?? ($this->sortDirection ?? 'asc');
    $isActive = $sortable && $field && $currentSortField === $field;
@endphp

<th {{ $attributes->merge(['class' => 'px-3 py-2 text-xs font-semibold tracking-wider uppercase text-slate-400 select-none']) }}>
    @if ($sortable && $field)
        <button
            type="button"
            wire:click="sortBy('{{ $field }}')"
            class="group inline-flex items-center gap-1.5 hover:text-slate-200 transition-colors {{ $alignClass }} w-full font-semibold cursor-pointer"
        >
            <span>{{ $slot }}</span>

            <span class="inline-flex items-center transition-colors">
                @if ($isActive)
                    @if ($currentSortDirection === 'asc')
                        <i data-lucide="arrow-up" class="w-3.5 h-3.5 text-emerald-400"></i>
                    @else
                        <i data-lucide="arrow-down" class="w-3.5 h-3.5 text-emerald-400"></i>
                    @endif
                @else
                    <i data-lucide="chevrons-up-down" class="w-3.5 h-3.5 text-slate-600 group-hover:text-slate-400"></i>
                @endif
            </span>
        </button>
    @else
        <div class="flex items-center {{ $alignClass }}">
            <span>{{ $slot }}</span>
        </div>
    @endif
</th>
