<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" x-effect="if (open) { $nextTick(() => window.lucide?.createIcons()) }">
    <button
        @click="open = ! open"
        aria-label="{{ __('Notifikasi') }}"
        class="relative inline-flex items-center justify-center min-w-[44px] min-h-[44px] text-slate-400 hover:text-slate-200 transition"
    >
        <i data-lucide="bell" class="w-5 h-5"></i>
        @if ($unreadCount > 0)
            <span class="absolute top-1.5 right-1.5 min-w-[16px] h-4 px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center shadow-sm">
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
        class="fixed inset-x-3 top-[calc(3.5rem+env(safe-area-inset-top))] sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-96 rounded-xl shadow-2xl bg-slate-900 border border-slate-800 overflow-hidden z-50 divide-y divide-slate-800/80"
        style="display: none;"
    >
        <div class="flex items-center justify-between px-4 py-3 bg-slate-900/90 backdrop-blur">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-slate-200">{{ __('Notifikasi') }}</span>
                @if ($unreadCount > 0)
                    <span class="px-1.5 py-0.5 rounded-full bg-rose-500/20 text-rose-400 text-[10px] font-semibold">
                        {{ $unreadCount }} {{ __('baru') }}
                    </span>
                @endif
            </div>
            @if ($unreadCount > 0)
                <button wire:click="markAllAsRead" class="text-[11px] text-emerald-400 hover:text-emerald-300 font-semibold transition">
                    {{ __('Tandai semua dibaca') }}
                </button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto divide-y divide-slate-800/60">
            @forelse ($notifications as $notification)
                @php
                    $icon = $notification->data['icon'] ?? 'bell';
                    $color = $notification->data['color'] ?? 'slate';
                    $badgeStyle = match ($color) {
                        'emerald' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
                        'rose' => 'bg-rose-500/15 text-rose-400 border-rose-500/20',
                        'amber' => 'bg-amber-500/15 text-amber-400 border-amber-500/20',
                        'sky' => 'bg-sky-500/15 text-sky-400 border-sky-500/20',
                        'indigo' => 'bg-indigo-500/15 text-indigo-400 border-indigo-500/20',
                        default => 'bg-slate-800 text-slate-300 border-slate-700',
                    };
                    $isUnread = is_null($notification->read_at);
                @endphp
                <button
                    wire:click="open('{{ $notification->id }}')"
                    aria-label="{{ __('Buka:') }} {{ \App\Support\NumberFormatter::narrative($notification->data['message'] ?? '') }}"
                    class="w-full text-left px-4 py-3 flex items-start gap-3 hover:bg-slate-800/60 transition {{ $isUnread ? 'bg-slate-800/20' : 'opacity-65' }}"
                >
                    <div class="mt-0.5 shrink-0 relative">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center border {{ $badgeStyle }}">
                            <i data-lucide="{{ $icon }}" class="w-4 h-4"></i>
                        </div>
                        @if ($isUnread)
                            <span class="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-emerald-400 ring-2 ring-slate-900"></span>
                        @endif
                    </div>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs text-slate-200 leading-snug {{ $isUnread ? 'font-medium' : '' }}">{{ \App\Support\NumberFormatter::narrative($notification->data['message'] ?? '-') }}</span>
                        <span class="block text-[10px] text-slate-400 mt-1">{{ $notification->created_at->diffForHumans() }}</span>
                    </span>
                    @if ($notification->data['url'] ?? null)
                        <i data-lucide="chevron-right" class="w-4 h-4 text-slate-500 shrink-0 mt-2"></i>
                    @endif
                </button>
            @empty
                <div class="px-4 py-8 text-center">
                    <div class="w-10 h-10 rounded-full bg-slate-800/80 border border-slate-700 flex items-center justify-center mx-auto mb-2 text-slate-400">
                        <i data-lucide="bell-off" class="w-5 h-5"></i>
                    </div>
                    <p class="text-xs text-slate-400">{{ __('Belum ada notifikasi.') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
