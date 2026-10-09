@php
    use App\Support\NumberFormatter as Num;
    $summary = $count->summary ?? [];
@endphp

<x-layouts.print :title="'Berita Acara '.$count->number">
    <div class="paper-sheet bg-white text-slate-900 rounded-xl border border-slate-300 p-6 md:p-8">
        <x-print-letterhead title="Berita Acara Stok Opname" :number="$count->number" :date="'Selesai: '.$count->posted_at?->translatedFormat('d M Y H:i')" />

        <table class="w-full text-xs text-left border-collapse border border-slate-300 mb-4">
            <tbody>
                @foreach (array_filter([
                    'Outlet' => $multiOutlet ? $count->outlet?->name : null,
                    'Lingkup' => $count->scope->label(),
                    'Periode hitung' => ($firstCountedAt ? \Illuminate\Support\Carbon::parse($firstCountedAt)->translatedFormat('d M Y H:i') : '-').' s.d. '.$count->posted_at?->translatedFormat('d M Y H:i'),
                    'Penghitung' => $counters->implode(', ') ?: '-',
                    'Barang dihitung' => Num::quantity($summary['counted'] ?? 0).' dari '.Num::quantity($summary['items'] ?? 0),
                    'Barang belum dihitung' => $count->uncounted_policy === \App\Models\StockCount::UNCOUNTED_ZERO ? 'Dianggap habis (0)' : 'Stoknya dibiarkan',
                    'Catatan' => $count->note,
                ]) as $label => $value)
                    <tr>
                        <th class="border border-slate-300 p-2 w-48 bg-slate-50 font-semibold text-slate-700">{{ $label }}</th>
                        <td class="border border-slate-300 p-2">{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="grid grid-cols-3 gap-3 mb-4 text-xs">
            <div class="border border-slate-300 rounded p-2">
                <p class="text-slate-500">Barang berubah</p>
                <p class="text-base font-bold tabular-nums">{{ Num::quantity($summary['changed'] ?? 0) }}</p>
            </div>
            <div class="border border-slate-300 rounded p-2">
                <p class="text-slate-500">Kurang</p>
                <p class="text-base font-bold tabular-nums">{{ Num::currency($summary['shortage_value'] ?? 0) }}</p>
                <p class="text-slate-500 tabular-nums">{{ Num::quantity($summary['shortage_qty'] ?? 0) }} unit</p>
            </div>
            <div class="border border-slate-300 rounded p-2">
                <p class="text-slate-500">Lebih</p>
                <p class="text-base font-bold tabular-nums">{{ Num::currency($summary['surplus_value'] ?? 0) }}</p>
                <p class="text-slate-500 tabular-nums">{{ Num::quantity($summary['surplus_qty'] ?? 0) }} unit</p>
            </div>
        </div>

        <table class="w-full text-[11px] text-left border-collapse border border-slate-300">
            <thead class="bg-slate-50">
                <tr>
                    <th class="border border-slate-300 p-1.5">Barang</th>
                    <th class="border border-slate-300 p-1.5 text-right">Stok sistem</th>
                    <th class="border border-slate-300 p-1.5 text-right">Hasil hitung</th>
                    <th class="border border-slate-300 p-1.5 text-right">Selisih</th>
                    <th class="border border-slate-300 p-1.5 text-right">Nilai</th>
                    <th class="border border-slate-300 p-1.5">Alasan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr class="break-inside-avoid">
                        <td class="border border-slate-300 p-1.5">{{ $item->product->name }} <span class="font-mono text-slate-500">{{ $item->product->sku }}</span></td>
                        <td class="border border-slate-300 p-1.5 text-right tabular-nums">{{ Num::quantity((float) $item->reference_system_qty) }}</td>
                        <td class="border border-slate-300 p-1.5 text-right tabular-nums">{{ Num::quantity((float) $item->counted_qty) }}</td>
                        <td class="border border-slate-300 p-1.5 text-right tabular-nums">{{ (float) $item->variance_qty > 0 ? '+' : '' }}{{ Num::quantity((float) $item->variance_qty) }}</td>
                        <td class="border border-slate-300 p-1.5 text-right tabular-nums">{{ Num::currency((int) round((float) $item->variance_qty * (int) $item->unit_cost)) }}</td>
                        <td class="border border-slate-300 p-1.5">{{ $item->reason?->label() ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="border border-slate-300 p-3 text-center text-slate-500">Tidak ada selisih. Stok fisik sama dengan stok sistem.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="grid grid-cols-2 gap-8 mt-10 text-xs text-center">
            <div>
                <p>Penghitung</p>
                <div class="h-16"></div>
                <p class="border-t border-slate-400 pt-1">{{ $counters->first() ?? 'Nama & tanda tangan' }}</p>
            </div>
            <div>
                <p>Disetujui</p>
                <div class="h-16"></div>
                <p class="border-t border-slate-400 pt-1">{{ $count->poster?->name ?? 'Nama & tanda tangan' }}</p>
            </div>
        </div>
    </div>
</x-layouts.print>
