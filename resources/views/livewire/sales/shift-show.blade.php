@php use App\Support\NumberFormatter as Num; @endphp

<div class="space-y-4 sm:space-y-6">
    <a href="{{ route('shifts.index') }}" wire:navigate class="text-xs text-slate-400 hover:text-slate-200 inline-flex items-center gap-1.5 min-h-[44px]">
        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
        <span>Kembali ke daftar shift</span>
    </a>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-2.5">
                <h3 class="text-lg font-bold text-slate-100 font-mono">{{ $cashShift->number }}</h3>
                @if ($cashShift->isOpen())
                    <x-badge color="sky">BUKA</x-badge>
                @else
                    <x-badge>DITUTUP</x-badge>
                @endif
            </div>
            <p class="text-xs text-slate-400 mt-1">
                {{ $cashShift->user->name }}@if (app(\App\Support\CurrentOutlet::class)->isMultiOutlet()) · {{ $cashShift->outlet?->name }}@endif · dibuka {{ $cashShift->opened_at->translatedFormat('d M Y H:i') }}
                @if ($cashShift->closed_at)
                    · ditutup {{ $cashShift->closed_at->translatedFormat('d M Y H:i') }}{{ $cashShift->closer && $cashShift->closer->id !== $cashShift->user_id ? ' oleh '.$cashShift->closer->name : '' }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('shifts.print', $cashShift) }}" target="_blank" x-on:click.prevent="window.open($el.href, '_blank')" class="inline-flex items-center gap-2 min-h-[44px] px-4 rounded-lg bg-slate-800 border border-slate-700 text-slate-200 hover:bg-slate-700 text-xs font-semibold">
                <i data-lucide="printer" class="w-4 h-4"></i> Cetak Rekap
            </a>
            @if ($this->canOperate())
                <x-secondary-button @click="$dispatch('open-modal', 'cash-movement')">
                    <i data-lucide="arrow-left-right" class="w-4 h-4"></i> Kas Masuk/Keluar
                </x-secondary-button>
                <x-primary-button type="button" @click="$dispatch('open-modal', 'close-shift')">
                    <i data-lucide="lock" class="w-4 h-4"></i> Tutup Shift
                </x-primary-button>
            @endif
        </div>
    </div>

    @include('livewire.sales.partials.shift-summary', ['shift' => $cashShift, 'summary' => $summary])

    @if ($cashShift->closing_note)
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-4 text-xs text-slate-300">
            <span class="text-slate-400">Catatan tutup shift:</span> {{ $cashShift->closing_note }}
        </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-4 sm:gap-6 items-start">
        <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                <h4 class="text-sm font-bold text-slate-200">Kas masuk & keluar</h4>
                <span class="text-[11px] text-slate-400">{{ $cashShift->cashMovements->count() }} catatan</span>
            </div>
            @if ($cashShift->cashMovements->isEmpty())
                <x-empty-state icon="arrow-left-right" title="Belum ada catatan kas" description="Uang yang diambil atau ditambahkan ke laci di luar penjualan dicatat di sini." />
            @else
                <ul class="divide-y divide-slate-800/60">
                    @foreach ($cashShift->cashMovements as $movement)
                        <li class="px-4 py-2.5 flex items-center gap-3 text-xs">
                            <i data-lucide="{{ $movement->type->value === 'in' ? 'arrow-down-left' : 'arrow-up-right' }}" @class(['w-4 h-4 shrink-0', 'text-emerald-400' => $movement->type->value === 'in', 'text-rose-400' => $movement->type->value === 'out'])></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-slate-200 truncate">{{ $movement->reason }}</p>
                                <p class="text-[11px] text-slate-400">{{ $movement->created_at->format('H:i') }} · {{ $movement->user->name }}</p>
                            </div>
                            <span @class(['tabular-nums font-semibold', 'text-emerald-400' => $movement->type->value === 'in', 'text-rose-400' => $movement->type->value === 'out'])>{{ $movement->type->value === 'in' ? '+' : '-' }}{{ Num::currency($movement->amount) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                <h4 class="text-sm font-bold text-slate-200">Transaksi di shift ini</h4>
                <span class="text-[11px] text-slate-400">{{ $sales->count() >= 100 ? '100 terakhir' : $sales->count().' transaksi' }}</span>
            </div>
            @if ($sales->isEmpty())
                <x-empty-state icon="receipt" title="Belum ada transaksi" description="Transaksi yang dibayar selama shift ini buka akan muncul di sini." />
            @else
                <ul class="divide-y divide-slate-800/60 max-h-[28rem] overflow-y-auto custom-scrollbar">
                    @foreach ($sales as $sale)
                        <li>
                            <a href="{{ route('sales.show', $sale) }}" wire:navigate class="px-4 py-2.5 flex items-center gap-3 text-xs hover:bg-slate-800/40 min-h-[48px]">
                                <div class="flex-1 min-w-0">
                                    <p class="font-mono text-slate-200">{{ $sale->number }}</p>
                                    <p class="text-[11px] text-slate-400">{{ $sale->sold_at->format('H:i') }} · {{ $sale->customer?->name ?? 'Umum' }}</p>
                                </div>
                                @if ($sale->isVoided())
                                    <x-badge color="rose">BATAL</x-badge>
                                @elseif ($sale->due_amount > 0)
                                    <x-badge color="amber">KASBON</x-badge>
                                @endif
                                <span @class(['tabular-nums font-semibold', 'text-slate-100' => ! $sale->isVoided(), 'text-slate-500 line-through' => $sale->isVoided()])>{{ Num::currency($sale->total) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($this->canOperate())
        <x-modal name="close-shift" max-width="md" focusable>
            <form wire:submit="close" class="p-5 sm:p-6 space-y-4" x-data="{ counted: @entangle('countedCash'), expected: {{ $summary['expected'] }} }">
                <x-modal-header title="Tutup shift" icon="lock" closeable>
                    Hitung semua uang tunai di laci, lalu masukkan jumlahnya. Setelah ditutup, shift tidak bisa dipakai transaksi lagi.
                </x-modal-header>

                <div class="rounded-xl bg-slate-950 border border-slate-800 p-3 flex justify-between items-baseline">
                    <span class="text-xs text-slate-400">Seharusnya di laci</span>
                    <span class="text-lg font-bold text-slate-100 tabular-nums">{{ Num::currency($summary['expected']) }}</span>
                </div>

                <div>
                    <x-input-label for="countedCash" value="Uang fisik hasil hitung" />
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">Rp</span>
                        <x-text-input x-model="counted" id="countedCash" inputmode="numeric" class="w-full pl-10 text-lg font-bold tabular-nums" placeholder="0" />
                    </div>
                    <x-input-error :messages="$errors->get('countedCash')" class="mt-1.5" />
                    <template x-if="counted !== '' && counted !== null">
                        <p class="text-xs mt-2 font-semibold"
                            :class="Number(String(counted).replace(/\D/g, '')) === expected ? 'text-emerald-400' : (Number(String(counted).replace(/\D/g, '')) > expected ? 'text-amber-400' : 'text-rose-400')"
                            x-text="(() => { const diff = Number(String(counted).replace(/\D/g, '')) - expected; const fmt = new Intl.NumberFormat('id-ID').format(Math.abs(diff)); return diff === 0 ? 'Cocok, tidak ada selisih.' : (diff > 0 ? `Lebih Rp${fmt}` : `Kurang Rp${fmt}`); })()"></p>
                    </template>
                </div>

                <div>
                    <x-input-label for="closingNote" value="Catatan" />
                    <x-textarea wire:model="closingNote" id="closingNote" rows="2" placeholder="Wajib diisi kalau ada selisih, mis. kembalian salah hitung" />
                    <x-input-error :messages="$errors->get('closingNote')" class="mt-1.5" />
                </div>

                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="close" loading="Menutup...">Tutup Shift</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </x-modal>

        <x-modal name="cash-movement" max-width="md" focusable>
            <form wire:submit="recordCash" class="p-5 sm:p-6 space-y-4">
                <x-modal-header title="Kas masuk / keluar" icon="arrow-left-right" closeable>
                    Uang yang keluar atau masuk laci di luar penjualan.
                </x-modal-header>
                <x-segmented class="w-full [&>*]:flex-1">
                    <x-tab-button :active="$cashType === 'out'" wire:click="$set('cashType', 'out')" icon="arrow-up-right">Kas keluar</x-tab-button>
                    <x-tab-button :active="$cashType === 'in'" wire:click="$set('cashType', 'in')" icon="arrow-down-left">Kas masuk</x-tab-button>
                </x-segmented>
                <div>
                    <x-input-label for="cashAmount" value="Nominal" />
                    <x-text-input wire:model="cashAmount" id="cashAmount" inputmode="numeric" class="w-full font-bold tabular-nums" placeholder="0" />
                    <x-input-error :messages="$errors->get('cashAmount')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="cashReason" value="Keperluan" />
                    <x-text-input wire:model="cashReason" id="cashReason" class="w-full" placeholder="Mis. beli plastik, setor ke pemilik" />
                    <x-input-error :messages="$errors->get('cashReason')" class="mt-1.5" />
                </div>
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="recordCash" loading="Menyimpan...">Simpan Catatan Kas</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </x-modal>
    @endif
</div>
