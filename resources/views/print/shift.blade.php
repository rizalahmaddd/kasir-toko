@php
    use App\Enums\PaymentMethod;
    $money = fn (int $value) => number_format($value, 0, ',', '.');
@endphp

<x-layouts.thermal :title="'Rekap '.$shift->number" :width="$width">
    <div class="center">
        <div class="bold big">{{ \App\Support\Branding::companyName() }}</div>
        <div class="bold">REKAP SHIFT KASIR</div>
    </div>

    <hr class="sep">
    <div class="row"><span>No</span><span>{{ $shift->number }}</span></div>
    <div class="row"><span>Kasir</span><span>{{ $shift->user->name }}</span></div>
    <div class="row"><span>Buka</span><span>{{ $shift->opened_at->format('d/m/Y H:i') }}</span></div>
    <div class="row"><span>Tutup</span><span>{{ $shift->closed_at?->format('d/m/Y H:i') ?? 'Masih buka' }}</span></div>

    <hr class="sep">
    <div class="row"><span>Transaksi</span><span>{{ $summary['sales_count'] }}</span></div>
    <div class="row"><span>Penjualan</span><span>{{ $money($summary['sales_total']) }}</span></div>
    @if ($summary['voided_count'] > 0)
        <div class="row"><span>Dibatalkan</span><span>{{ $summary['voided_count'] }} trx</span></div>
    @endif

    <hr class="sep">
    <div class="bold">Uang laci</div>
    <div class="row"><span>Modal awal</span><span>{{ $money($summary['opening']) }}</span></div>
    <div class="row"><span>Penjualan tunai</span><span>{{ $money($summary['cash_sales']) }}</span></div>
    @if ($summary['cash_receivables'] > 0)
        <div class="row"><span>Pelunasan kasbon</span><span>{{ $money($summary['cash_receivables']) }}</span></div>
    @endif
    <div class="row"><span>Kas masuk</span><span>{{ $money($summary['cash_in']) }}</span></div>
    <div class="row"><span>Kas keluar</span><span>-{{ $money($summary['cash_out']) }}</span></div>
    <div class="row bold"><span>Seharusnya</span><span>{{ $money($shift->expected_cash ?? $summary['expected']) }}</span></div>
    @if (! $shift->isOpen())
        <div class="row"><span>Uang fisik</span><span>{{ $money($shift->counted_cash) }}</span></div>
        <div class="row bold"><span>Selisih</span><span>{{ $shift->cash_difference > 0 ? '+' : '' }}{{ $money($shift->cash_difference) }}</span></div>
    @endif

    <hr class="sep">
    <div class="bold">Non-tunai</div>
    @foreach ($summary['non_cash'] as $method => $amount)
        <div class="row"><span>{{ PaymentMethod::from($method)->label() }}</span><span>{{ $money($amount) }}</span></div>
    @endforeach

    @if ($shift->cashMovements->isNotEmpty())
        <hr class="sep">
        <div class="bold">Catatan kas</div>
        @foreach ($shift->cashMovements as $movement)
            <div class="row"><span>{{ $movement->type->value === 'in' ? '+' : '-' }} {{ $movement->reason }}</span><span>{{ $money($movement->amount) }}</span></div>
        @endforeach
    @endif

    @if ($shift->closing_note)
        <hr class="sep">
        <div>Catatan: {{ $shift->closing_note }}</div>
    @endif

    <div style="height: 10mm"></div>
    <div class="row"><span>Kasir</span><span>Diperiksa</span></div>
    <div style="height: 12mm"></div>
    <div class="row"><span>(............)</span><span>(............)</span></div>
    <div style="height: 6mm"></div>
</x-layouts.thermal>
