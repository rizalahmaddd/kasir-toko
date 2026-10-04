@props(['align' => 'left'])

@php
    $alignClass = match($align) {
        'right' => 'text-right',
        'center' => 'text-center',
        default => 'text-left',
    };
@endphp

<td {{ $attributes->merge(['class' => "px-3 py-2 {$alignClass}"]) }}>
    {{ $slot }}
</td>
