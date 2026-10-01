@props([
    'title',
    'maxWidth' => 'lg',
    'icon' => null,
    'subtitle' => null,
    'name' => 'record-form',
    'closeAction' => 'closeModal'
])

<x-modal :name="$name" :show="false" :max-width="$maxWidth">
    {{-- Header --}}
    <div class="px-4 sm:px-8 py-3 sm:py-6 border-b border-slate-800/80 flex items-center justify-between gap-4 bg-slate-900/95 shrink-0">
        <div class="flex items-center gap-3.5 min-w-0">
            @if ($icon)
                <div class="hidden sm:flex w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 items-center justify-center shrink-0 shadow-sm">
                    <i data-lucide="{{ $icon }}" class="w-5 h-5"></i>
                </div>
            @endif
            <div class="min-w-0">
                <h3 class="font-bold text-base sm:text-lg text-slate-100 tracking-tight leading-snug truncate">{{ $title }}</h3>
                @if ($subtitle)
                    <p class="hidden sm:block text-sm text-slate-400 mt-1 leading-normal truncate">{{ $subtitle }}</p>
                @endif
            </div>
        </div>

        <button
            type="button"
            @click="$dispatch('close')"
            wire:click="{{ $closeAction }}"
            aria-label="{{ __('Tutup') }}"
            class="inline-flex items-center justify-center w-11 h-11 -me-2 sm:me-0 sm:w-10 sm:h-10 rounded-xl text-slate-400 hover:text-slate-100 hover:bg-slate-800/80 border border-transparent hover:border-slate-700/80 transition-all focus:outline-none focus:ring-2 focus:ring-emerald-500/40 shrink-0"
        >
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>

    {{-- Body --}}
    <div class="overflow-y-auto overscroll-contain custom-scrollbar p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:p-8 flex-1">
        {{ $slot }}
    </div>
</x-modal>
