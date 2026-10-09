@php
    use App\Support\NumberFormatter as Num;
@endphp

<x-layouts.print :title="$note->number.' - Surat Jalan'">
    <div class="paper-sheet bg-white text-slate-900 rounded-xl border border-slate-300 p-6 md:p-8 space-y-5">
        <x-print-letterhead title="Surat Jalan" :number="$note->number" :date="$note->created_at->translatedFormat('d M Y')" />

        <table class="w-full text-xs text-left border-collapse border border-slate-300">
            <tbody>
                @foreach ([
                    'Kepada' => $note->recipient,
                    'Telepon' => $note->phone,
                    'Alamat Kirim' => $note->address,
                    'Proyek / Keterangan' => $note->project,
                    'No. Transaksi' => $note->sale->number,
                    'Sopir / Kendaraan' => trim(($note->driver ?? '').($note->vehicle ? ' · '.$note->vehicle : '')),
                ] as $label => $value)
                    <tr>
                        <th class="border border-slate-300 p-2 w-48 bg-slate-50 font-semibold text-slate-700">{{ $label }}</th>
                        <td class="border border-slate-300 p-2 text-slate-900">{{ filled($value) ? $value : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="w-full text-xs text-left border-collapse border border-slate-300">
            <thead>
                <tr class="bg-slate-50">
                    <th class="border border-slate-300 p-2 w-10 text-center">No</th>
                    <th class="border border-slate-300 p-2">Nama Barang</th>
                    <th class="border border-slate-300 p-2 w-32 text-right">Jumlah</th>
                    <th class="border border-slate-300 p-2 w-48">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($note->sale->items as $index => $item)
                    <tr>
                        <td class="border border-slate-300 p-2 text-center">{{ $index + 1 }}</td>
                        <td class="border border-slate-300 p-2">
                            {{ $item->product_name }}
                            @if ($item->serials->isNotEmpty())
                                <div class="text-[10px] text-slate-600 font-mono">SN: {{ $item->serials->pluck('serial')->implode(', ') }}</div>
                            @endif
                        </td>
                        <td class="border border-slate-300 p-2 text-right">{{ Num::quantity($item->quantity) }} {{ $item->unit }}</td>
                        <td class="border border-slate-300 p-2">{{ $item->note ?: '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($note->notes)
            <p class="text-xs"><span class="font-semibold">Catatan:</span> {{ $note->notes }}</p>
        @endif

        <div class="grid grid-cols-3 gap-6 pt-8 text-xs text-center">
            @foreach (['Pengirim', 'Sopir', 'Penerima'] as $role)
                <div>
                    <p>{{ $role }}</p>
                    <div class="h-16"></div>
                    <p class="border-t border-slate-400 pt-1">(.................................)</p>
                </div>
            @endforeach
        </div>
    </div>
</x-layouts.print>
