@props(['choices'])

{{-- Dipakai di komponen Livewire yang memakai WithOutletFilter. Pilihan kosong = outlet yang sedang aktif. --}}
@if ($choices->count() > 1)
    @php
        $activeOutletName = app(\App\Support\CurrentOutlet::class)->get()?->name;
    @endphp
    <x-select variant="filter" wire:model.live="outletFilter" aria-label="Filter outlet" {{ $attributes->merge(['class' => 'flex-1 sm:flex-none']) }}>
        <option value="">Outlet aktif{{ $activeOutletName ? " ({$activeOutletName})" : '' }}</option>
        <option value="all">Semua outlet</option>
        @foreach ($choices as $choice)
            <option value="{{ $choice->id }}">{{ $choice->name }}</option>
        @endforeach
    </x-select>
@endif
