<x-layouts.print :title="'Salinan Resep '.$prescription->number">
    <div class="paper-sheet bg-white text-slate-900 rounded-xl border border-slate-300 p-6 md:p-8 space-y-5">
        <x-print-letterhead title="Salinan Resep (Copy Resep)" :number="$prescription->number" :date="'Dicetak: '.now()->translatedFormat('d M Y H:i')" />

        @if ($identity['name'] || $identity['address'])
            <p class="text-xs text-slate-600">{{ collect([$identity['name'], $identity['address'], $identity['phone']])->filter()->implode(' · ') }}</p>
        @endif

        <table class="w-full text-xs text-left border-collapse border border-slate-300">
            <tbody>
                @foreach ([
                    'Dokter' => 'dr. '.$prescription->doctor_name.($prescription->doctor_sip ? ' (SIP '.$prescription->doctor_sip.')' : ''),
                    'Klinik / RS' => $prescription->clinic_name,
                    'Tanggal resep' => $prescription->prescription_date->translatedFormat('d F Y'),
                    'Pasien' => $prescription->patient_name.($prescription->patient_age ? ', '.$prescription->patient_age.' tahun' : ''),
                ] as $label => $value)
                    <tr>
                        <th class="border border-slate-300 p-2 w-40 bg-slate-50 font-semibold text-slate-700">{{ $label }}</th>
                        <td class="border border-slate-300 p-2">{{ filled($value) ? $value : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="w-full text-xs text-left border-collapse border border-slate-300">
            <thead class="bg-slate-50">
                <tr>
                    <th class="border border-slate-300 p-2">R/</th>
                    <th class="border border-slate-300 p-2 text-right">Diresepkan</th>
                    <th class="border border-slate-300 p-2 text-right">Sudah diberikan</th>
                    <th class="border border-slate-300 p-2 text-right">Sisa (det / nedet)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prescription->items as $item)
                    <tr>
                        <td class="border border-slate-300 p-2">
                            <div class="font-semibold">{{ $item->product_name }}</div>
                            <div class="text-slate-600">S. {{ $item->dosage_instructions ?: '-' }}@if ($item->iteration > 0) · iter {{ $item->iteration }}x @endif</div>
                        </td>
                        <td class="border border-slate-300 p-2 text-right">{{ \App\Support\NumberFormatter::quantity((float) $item->quantity_prescribed) }}</td>
                        <td class="border border-slate-300 p-2 text-right">{{ \App\Support\NumberFormatter::quantity((float) $item->quantity_dispensed) }}</td>
                        <td class="border border-slate-300 p-2 text-right font-semibold">{{ $item->remaining() > 0 ? 'nedet '.\App\Support\NumberFormatter::quantity($item->remaining()) : 'det' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="flex justify-end pt-6 text-xs">
            <div class="text-center w-56">
                <p>PCC (pro copy conform)</p>
                <div class="h-16"></div>
                <p class="border-t border-slate-400 pt-1 font-semibold">{{ $prescription->verifier?->name ?? $pharmacist->name }}</p>
                <p class="text-slate-600">Apoteker</p>
            </div>
        </div>
    </div>
</x-layouts.print>
