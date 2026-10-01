@props(['size' => 'w-6 h-6', 'padding' => 'p-2.5', 'radius' => 'rounded-xl'])

{{-- Logo dari Pengaturan Perusahaan (App\Support\Branding). Tanpa logo: ikon bawaan dengan satu-satunya
     gradient di sistem (DESIGN.md "Palet Warna"). Ukuran kotak tetap sama di kedua kondisi. --}}
@if ($logoUrl = \App\Support\Branding::logoUrl())
    <div {{ $attributes->merge(['class' => "{$radius} bg-white overflow-hidden flex items-center justify-center shrink-0"]) }}>
        <img src="{{ $logoUrl }}" alt="{{ \App\Support\Branding::appName() }}" class="{{ $size }} box-content {{ $padding }} object-contain">
    </div>
@else
    <div {{ $attributes->merge(['class' => "bg-gradient-to-tr from-emerald-600 to-emerald-400 {$padding} {$radius} text-slate-950 shadow-md shadow-emerald-900/30 flex items-center justify-center"]) }}>
        <i data-lucide="shield-check" class="{{ $size }}" aria-hidden="true"></i>
    </div>
@endif
