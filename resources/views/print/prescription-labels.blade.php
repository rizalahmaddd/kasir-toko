<x-layouts.thermal :title="'Etiket '.$prescription->number" :width="$width">
    @foreach ($items as $item)
        <div class="center">
            <div class="bold">{{ \App\Support\Branding::companyName() }}</div>
            @if ($identity['name'])
                <div>{{ $identity['name'] }}</div>
            @endif
            @if ($identity['address'])
                <div class="muted">{{ $identity['address'] }}</div>
            @endif
        </div>
        <hr class="sep">
        <div class="row"><span>No. {{ $prescription->number }}</span><span>{{ now()->format('d/m/Y') }}</span></div>
        <div class="bold">{{ $prescription->patient_name }}</div>
        <div class="center bold big" style="margin: 6px 0;">{{ $item->dosage_instructions ?: 'Sesuai petunjuk dokter' }}</div>
        <div>{{ $item->product_name }}</div>
        <hr class="sep">
        <div class="row"><span>Apoteker</span><span>{{ $pharmacist->name }}</span></div>
        @if (! $loop->last)
            <div style="page-break-after: always; height: 8mm;"></div>
        @endif
    @endforeach
</x-layouts.thermal>
