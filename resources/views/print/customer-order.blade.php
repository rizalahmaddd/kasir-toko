@php
    use App\Support\NumberFormatter as Num;
@endphp

<x-layouts.thermal :title="'Nota '.$order->number" :width="\App\Support\PosSettings::receiptWidth()" :auto-print="request()->boolean('print')">
    <div class="center">
        <div class="bold big">{{ \App\Support\Branding::companyName() }}</div>
        @if ($identity['name'])
            <div class="bold">{{ $identity['name'] }}</div>
        @endif
        @if ($identity['phone'])
            <div class="muted">Telp {{ $identity['phone'] }}</div>
        @endif
    </div>
    <hr class="sep">
    <div class="center bold">{{ $order->isService() ? 'TANDA TERIMA SERVIS' : 'NOTA PESANAN' }}</div>
    <div class="row"><span>No</span><span>{{ $order->number }}</span></div>
    <div class="row"><span>Tanggal</span><span>{{ $order->created_at->format('d/m/Y H:i') }}</span></div>
    <div class="row"><span>Nama</span><span>{{ $order->customer_name }}</span></div>
    @if ($order->customer_phone)
        <div class="row"><span>Telp</span><span>{{ $order->customer_phone }}</span></div>
    @endif
    @if ($order->pickup_at)
        <div class="row bold"><span>{{ $order->isService() ? 'Selesai' : 'Ambil' }}</span><span>{{ $order->pickup_at->format('d/m/Y H:i') }}</span></div>
    @endif
    @if ($order->isService())
        <hr class="sep">
        <div>Perangkat: {{ $order->device ?: '-' }}</div>
        @if ($order->device_serial)
            <div>IMEI/SN: {{ $order->device_serial }}</div>
        @endif
        <div>Keluhan: {{ $order->complaint ?: '-' }}</div>
    @endif
    @if ($order->items->isNotEmpty())
        <hr class="sep">
        @foreach ($order->items as $item)
            <div class="item">
                <div>{{ $item->name }}</div>
                <div class="row"><span>{{ Num::quantity($item->quantity) }} x {{ number_format($item->price, 0, ',', '.') }}</span><span>{{ number_format((int) round($item->price * (float) $item->quantity), 0, ',', '.') }}</span></div>
                @if ($item->note)
                    <div class="muted">&nbsp; {{ $item->note }}</div>
                @endif
            </div>
        @endforeach
    @endif
    <hr class="sep">
    <div class="row"><span>Perkiraan total</span><span>{{ number_format($order->estimated_total, 0, ',', '.') }}</span></div>
    <div class="row"><span>Uang muka</span><span>{{ number_format($order->deposit, 0, ',', '.') }}</span></div>
    <div class="row bold big"><span>SISA</span><span>{{ Num::currency($order->remaining()) }}</span></div>
    @if ($order->notes)
        <hr class="sep">
        <div style="white-space: pre-line">{{ $order->notes }}</div>
    @endif
    <hr class="sep">
    <div class="center muted">Bawa nota ini saat pengambilan.</div>
    <div style="height: 6mm"></div>
</x-layouts.thermal>
