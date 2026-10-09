@php
    use App\Support\NumberFormatter as Num;
@endphp

<x-layouts.print :title="'Lembar Hitung '.$count->number">
    <div class="paper-sheet bg-white text-slate-900 rounded-xl border border-slate-300 p-6 md:p-8">
        <x-print-letterhead title="Lembar Hitung Stok" :number="$count->number" :date="'Dicetak: '.now()->translatedFormat('d M Y H:i')" />

        <p class="text-xs text-slate-600 mb-3">
            {{ $count->scope->label() }}@if ($multiOutlet) · Outlet {{ $count->outlet?->name }}@endif @if ($count->note) · {{ $count->note }}@endif.
            Tulis jumlah fisik di kolom Hitungan. Barang di beberapa tempat dijumlahkan.
        </p>

        <table class="w-full text-[11px] text-left border-collapse border border-slate-300">
            <thead class="bg-slate-50">
                <tr>
                    <th class="border border-slate-300 p-1.5 w-8">No</th>
                    <th class="border border-slate-300 p-1.5">SKU / Barcode</th>
                    <th class="border border-slate-300 p-1.5">Nama barang</th>
                    <th class="border border-slate-300 p-1.5">Satuan</th>
                    @if ($showSystem)
                        <th class="border border-slate-300 p-1.5 text-right">Stok sistem</th>
                    @endif
                    <th class="border border-slate-300 p-1.5 w-32">Hitungan</th>
                </tr>
            </thead>
            <tbody>
                @php $category = false; @endphp
                @foreach ($items as $index => $item)
                    @php $product = $item->product; @endphp
                    @if ($category !== ($product->category?->name ?? ''))
                        @php $category = $product->category?->name ?? ''; @endphp
                        <tr>
                            <td colspan="{{ $showSystem ? 6 : 5 }}" class="border border-slate-300 p-1.5 font-semibold bg-slate-100">{{ $category ?: 'Tanpa kategori' }}</td>
                        </tr>
                    @endif
                    <tr class="break-inside-avoid">
                        <td class="border border-slate-300 p-1.5 tabular-nums">{{ $index + 1 }}</td>
                        <td class="border border-slate-300 p-1.5 font-mono">{{ $product->sku }}@if ($product->barcode)<br>{{ $product->barcode }}@endif</td>
                        <td class="border border-slate-300 p-1.5">
                            {{ $product->name }}@if ($product->variantLabel()) — {{ $product->variantLabel() }}@endif
                            @if ($product->tracksSerials())<div class="text-[10px] text-slate-500">Tulis nomor seri setiap unit di balik lembar</div>@endif
                        </td>
                        <td class="border border-slate-300 p-1.5">
                            {{ $product->unit }}
                            @foreach ($product->units as $unit)
                                <div class="text-[10px] text-slate-500">{{ $unit->name }} = {{ Num::quantity((float) $unit->factor) }} {{ $product->unit }}</div>
                            @endforeach
                        </td>
                        @if ($showSystem)
                            <td class="border border-slate-300 p-1.5 text-right tabular-nums">{{ Num::quantity((float) $item->expected_qty) }}</td>
                        @endif
                        <td class="border border-slate-300 p-1.5"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="grid grid-cols-2 gap-8 mt-10 text-xs text-center">
            <div>
                <p>Penghitung</p>
                <div class="h-16"></div>
                <p class="border-t border-slate-400 pt-1">Nama &amp; tanda tangan</p>
            </div>
            <div>
                <p>Pemeriksa</p>
                <div class="h-16"></div>
                <p class="border-t border-slate-400 pt-1">Nama &amp; tanda tangan</p>
            </div>
        </div>
    </div>
</x-layouts.print>
