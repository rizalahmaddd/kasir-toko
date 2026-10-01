@php use App\Support\NumberFormatter as Num; @endphp

<div class="space-y-4 sm:space-y-6">
    @if ($shift = $this->myShift)
        <div class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-slate-100">Shift Anda sedang buka</h3>
                    <p class="text-xs text-slate-400"><span class="font-mono">{{ $shift->number }}</span> · sejak {{ $shift->opened_at->translatedFormat('d M Y H:i') }} ({{ $shift->opened_at->diffForHumans(short: true) }})</p>
                </div>
                <div class="flex gap-2">
                    <x-feature-link :href="route('pos.cashier')" wire:navigate hide-when-disabled class="inline-flex items-center gap-2 min-h-[44px] px-4 rounded-lg bg-slate-800 border border-slate-700 text-slate-200 hover:bg-slate-700 text-xs font-semibold">
                        <i data-lucide="shopping-cart" class="w-4 h-4"></i> Ke Kasir
                    </x-feature-link>
                    <a href="{{ route('shifts.show', $shift) }}" wire:navigate class="inline-flex items-center gap-2 min-h-[44px] px-4 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-slate-950 text-xs font-bold">
                        <i data-lucide="lock" class="w-4 h-4"></i> Rekap & Tutup Shift
                    </a>
                </div>
            </div>
            @include('livewire.sales.partials.shift-summary', ['shift' => $shift, 'summary' => $summary])
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
            <span class="w-11 h-11 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center shrink-0">
                <i data-lucide="wallet" class="w-5 h-5"></i>
            </span>
            <div class="flex-1">
                <h3 class="text-sm font-bold text-slate-100">Anda belum membuka shift</h3>
                <p class="text-xs text-slate-400 mt-0.5">Shift mencatat modal awal laci dan semua uang yang masuk/keluar sampai ditutup, supaya selisih uang bisa dilacak.</p>
            </div>
            <x-primary-button type="button" @click="$dispatch('open-modal', 'open-shift')">
                <i data-lucide="unlock" class="w-4 h-4"></i> Buka Shift
            </x-primary-button>
        </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2">
        <h3 class="text-sm font-bold text-slate-200">{{ $this->canManageAll() ? 'Riwayat shift semua kasir' : 'Riwayat shift Anda' }}</h3>
        <div class="flex flex-wrap gap-2">
            @if ($cashiers->count() > 1)
                <x-select variant="filter" wire:model.live="cashier" aria-label="Filter kasir">
                    <option value="">Semua kasir</option>
                    @foreach ($cashiers as $cashierOption)
                        <option value="{{ $cashierOption->id }}">{{ $cashierOption->name }}</option>
                    @endforeach
                </x-select>
            @endif
            <x-select variant="filter" wire:model.live="status" aria-label="Filter status shift">
                <option value="">Semua shift</option>
                <option value="open">Masih buka</option>
                <option value="closed">Sudah ditutup</option>
                <option value="variance">Ada selisih uang</option>
            </x-select>
        </div>
    </div>

    @if ($shifts->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
            <x-empty-state icon="wallet" title="Belum ada shift" description="Shift pertama dibuka dari layar kasir atau tombol Buka Shift di atas." />
        </div>
    @else
        <x-table :pagination="$shifts">
            <x-slot:header>
                <tr>
                    <x-table.th>Shift</x-table.th>
                    <x-table.th>Kasir</x-table.th>
                    <x-table.th align="right">Penjualan</x-table.th>
                    <x-table.th align="right">Selisih Laci</x-table.th>
                    <x-table.th>Status</x-table.th>
                    <x-table.th align="right"><span class="sr-only">Aksi</span></x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($shifts as $row)
                    <x-table.tr wire:key="shift-{{ $row->id }}">
                        <x-table.td>
                            <a href="{{ route('shifts.show', $row) }}" wire:navigate class="font-mono font-semibold text-emerald-400 hover:text-emerald-300">{{ $row->number }}</a>
                            <div class="text-[11px] text-slate-400">{{ $row->opened_at->translatedFormat('d M H:i') }} – {{ $row->closed_at?->translatedFormat($row->closed_at->isSameDay($row->opened_at) ? 'H:i' : 'd M H:i') ?? 'sekarang' }}</div>
                        </x-table.td>
                        <x-table.td class="text-slate-300">{{ $row->user->name }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums">
                            <div class="font-semibold text-slate-100">{{ Num::currency($row->sales_total ?? 0) }}</div>
                            <div class="text-[11px] text-slate-400">{{ $row->sales_count }} transaksi</div>
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums">
                            @if ($row->isOpen())
                                <span class="text-slate-400">-</span>
                            @elseif ($row->cash_difference === 0)
                                <span class="text-emerald-400 font-semibold">Cocok</span>
                            @else
                                <span @class(['font-semibold', 'text-amber-400' => $row->cash_difference > 0, 'text-rose-400' => $row->cash_difference < 0])>{{ $row->cash_difference > 0 ? '+' : '-' }}{{ Num::currency(abs($row->cash_difference)) }}</span>
                            @endif
                        </x-table.td>
                        <x-table.td>
                            @if ($row->isOpen())
                                <x-badge color="sky">BUKA</x-badge>
                            @else
                                <x-badge>DITUTUP</x-badge>
                            @endif
                        </x-table.td>
                        <x-table.td align="right">
                            <a href="{{ route('shifts.show', $row) }}" wire:navigate aria-label="Detail {{ $row->number }}" class="inline-flex items-center justify-center w-11 h-11 sm:w-8 sm:h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800">
                                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                            </a>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-modal name="open-shift" max-width="md" focusable>
        <form wire:submit="openShift" class="p-5 sm:p-6 space-y-5">
            <x-modal-header title="Buka shift kasir" icon="wallet" closeable>
                Hitung uang di laci sebelum mulai berjualan.
            </x-modal-header>
            <div>
                <x-input-label for="openingCash" value="Modal awal di laci" />
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">Rp</span>
                    <x-text-input wire:model="openingCash" id="openingCash" inputmode="numeric" class="w-full pl-10 text-lg font-bold tabular-nums" placeholder="0" />
                </div>
                <x-input-error :messages="$errors->get('openingCash')" class="mt-1.5" />
            </div>
            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="openShift" loading="Membuka...">Buka Shift</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-modal>
</div>
