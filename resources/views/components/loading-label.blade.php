@props(['target', 'loading' => 'Memproses...'])

<span wire:loading.remove wire:target="{{ $target }}" {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-2 whitespace-nowrap']) }}>{{ $slot }}</span>
<span wire:loading wire:target="{{ $target }}" {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-2 whitespace-nowrap']) }}>
    <x-spinner />
    <span>{{ $loading }}</span>
</span>
