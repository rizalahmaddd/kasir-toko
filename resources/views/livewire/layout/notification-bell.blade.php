<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <button
        @click="open = ! open"
        aria-label="{{ __('Notifikasi') }}"
        class="relative inline-flex items-center justify-center min-w-[44px] min-h-[44px] text-slate-400 hover:text-slate-200 transition"
    >
        <i data-lucide="bell" class="w-5 h-5"></i>
        @if ($unreadCount > 0)
            <span class="absolute top-1.5 right-1.5 min-w-[16px] h-4 px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        class="fixed inset-x-3 top-[calc(3.5rem+env(safe-area-inset-top))] sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-80 rounded-lg shadow-xl bg-slate-900 border border-slate-800 overflow-hidden z-50"
        style="display: none;"
    >
        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-800">
            <span class="text-xs font-bold text-slate-200">{{ __('Notifikasi') }}</span>
            @if ($unreadCount > 0)
                <button wire:click="markAllAsRead" class="text-[11px] text-emerald-400 hover:text-emerald-300 font-semibold">
                    {{ __('Tandai semua dibaca') }}
                </button>
            @endif
        </div>

        <div class="max-h-80 overflow-y-auto divide-y divide-slate-800/60">
            @forelse ($notifications as $notification)
                <button
                    wire:click="open('{{ $notification->id }}')"
                    aria-label="{{ __('Buka:') }} {{ \App\Support\NumberFormatter::narrative($notification->data['message'] ?? '') }}"
                    class="w-full text-left px-4 py-3 flex items-start gap-2.5 hover:bg-slate-800/60 transition {{ is_null($notification->read_at) ? '' : 'opacity-60' }}"
                >
                    <span class="mt-0.5 shrink-0 w-2 h-2 rounded-full {{ is_null($notification->read_at) ? 'bg-emerald-400' : 'bg-transparent' }}"></span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs text-slate-200 leading-snug">{{ \App\Support\NumberFormatter::narrative($notification->data['message'] ?? '-') }}</span>
                        <span class="block text-[10px] text-slate-400 mt-1">{{ $notification->created_at->diffForHumans() }}</span>
                    </span>
                    @if ($notification->data['url'] ?? null)
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-400 shrink-0 mt-1"></i>
                    @endif
                </button>
            @empty
                <div class="px-4 py-6 text-center">
                    <p class="text-xs text-slate-400">{{ __('Belum ada notifikasi.') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
