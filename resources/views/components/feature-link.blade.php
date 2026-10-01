@props(['href', 'hideWhenDisabled' => false])

{{-- Tautan ke fitur yang dimatikan di Pengaturan Fitur: tombol/ikon disembunyikan, teks (nomor dokumen, nama) tetap tampil tanpa tautan. --}}
@if (\App\Support\Features::allowsUrl($href))
    <a href="{{ $href }}" {{ $attributes }}>{{ $slot }}</a>
@elseif (! $hideWhenDisabled)
    @php
        $plainClass = collect(explode(' ', (string) $attributes->get('class')))
            ->reject(fn (string $class) => $class === '' || str_contains($class, 'hover:') || $class === 'underline' || str_starts_with($class, 'underline-') || str_starts_with($class, 'text-emerald-') || str_starts_with($class, 'text-indigo-'))
            ->implode(' ');
    @endphp
    <span {{ $attributes->except(['class', 'wire:navigate', 'target', 'rel', 'title', 'aria-label']) }} class="{{ $plainClass }}">{{ $slot }}</span>
@endif
