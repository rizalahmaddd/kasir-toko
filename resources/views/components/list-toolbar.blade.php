@props(['description' => null, 'searchPlaceholder' => 'Cari...', 'canManage' => true, 'addLabel' => 'Tambah', 'canExport' => false])

<div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 sm:gap-4">
    <div>
        @if ($description)
            <p class="text-xs text-slate-400">{{ $description }}</p>
        @endif
    </div>

    <div class="flex flex-wrap xl:flex-nowrap items-center gap-2 sm:gap-3">
        <x-search-input class="order-first sm:order-none basis-full sm:basis-auto sm:flex-1 sm:min-w-[14rem] xl:flex-none xl:w-64" wire:model.live.debounce.400ms="search" :placeholder="$searchPlaceholder" />

        {{-- Slot opsional untuk filter (mis. select status) di antara pencarian dan tombol aksi. --}}
        {{ $filters ?? '' }}

        @if ($canExport)
            <x-table.export-button action="export" label="Export" />
        @endif

        {{ $actions ?? '' }}

        @if ($canManage)
            <x-primary-button size="sm" type="button" @click="$dispatch('open-modal', 'record-form')" wire:click="openCreateModal" class="flex-1 sm:flex-none shrink-0">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>{{ $addLabel }}</span>
            </x-primary-button>
        @endif
    </div>
</div>
