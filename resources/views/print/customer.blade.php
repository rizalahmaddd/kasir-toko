<x-layouts.print :title="$customer->code.' - Kartu Pelanggan'">
    <div class="paper-sheet bg-white text-slate-900 rounded-xl border border-slate-300 p-6 md:p-8">
        <x-print-letterhead title="Kartu Pelanggan" :number="$customer->code" :date="'Dicetak: '.now()->translatedFormat('d M Y H:i')">
            <figure class="shrink-0 text-center">
                <div class="w-24 h-24" role="img" aria-label="QR code {{ $customer->code }}">{!! \App\Support\QrCodeSvg::render(route('master-data.customers.show', $customer)) !!}</div>
                <figcaption class="text-[9px] text-slate-500 leading-tight mt-0.5">Buka data di aplikasi</figcaption>
            </figure>
        </x-print-letterhead>

        <table class="w-full text-xs text-left border-collapse border border-slate-300">
            <tbody>
                @foreach ([
                    'Nama Pelanggan' => $customer->name,
                    'Jenis/Tipe' => $customer->type,
                    'PIC' => $customer->contact_person,
                    'Telepon' => $customer->phone,
                    'Email' => $customer->email,
                    'Alamat' => $customer->address,
                    'NPWP' => $customer->npwp,
                    'Termin Pembayaran' => $customer->payment_term_days.' hari',
                    'Status' => $customer->is_active ? 'Aktif' : 'Nonaktif',
                ] as $label => $value)
                    <tr>
                        <th class="border border-slate-300 p-2 w-48 bg-slate-50 font-semibold text-slate-700">{{ $label }}</th>
                        <td class="border border-slate-300 p-2 text-slate-900">{{ filled($value) ? $value : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.print>
