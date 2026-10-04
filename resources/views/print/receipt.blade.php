@php
    use App\Models\Setting;
    use App\Support\NumberFormatter as Num;
    use App\Support\PosSettings;

    $header = PosSettings::get('pos.receipt_header');
    $footer = PosSettings::get('pos.receipt_footer');
@endphp

<x-layouts.thermal :title="'Struk '.$sale->number" :width="$width" :auto-print="$autoPrint" :embedded="$embedded">
    <div class="center">
        @if ($logo = \App\Support\Branding::logoUrl())
            <img src="{{ $logo }}" alt="" class="logo">
        @endif
        <div class="bold big">{{ \App\Support\Branding::companyName() }}</div>
        @if (Setting::get('company_address'))
            <div class="muted">{{ Setting::get('company_address') }}</div>
        @endif
        @if (Setting::get('company_phone'))
            <div class="muted">Telp {{ Setting::get('company_phone') }}</div>
        @endif
        @if ($header)
            <div class="muted" style="white-space: pre-line">{{ $header }}</div>
        @endif
    </div>

    <hr class="sep">
    <div class="row"><span>No</span><span>{{ $sale->number }}</span></div>
    <div class="row"><span>Tanggal</span><span>{{ $sale->sold_at->format('d/m/Y H:i') }}</span></div>
    <div class="row"><span>Kasir</span><span>{{ $sale->cashier->name }}</span></div>
    @if ($sale->customer)
        <div class="row"><span>Pelanggan</span><span>{{ $sale->customer->name }}</span></div>
    @endif

    @if ($sale->isVoided())
        <div class="center"><span class="stamp">DIBATALKAN</span></div>
    @endif

    <hr class="sep">
    @foreach ($sale->items as $item)
        <div class="item">
            <div>{{ $item->product_name }}</div>
            <div class="row">
                <span>{{ Num::quantity($item->quantity) }} x {{ number_format($item->price, 0, ',', '.') }}</span>
                <span>{{ number_format($item->total + $item->discount_amount, 0, ',', '.') }}</span>
            </div>
            @if ($item->discount_amount > 0)
                <div class="row"><span>&nbsp; Diskon</span><span>-{{ number_format($item->discount_amount, 0, ',', '.') }}</span></div>
            @endif
            @if ($item->note)
                <div class="muted">&nbsp; {{ $item->note }}</div>
            @endif
        </div>
    @endforeach

    <hr class="sep">
    <div class="row"><span>Subtotal</span><span>{{ number_format($sale->subtotal, 0, ',', '.') }}</span></div>
    @if ($sale->discount_amount > 0)
        <div class="row">
            <span>Diskon{{ $sale->discount_type === 'percent' ? ' '.Num::quantity($sale->discount_value).'%' : '' }}</span>
            <span>-{{ number_format($sale->discount_amount, 0, ',', '.') }}</span>
        </div>
    @endif
    @if ($sale->tax_amount > 0)
        <div class="row"><span>{{ PosSettings::taxLabel() }} {{ Num::quantity($sale->tax_rate) }}%</span><span>{{ number_format($sale->tax_amount, 0, ',', '.') }}</span></div>
    @endif
    <div class="row bold big"><span>TOTAL</span><span>{{ Num::currency($sale->total) }}</span></div>

    <hr class="sep">
    @foreach ($sale->payments->where('kind', 'sale') as $payment)
        <div class="row">
            <span>{{ $payment->method->label() }}</span>
            <span>{{ number_format($payment->method->value === 'cash' ? $sale->cash_received : $payment->amount, 0, ',', '.') }}</span>
        </div>
    @endforeach
    @if ($sale->change_amount > 0)
        <div class="row bold"><span>Kembali</span><span>{{ number_format($sale->change_amount, 0, ',', '.') }}</span></div>
    @endif
    @foreach ($sale->payments->where('kind', 'receivable') as $payment)
        <div class="row"><span>Pelunasan {{ $payment->paid_at->format('d/m') }}</span><span>{{ number_format($payment->amount, 0, ',', '.') }}</span></div>
    @endforeach
    @if ($sale->due_amount > 0 && ! $sale->isVoided())
        <div class="row bold"><span>Sisa (kasbon)</span><span>{{ number_format($sale->due_amount, 0, ',', '.') }}</span></div>
    @endif

    @if ($sale->note)
        <hr class="sep">
        <div>Catatan: {{ $sale->note }}</div>
    @endif

    @if ($footer)
        <hr class="sep">
        <div class="center" style="white-space: pre-line">{{ $footer }}</div>
    @endif
    <div style="height: 6mm"></div>
</x-layouts.thermal>
