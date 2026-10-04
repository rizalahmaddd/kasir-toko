@props([
    'disabled' => false,
    'searchable' => null,
    'variant' => 'form',
    'searchPlaceholder' => 'Cari…',
])

@php
    /**
     * searchable: null means "decide from the options" — on when there are more than 6 real
     * options (the empty-value placeholder does not count). Pass true/false to force it.
     */
    $slotHtml = (string) $slot;
    $realOptionCount = preg_match_all('/<option\b(?![^>]*\bvalue\s*=\s*(?:""|\'\'))/i', $slotHtml);
    $isSearchable = $searchable ?? $realOptionCount > 6;

    $controlClasses = match ($variant) {
        'filter' => 'h-11 sm:h-[38px] bg-slate-900 border border-slate-800 text-slate-300 text-xs rounded-lg pl-3 pr-9 py-2 focus:outline-none focus:border-emerald-500 disabled:opacity-50 cursor-pointer',
        'inset' => 'min-h-[40px] sm:min-h-[36px] bg-slate-900 border-0 text-slate-200 text-xs rounded-md pl-2.5 pr-8 py-1.5 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:opacity-50 cursor-pointer',
        'compact' => 'h-11 sm:h-8 bg-slate-900 border border-slate-800 text-slate-200 text-xs rounded-lg pl-2.5 pr-8 py-1 focus:outline-none focus:border-emerald-500 disabled:opacity-50 cursor-pointer',
        default => 'w-full min-h-[44px] sm:min-h-0 bg-slate-950 border border-slate-800 rounded-lg pl-3 pr-9 py-2 text-sm text-slate-100 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 disabled:opacity-50 disabled:bg-slate-900/50 transition cursor-pointer',
    };

    $chevronOffset = in_array($variant, ['inset', 'compact'], true) ? 'right-2.5' : 'right-3';
@endphp

@if (! $isSearchable)
    <select @disabled($disabled) {{ $attributes->merge(['class' => $controlClasses]) }}>
        {{ $slot }}
    </select>
@else
    @php
        $triggerAttributeNames = ['id', 'class', 'title', 'aria-describedby', 'autofocus'];
        $triggerAttributes = $attributes->only($triggerAttributeNames);
        $selectAttributes = $attributes->except([...$triggerAttributeNames, 'aria-label']);
        $accessibleName = $attributes->get('aria-label');

        preg_match('/<option\b[^>]*>(.*?)<\/option>/is', $slotHtml, $firstOption);
        $initialLabel = trim(html_entity_decode(strip_tags($firstOption[1] ?? ''), ENT_QUOTES));
    @endphp

    <div class="contents" x-data="searchableSelect({ model: @js($attributes->wire('model')->value() ?: null) })">
        {{-- The native select stays the bound value; the button and panel below only drive it. --}}
        <select x-ref="select" tabindex="-1" aria-hidden="true" class="sr-only" @disabled($disabled) {{ $selectAttributes }}>
            {{ $slot }}
        </select>

        <button
            type="button"
            x-ref="trigger"
            @click="toggle()"
            @keydown="onTriggerKeydown($event)"
            :disabled="disabled"
            @disabled($disabled)
            aria-haspopup="listbox"
            :aria-expanded="open"
            {{ $triggerAttributes->merge(['class' => 'select-trigger relative flex items-center text-left min-w-0 sm:min-w-[10rem] '.$controlClasses]) }}
        >
            @if ($accessibleName)
                <span class="sr-only">{{ $accessibleName }}:</span>
            @endif
            <span class="block truncate" :class="isPlaceholder && 'text-slate-400'" x-text="selectedLabel || ' '">{{ $initialLabel ?: "\u{00A0}" }}</span>
            <svg aria-hidden="true" class="pointer-events-none absolute {{ $chevronOffset }} top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </button>

        <template x-teleport="body">
            <div
                x-ref="panel"
                x-show="open"
                x-transition.opacity.duration.100ms
                :style="panelStyle"
                @click.outside="closeOnOutsideClick($event)"
                class="z-[70] overflow-hidden rounded-lg border border-slate-700 bg-slate-900 shadow-xl shadow-slate-950/40"
                style="display: none;"
            >
                <div class="flex items-center gap-2 px-3 border-b border-slate-800">
                    <svg aria-hidden="true" class="w-4 h-4 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                    <input
                        x-ref="search"
                        x-model="query"
                        @input="onQueryChanged()"
                        @keydown="onSearchKeydown($event)"
                        type="text"
                        role="combobox"
                        autocomplete="off"
                        spellcheck="false"
                        aria-autocomplete="list"
                        :aria-expanded="open"
                        :aria-controls="`select-${uid}-listbox`"
                        :aria-activedescendant="activeIndex >= 0 ? optionId(activeIndex) : null"
                        aria-label="{{ $searchPlaceholder }}"
                        placeholder="{{ $searchPlaceholder }}"
                        class="input-bare flex-1 min-w-0 h-11 p-0 bg-transparent border-0 text-sm text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                    >
                </div>

                <ul
                    :id="`select-${uid}-listbox`"
                    role="listbox"
                    class="py-1 overflow-y-auto overscroll-contain custom-scrollbar"
                    :style="{ maxHeight: `${listMaxHeight}px` }"
                >
                    <template x-for="option in filtered" :key="`${option.index}|${option.value}`">
                        <li role="presentation">
                            <div x-show="option.groupStart" x-text="option.group" aria-hidden="true" class="px-3 pt-2.5 pb-1 text-[11px] font-semibold text-slate-400"></div>
                            <div
                                role="option"
                                :id="optionId(option.index)"
                                :aria-selected="option.value === selectedValue"
                                :aria-disabled="option.disabled"
                                :data-active="option.index === activeIndex"
                                @click="choose(option)"
                                @mousemove="option.disabled || (activeIndex = option.index)"
                                class="flex items-center gap-2 px-3 py-2 min-h-[44px] sm:min-h-[36px] text-sm"
                                :class="{
                                    'bg-slate-800 text-slate-100': option.index === activeIndex,
                                    'text-emerald-400 font-medium': option.value === selectedValue && option.index !== activeIndex,
                                    'text-slate-200': option.value !== selectedValue && option.index !== activeIndex,
                                    'opacity-50 cursor-not-allowed': option.disabled,
                                    'cursor-pointer': ! option.disabled,
                                }"
                            >
                                <span class="flex-1 truncate" x-text="option.label"></span>
                                <svg x-show="option.value === selectedValue" aria-hidden="true" class="w-4 h-4 shrink-0 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                            </div>
                        </li>
                    </template>

                    <li x-show="filtered.length === 0" class="px-3 py-3 text-sm text-slate-400">
                        Tidak ada opsi yang cocok dengan “<span class="text-slate-200" x-text="query"></span>”.
                    </li>
                </ul>
            </div>
        </template>
    </div>
@endif
