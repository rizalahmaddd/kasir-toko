@php
    use App\Enums\OrderType;
    use App\Support\NumberFormatter as Num;
@endphp

<x-layouts.thermal :title="'Tiket Dapur '.$ticket->label" :width="$width" :auto-print="$autoPrint" :embedded="$embedded">
    <div class="center bold">TIKET DAPUR</div>
    <div class="center bold" style="font-size: 1.8em; line-height: 1.2">{{ $ticket->label }}</div>
    @if ($type = OrderType::tryFrom((string) $ticket->order_type))
        <div class="center bold">{{ strtoupper($type->label()) }}</div>
    @endif

    <hr class="sep">
    <div class="row"><span>Waktu</span><span>{{ $ticket->created_at->format('d/m H:i') }}</span></div>
    @if ($ticket->sale)
        <div class="row"><span>No</span><span>{{ $ticket->sale->number }}</span></div>
    @endif
    @if ($ticket->user)
        <div class="row"><span>Kasir</span><span>{{ $ticket->user->name }}</span></div>
    @endif

    <hr class="sep">
    @foreach ($ticket->items as $item)
        <div class="item">
            <div class="bold big">{{ Num::quantity($item['quantity']) }}x {{ $item['name'] }}</div>
            @foreach ($item['modifiers'] ?? [] as $modifier)
                <div>&nbsp; + {{ $modifier }}</div>
            @endforeach
            @if (! empty($item['note']))
                <div class="bold">&nbsp; ! {{ $item['note'] }}</div>
            @endif
        </div>
    @endforeach
    <div style="height: 6mm"></div>
</x-layouts.thermal>
