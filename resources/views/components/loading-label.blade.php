@props(['target', 'loading' => 'Memproses...'])

<span wire:loading.remove wire:target="{{ $target }}" {{ $attributes }}>{{ $slot }}</span>
<span wire:loading wire:target="{{ $target }}" class="inline-flex items-center gap-1.5">
    <x-spinner />
    <span>{{ $loading }}</span>
</span>
