@props(['value'])

{{-- Tren naik/turun dibanding periode sebelumnya. $value null berarti tidak ada dasar pembanding
     (periode sebelumnya nol), jadi sengaja tidak ditampilkan sebagai 0% yang menyesatkan. --}}
@if ($value !== null)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-0.5 text-[11px] font-bold '.($value >= 0 ? 'text-emerald-400' : 'text-rose-400')]) }}>
        <i data-lucide="{{ $value >= 0 ? 'arrow-up' : 'arrow-down' }}" class="w-3 h-3"></i>
        {{ number_format(abs($value), 1) }}%
    </span>
@endif
