@php use App\Support\NumberFormatter as Num; @endphp

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-900/60 p-3 sm:p-4 rounded-xl border border-slate-800">
        <div class="flex items-center bg-slate-950 border border-slate-800 rounded-lg h-10 sm:h-[38px] px-2 shadow-inner self-start">
            <i data-lucide="calendar" class="w-4 h-4 text-slate-400 me-2 shrink-0"></i>
            <input type="date" wire:model.live="from" aria-label="Dari tanggal" class="bg-transparent border-0 text-slate-200 text-xs focus:ring-1 focus:ring-emerald-500 rounded w-[8.75rem] px-1 py-1">
            <span class="text-slate-500 text-xs px-1 shrink-0">–</span>
            <input type="date" wire:model.live="to" aria-label="Sampai tanggal" class="bg-transparent border-0 text-slate-200 text-xs focus:ring-1 focus:ring-emerald-500 rounded w-[8.75rem] px-1 py-1">
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('pharmacy.prescriptions') }}" wire:navigate class="inline-flex items-center gap-1.5 min-h-[44px] px-2 text-xs font-semibold text-slate-400 hover:text-slate-100">
                <i data-lucide="file-heart" class="w-4 h-4"></i> Daftar resep
            </a>
            <x-table.export-button action="export" label="Export" />
        </div>
    </div>

    @if ($items->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state icon="clipboard-list" title="Tidak ada penjualan obat keras" description="Belum ada obat wajib resep yang terjual di rentang tanggal ini." />
        </div>
    @else
        <x-table :pagination="$items">
            <x-slot:header>
                <tr>
                    <x-table.th>Waktu & Transaksi</x-table.th>
                    <x-table.th>Obat</x-table.th>
                    <x-table.th align="right">Jumlah</x-table.th>
                    <x-table.th>Resep</x-table.th>
                    <x-table.th class="hidden lg:table-cell">Kasir</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($items as $item)
                    @php $prescription = $item->sale->prescription; @endphp
                    <x-table.tr wire:key="rx-sale-{{ $item->id }}">
                        <x-table.td>
                            <a href="{{ route('sales.show', $item->sale) }}" wire:navigate class="font-mono text-emerald-400 hover:text-emerald-300">{{ $item->sale->number }}</a>
                            <div class="text-[11px] text-slate-400">{{ $item->sale->sold_at->translatedFormat('d M Y H:i') }}</div>
                        </x-table.td>
                        <x-table.td class="text-slate-100">{{ $item->product_name }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-200">{{ Num::quantity((float) $item->quantity) }} {{ $item->unit }}</x-table.td>
                        <x-table.td>
                            @if ($prescription)
                                <a href="{{ route('pharmacy.prescriptions.show', $prescription) }}" wire:navigate class="font-mono text-slate-200 hover:text-emerald-300">{{ $prescription->number }}</a>
                                <div class="text-[11px] text-slate-400">dr. {{ $prescription->doctor_name }} · {{ $prescription->patient_name }}</div>
                            @else
                                <x-badge color="rose">TANPA RESEP</x-badge>
                            @endif
                            @foreach ($item->sale->flagLabels() as $flag)
                                <div class="text-[11px] text-amber-400">{{ $flag }}</div>
                            @endforeach
                        </x-table.td>
                        <x-table.td class="hidden lg:table-cell text-slate-400">{{ $item->sale->cashier?->name ?? '-' }}</x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif
</div>
