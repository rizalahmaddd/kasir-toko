<div>
    @if ($choices->count() > 1)
        @php $active = $choices->firstWhere('id', $currentId) ?? $choices->first(); @endphp
        <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" x-effect="if (open) { $nextTick(() => window.lucide?.createIcons()) }">
            <button type="button" @click="open = ! open" aria-haspopup="listbox" :aria-expanded="open" aria-label="{{ __('Ganti outlet') }}"
                class="inline-flex items-center gap-2 min-h-[44px] sm:min-h-[36px] max-w-[11rem] px-2.5 rounded-lg border border-slate-800 bg-slate-900 text-xs font-semibold text-slate-200 hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40">
                <i data-lucide="store" class="w-4 h-4 shrink-0 text-slate-400"></i>
                <span class="truncate">{{ $active->name }}</span>
                <i data-lucide="chevron-down" class="w-3.5 h-3.5 shrink-0 text-slate-400"></i>
            </button>

            <div x-show="open" x-transition.opacity.duration.100ms style="display: none;"
                class="absolute right-0 mt-2 w-64 max-w-[calc(100vw-1.5rem)] rounded-xl border border-slate-800 bg-slate-900 shadow-xl z-50 overflow-hidden" role="listbox">
                <p class="px-3 py-2 text-[11px] font-semibold text-slate-400 border-b border-slate-800">{{ __('Outlet yang sedang dipakai') }}</p>
                <ul class="max-h-72 overflow-y-auto py-1">
                    @foreach ($choices as $choice)
                        @php $locked = ! in_array($choice->id, $operationalIds, true); @endphp
                        <li wire:key="switch-{{ $choice->id }}">
                            <button type="button" wire:click="switchTo({{ $choice->id }})" role="option" aria-selected="{{ $choice->id === $active->id ? 'true' : 'false' }}"
                                class="w-full min-h-[44px] px-3 py-2 flex items-center gap-2 text-left text-xs hover:bg-slate-800/70 {{ $choice->id === $active->id ? 'text-emerald-400 font-semibold' : 'text-slate-200' }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ $choice->name }}</span>
                                    <span class="block font-mono text-[10px] text-slate-400">{{ $choice->code }}</span>
                                </span>
                                @if ($locked)
                                    <x-badge color="amber">TERKUNCI</x-badge>
                                @elseif ($choice->id === $active->id)
                                    <i data-lucide="check" class="w-4 h-4 shrink-0"></i>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
