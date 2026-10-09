@php
    use App\Support\NumberFormatter as Num;
    $isPro = app(\App\Support\CurrentTenant::class)->get()?->isPro() ?? true;
@endphp

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <a href="{{ route('pharmacy.prescriptions') }}" wire:navigate class="inline-flex items-center gap-1.5 min-h-[44px] text-xs font-semibold text-slate-400 hover:text-slate-100">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Daftar resep
        </a>
        <div class="flex flex-wrap gap-2">
            @if ($isPro && $hasRemaining && $prescription->status->isOpen())
                <x-secondary-button size="sm" :href="route('pharmacy.prescriptions.copy', $prescription)" target="_blank">
                    <i data-lucide="copy" class="w-4 h-4"></i> Cetak Salinan Resep
                </x-secondary-button>
            @endif
            @if ($isPro)
                <x-secondary-button size="sm" :href="route('pharmacy.prescriptions.labels', $prescription)" target="_blank">
                    <i data-lucide="tag" class="w-4 h-4"></i> Cetak Etiket
                </x-secondary-button>
            @endif
            @if ($prescription->status->isOpen() && ! $prescription->isVerified())
                @can('pharmacy.prescription.verify')
                    <x-primary-button size="sm" type="button" wire:click="verify" wire:loading.attr="disabled">
                        <x-loading-label target="verify" loading="Memverifikasi...">Verifikasi Resep</x-loading-label>
                    </x-primary-button>
                @endcan
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6 items-start">
        {{-- Titik fokus: obat dan sisa yang masih bisa ditebus --}}
        <section class="lg:col-span-8 rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-4 sm:p-6 shadow-xl space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="font-mono text-lg font-bold text-slate-100">{{ $prescription->number }}</h1>
                <x-badge :color="$prescription->status->color()">{{ mb_strtoupper($prescription->status->label()) }}</x-badge>
                @if ($prescription->status->isOpen())
                    <x-badge :color="$prescription->isVerified() ? 'emerald' : 'amber'">{{ $prescription->isVerified() ? 'TERVERIFIKASI' : 'MENUNGGU APOTEKER' }}</x-badge>
                @endif
            </div>

            <div class="overflow-x-auto -mx-1">
                <table class="w-full text-xs">
                    <thead class="text-slate-400 text-left">
                        <tr class="border-b border-slate-700/60">
                            <th class="py-2 px-1 font-semibold">Obat</th>
                            <th class="py-2 px-1 font-semibold text-right">Diresepkan</th>
                            <th class="py-2 px-1 font-semibold text-right">Ditebus</th>
                            <th class="py-2 px-1 font-semibold text-right">Sisa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($prescription->items as $item)
                            @php $unit = $item->product?->unit; @endphp
                            <tr wire:key="item-{{ $item->id }}">
                                <td class="py-2.5 px-1">
                                    <div class="font-semibold text-slate-100">{{ $item->product_name }}</div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ $item->dosage_instructions ?: 'Aturan pakai belum diisi' }}@if ($item->iteration > 0) · iter {{ $item->iteration }}x @endif
                                        @unless ($item->product_id) · di luar katalog @endunless
                                    </div>
                                </td>
                                <td class="py-2.5 px-1 text-right tabular-nums text-slate-300">{{ Num::quantity((float) $item->quantity_prescribed) }} {{ $unit }}</td>
                                <td class="py-2.5 px-1 text-right tabular-nums text-slate-300">{{ Num::quantity((float) $item->quantity_dispensed) }}</td>
                                <td class="py-2.5 px-1 text-right tabular-nums font-bold {{ $item->remaining() > 0 ? 'text-emerald-400' : 'text-slate-400' }}">{{ Num::quantity($item->remaining()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($sales->isNotEmpty())
                <div class="pt-3 border-t border-slate-700/60 space-y-2">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Penebusan</p>
                    @foreach ($sales as $sale)
                        <a href="{{ route('sales.show', $sale) }}" wire:navigate class="flex items-center justify-between gap-3 min-h-[44px] px-3 rounded-lg border border-slate-800 bg-slate-950/40 hover:border-slate-700 text-xs">
                            <span class="font-mono text-slate-200">{{ $sale->number }}</span>
                            <span class="text-slate-400">{{ $sale->sold_at->translatedFormat('d M Y H:i') }} · {{ $sale->cashier?->name }}</span>
                            @if ($sale->isVoided())
                                <x-badge color="slate">DIBATALKAN</x-badge>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <aside class="lg:col-span-4 space-y-4">
            <section class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-2 text-xs">
                <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400">Pasien</p>
                <p class="text-sm font-semibold text-slate-100">{{ $prescription->patient_name }}@if ($prescription->patient_age), {{ $prescription->patient_age }} tahun @endif</p>
                @if ($prescription->patient_phone)<p class="text-slate-300">{{ $prescription->patient_phone }}</p>@endif
                @if ($prescription->patient_address)<p class="text-slate-400">{{ $prescription->patient_address }}</p>@endif
                @if ($prescription->customer)
                    <a href="{{ route('master-data.customers.show', $prescription->customer) }}" wire:navigate class="inline-flex text-emerald-400 hover:underline">Pelanggan {{ $prescription->customer->code }}</a>
                @endif
            </section>

            <section class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-1.5 text-xs">
                <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400">Dokter</p>
                <p class="text-sm font-semibold text-slate-100">dr. {{ $prescription->doctor_name }}</p>
                @if ($prescription->doctor_sip)<p class="text-slate-300">SIP {{ $prescription->doctor_sip }}</p>@endif
                @if ($prescription->clinic_name)<p class="text-slate-400">{{ $prescription->clinic_name }}</p>@endif
                <p class="text-slate-400">Tanggal resep {{ $prescription->prescription_date->translatedFormat('d M Y') }}</p>
            </section>

            <section class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-1.5 text-xs text-slate-400">
                <p>Dicatat {{ $prescription->created_at->translatedFormat('d M Y H:i') }} oleh {{ $prescription->creator?->name ?? '-' }} di {{ $prescription->outlet?->name }}</p>
                @if ($prescription->isVerified())
                    <p>Diverifikasi {{ $prescription->verified_at->translatedFormat('d M Y H:i') }} oleh {{ $prescription->verifier?->name ?? '-' }}</p>
                @endif
                @if ($prescription->notes)
                    <p class="text-slate-300 whitespace-pre-line">{{ $prescription->notes }}</p>
                @endif
            </section>

            @if ($prescription->image_path)
                <section class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-2">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-400">Foto resep</p>
                    <a href="{{ route('pharmacy.prescriptions.image', $prescription) }}" target="_blank" rel="noopener" class="block">
                        <img src="{{ route('pharmacy.prescriptions.image', $prescription) }}" alt="Foto resep {{ $prescription->number }}" class="w-full rounded-lg border border-slate-800 bg-slate-950 object-contain max-h-80">
                    </a>
                </section>
            @endif
        </aside>
    </div>
</div>
