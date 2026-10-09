@php
    use App\Support\NumberFormatter as Num;
    $summary = $this->summary;
@endphp

<div class="space-y-4 sm:space-y-6">
    {{-- Titik fokus: nilai stok yang sudah tidak bisa dijual --}}
    <section class="rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-4 sm:p-6 shadow-xl grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-rose-400">Sudah kedaluwarsa</p>
            <p class="text-2xl font-extrabold text-slate-50 tabular-nums mt-1">{{ Num::currency($summary['expired_value']) }}</p>
            <p class="text-xs text-slate-400">{{ $summary['expired_count'] }} batch masih tercatat di stok. Keluarkan lewat Stok Keluar supaya tidak terjual.</p>
        </div>
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-amber-400">Kedaluwarsa ≤ {{ \App\Support\PosSettings::expiryWarningDays() }} hari</p>
            <p class="text-2xl font-extrabold text-slate-50 tabular-nums mt-1">{{ Num::currency($summary['soon_value']) }}</p>
            <p class="text-xs text-slate-400">{{ $summary['soon_count'] }} batch perlu dijual lebih dulu atau dikembalikan ke pemasok.</p>
        </div>
    </section>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <x-segmented class="overflow-x-auto max-w-full">
            <x-tab-button size="sm" :active="$window === 'expired'" wire:click="$set('window', 'expired')">Sudah lewat</x-tab-button>
            @foreach (['30', '60', '90', '180'] as $days)
                <x-tab-button size="sm" :active="$window === $days" wire:click="$set('window', '{{ $days }}')">≤ {{ $days }} hari</x-tab-button>
            @endforeach
        </x-segmented>
        <x-table.export-button action="export" label="Export" />
    </div>

    @if ($batches->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state icon="calendar-check" title="Tidak ada batch di rentang ini" description="Stok dengan tanggal kedaluwarsa muncul di sini setelah dicatat lewat Stok Masuk." />
        </div>
    @else
        <x-table :pagination="$batches">
            <x-slot:header>
                <tr>
                    <x-table.th>Produk</x-table.th>
                    <x-table.th>Batch</x-table.th>
                    <x-table.th>Kedaluwarsa</x-table.th>
                    <x-table.th align="right">Jumlah</x-table.th>
                    <x-table.th align="right" class="hidden sm:table-cell">Nilai</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($batches as $batch)
                    @php $daysLeft = (int) today()->diffInDays($batch->expires_at, false); @endphp
                    <x-table.tr wire:key="expiry-{{ $batch->id }}">
                        <x-table.td>
                            <a href="{{ route('inventory.stock', ['product' => $batch->product_id]) }}" wire:navigate class="font-semibold text-slate-100 hover:text-emerald-300">{{ $batch->product?->name }}</a>
                        </x-table.td>
                        <x-table.td class="font-mono text-slate-300">{{ $batch->batch_number ?: 'Tanpa nomor' }}</x-table.td>
                        <x-table.td>
                            <x-badge :color="$daysLeft < 0 ? 'rose' : 'amber'">{{ $batch->expires_at->translatedFormat('d M Y') }}</x-badge>
                            <div class="text-[11px] text-slate-400 mt-0.5">{{ $daysLeft < 0 ? 'lewat '.abs($daysLeft).' hari' : ($daysLeft === 0 ? 'hari ini' : $daysLeft.' hari lagi') }}</div>
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-200">{{ Num::quantity((float) $batch->quantity) }} {{ $batch->product?->unit }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-400 hidden sm:table-cell">{{ Num::currency((int) round((float) $batch->quantity * ($batch->unit_cost ?? $batch->product?->cost_price ?? 0))) }}</x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif
</div>
