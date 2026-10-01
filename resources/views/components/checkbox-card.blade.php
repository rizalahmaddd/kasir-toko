@props(['label', 'description' => null])

<div class="bg-slate-900/60 border border-slate-800 rounded-xl p-3">
    <label class="flex items-start gap-3 min-h-[44px] cursor-pointer select-none">
        <x-checkbox :attributes="$attributes->merge(['class' => 'w-5 h-5 mt-0.5'])" />
        <span>
            <span class="block text-xs font-semibold text-slate-200">{{ $label }}</span>
            @if ($description)
                <span class="block text-[11px] text-slate-400">{{ $description }}</span>
            @endif
        </span>
    </label>
    {{ $slot }}
</div>
