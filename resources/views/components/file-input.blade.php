@props([
    'disabled' => false,
    'file' => null,
    'icon' => 'upload',
    'label' => 'Pilih file',
    'hint' => null,
])

@php
    $target = $attributes->wire('model')->value();
    $fileName = $file instanceof \Illuminate\Http\UploadedFile ? $file->getClientOriginalName() : null;
@endphp

<label
    x-data="{ over: false }"
    x-on:dragover.prevent="over = true"
    x-on:dragleave.prevent="over = false"
    x-on:drop.prevent="over = false; $refs.input.files = $event.dataTransfer.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true }))"
    x-bind:class="over && 'border-emerald-500 bg-emerald-500/5'"
    {{ $attributes->only('class')->class([
        'group flex items-center gap-3 w-full min-h-[56px] px-3 py-2.5 rounded-lg border border-dashed bg-slate-950 transition-colors focus-within:ring-2 focus-within:ring-emerald-500',
        'border-emerald-500/40' => $fileName,
        'border-slate-700' => ! $fileName,
        'hover:border-emerald-500 cursor-pointer' => ! $disabled,
        'opacity-50 cursor-not-allowed' => $disabled,
    ]) }}
>
    <span @class([
        'shrink-0 w-9 h-9 inline-flex items-center justify-center rounded-lg border',
        'bg-emerald-500/10 border-emerald-500/30 text-emerald-400' => $fileName,
        'bg-slate-900 border-slate-800 text-slate-400 group-hover:text-emerald-400' => ! $fileName,
    ])>
        <i data-lucide="{{ $fileName ? 'file-check' : $icon }}" class="w-4 h-4" @if ($target) wire:loading.remove wire:target="{{ $target }}" @endif></i>
        @if ($target)
            <x-spinner class="w-4 h-4" wire:loading wire:target="{{ $target }}" />
        @endif
    </span>

    <span class="min-w-0 flex-1">
        <span class="block text-xs font-semibold text-slate-200 truncate">{{ $fileName ?? $label }}</span>
        <span class="block text-[11px] text-slate-400" @if ($target) wire:loading.remove wire:target="{{ $target }}" @endif>
            {{ $fileName ? 'Klik atau seret file lain untuk mengganti.' : ($hint ?? 'Klik atau seret file ke sini.') }}
        </span>
        @if ($target)
            <span class="block text-[11px] text-slate-400" wire:loading wire:target="{{ $target }}">Mengunggah...</span>
        @endif
    </span>

    <input type="file" x-ref="input" @disabled($disabled) class="sr-only" {{ $attributes->except('class') }}>
</label>
