@php
    use App\Enums\OrderType;
    use App\Support\NumberFormatter as Num;
@endphp

<div class="space-y-4 sm:space-y-6" wire:poll.30s
    x-data="{
        soundEnabled: localStorage.getItem('kds_sound') !== 'false',
        lastPendingCount: {{ $pendingCount }},
        toggleSound() {
            this.soundEnabled = !this.soundEnabled;
            localStorage.setItem('kds_sound', this.soundEnabled);
            if (this.soundEnabled) this.playChime();
        },
        playChime() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const now = ctx.currentTime;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.setValueAtTime(587.33, now);
                osc.frequency.setValueAtTime(880, now + 0.15);
                gain.gain.setValueAtTime(0.2, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.6);
                osc.start(now);
                osc.stop(now + 0.6);
            } catch (e) {}
        },
        checkNewTickets(currentCount) {
            if (currentCount > this.lastPendingCount && this.soundEnabled) {
                this.playChime();
            }
            this.lastPendingCount = currentCount;
        }
    }"
    x-init="$watch('$wire.pendingCount', val => checkNewTickets(val))">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 flex-wrap">
            <x-segmented>
                <x-tab-button size="sm" :active="$tab !== 'done'" wire:click="$set('tab', 'pending')">Menunggu ({{ $pendingCount }})</x-tab-button>
                <x-tab-button size="sm" :active="$tab === 'done'" wire:click="$set('tab', 'done')">Selesai hari ini</x-tab-button>
            </x-segmented>
            <button type="button" @click="toggleSound()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 bg-slate-800 text-xs font-semibold text-slate-300 hover:text-white transition cursor-pointer">
                <i :data-lucide="soundEnabled ? 'volume-2' : 'volume-x'" class="w-3.5 h-3.5 text-emerald-400"></i>
                <span x-text="soundEnabled ? 'Suara Aktif' : 'Suara Senyap'"></span>
            </button>
        </div>
        <p class="text-xs text-slate-400">Pesanan baru dari kasir muncul otomatis. Terlama di kiri atas.</p>
    </div>

    @if ($this->tickets->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state icon="chef-hat" :title="$tab === 'done' ? 'Belum ada pesanan selesai hari ini' : 'Tidak ada pesanan menunggu'" description="Tiket dibuat saat kasir menunda pesanan (open bill) atau menyelesaikan pembayaran dengan tipe pesanan." />
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-3 sm:gap-4">
            @foreach ($this->tickets as $ticket)
                @php
                    $type = OrderType::tryFrom((string) $ticket->order_type);
                    $minutes = (int) $ticket->created_at->diffInMinutes(now());
                @endphp
                <article wire:key="ticket-{{ $ticket->id }}" @class([
                    'rounded-xl border bg-slate-900/80 flex flex-col',
                    'border-amber-500/40' => ! $ticket->isDone() && $minutes >= 15,
                    'border-slate-800' => $ticket->isDone() || $minutes < 15,
                ])>
                    <header class="flex items-start justify-between gap-3 px-4 pt-3.5 pb-2.5 border-b border-slate-800/80">
                        <div class="min-w-0">
                            <h3 class="text-lg font-extrabold text-slate-50 leading-tight truncate">{{ $ticket->label }}</h3>
                            <p class="text-[11px] text-slate-400 mt-0.5">
                                {{ $ticket->created_at->format('H:i') }} · {{ $ticket->user?->name ?? '-' }}{{ $ticket->sale ? ' · '.$ticket->sale->number : '' }}
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            @if ($type)
                                <x-badge :color="$type === OrderType::DineIn ? 'sky' : 'slate'">{{ strtoupper($type->shortLabel()) }}</x-badge>
                            @endif
                            @unless ($ticket->isDone())
                                <span @class(['text-[11px] font-semibold tabular-nums', 'text-amber-400' => $minutes >= 15, 'text-slate-400' => $minutes < 15])>{{ $minutes }} mnt</span>
                            @endunless
                        </div>
                    </header>

                    <ul class="flex-1 px-4 py-3 space-y-2">
                        @foreach ($ticket->items as $item)
                            <li>
                                <p class="text-[15px] font-extrabold text-slate-100 leading-snug"><span class="tabular-nums text-emerald-400">{{ Num::quantity($item['quantity']) }}×</span> {{ $item['name'] }}</p>
                                @if (! empty($item['modifiers']))
                                    <p class="text-xs sm:text-sm font-semibold text-sky-300 leading-snug mt-0.5">{{ implode(', ', $item['modifiers']) }}</p>
                                @endif
                                @if (! empty($item['note']))
                                    <p class="text-xs sm:text-sm font-bold text-amber-300 mt-1 flex items-center gap-1.5"><i data-lucide="message-square" class="w-3.5 h-3.5 shrink-0 text-amber-400"></i> {{ $item['note'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <footer class="flex items-center gap-2 px-4 pb-3.5">
                        @if ($ticket->isDone())
                            <x-secondary-button size="sm" class="flex-1" wire:click="reopen({{ $ticket->id }})">Kembalikan ke Antrean</x-secondary-button>
                        @else
                            <x-primary-button size="sm" class="flex-1" wire:click="markDone({{ $ticket->id }})">
                                <i data-lucide="check" class="w-4 h-4"></i>
                                <span>Tandai Selesai</span>
                            </x-primary-button>
                        @endif
                        <x-icon-button icon="printer" label="Cetak tiket" x-on:click="window.open('{{ route('print.kitchen-ticket', $ticket) }}?print=1', '_blank')" />
                    </footer>
                </article>
            @endforeach
        </div>
    @endif
</div>
