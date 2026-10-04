@props(['label'])

<span class="relative inline-flex shrink-0 items-center">
    <input type="checkbox" role="switch" aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'peer sr-only']) }}>
    <span class="w-9 h-5 rounded-full bg-slate-700 transition-colors peer-checked:bg-emerald-600 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-slate-900"></span>
    <span class="absolute left-0.5 w-4 h-4 rounded-full bg-slate-100 transition-transform peer-checked:translate-x-4"></span>
</span>
