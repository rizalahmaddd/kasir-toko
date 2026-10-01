@props(['data', 'type' => 'number', 'color' => 'emerald'])

@php
    $max = collect($data)->max('value') ?: 1;

    // Helper formatting nilai ringkas untuk label atas grafik agar tidak bertumpuk
    $formatCompact = function (float $value) use ($type) {
        if ($value <= 0) return '';
        if ($type !== 'currency') {
            return number_format($value, 0, ',', '.');
        }
        if ($value >= 1_000_000_000) {
            return 'Rp'.number_format($value / 1_000_000_000, 1, ',', '.').' M';
        }
        if ($value >= 1_000_000) {
            return 'Rp'.number_format($value / 1_000_000, 1, ',', '.').' jt';
        }
        return 'Rp'.number_format($value, 0, ',', '.');
    };

    $formatFull = fn (float $value) => $type === 'currency'
        ? 'Rp'.number_format($value, 0, ',', '.')
        : number_format($value, 0, ',', '.');

    $barGradient = match ($color) {
        'amber' => 'bg-gradient-to-t from-amber-600 to-amber-400 group-hover:from-amber-500 group-hover:to-amber-300',
        'sky' => 'bg-gradient-to-t from-sky-600 to-sky-400 group-hover:from-sky-500 group-hover:to-sky-300',
        default => 'bg-gradient-to-t from-emerald-600 to-emerald-400 group-hover:from-emerald-500 group-hover:to-emerald-300',
    };
@endphp

@php
    // Di layar sempit label nilai per batang hanya muat bila batangnya sedikit
    $dense = count($data) > 6;
    $points = collect($data)->map(fn ($point) => ['label' => $point['label'], 'value' => $formatFull((float) $point['value'])])->values();
@endphp

{{-- Tanpa hover di layar sentuh: ketuk batang untuk melihat nilai lengkapnya di bawah grafik --}}
<div class="relative pt-4" x-data="{ selected: null, points: @js($points) }">
    {{-- Garis panduan horizontal halus --}}
    <div class="absolute inset-0 top-6 bottom-7 flex flex-col justify-between pointer-events-none opacity-20">
        <div class="border-b border-dashed border-slate-700 w-full"></div>
        <div class="border-b border-dashed border-slate-700 w-full"></div>
        <div class="border-b border-dashed border-slate-700 w-full"></div>
    </div>

    <div class="flex items-end gap-1.5 sm:gap-4 h-44 relative z-10">
        @foreach ($data as $point)
            @php
                $val = (float) $point['value'];
                $heightPercent = $val > 0 ? max(6, round(($val / $max) * 100)) : 0;
            @endphp
            <button type="button" class="flex-1 flex flex-col items-center gap-2 min-w-0 group cursor-default" wire:key="bar-{{ $loop->index }}" title="{{ $point['label'] }}: {{ $formatFull($val) }}"
                @click="selected = selected === {{ $loop->index }} ? null : {{ $loop->index }}"
                :aria-pressed="selected === {{ $loop->index }}">
                {{-- Nilai angka di atas batang --}}
                <span @class(['text-[10px] sm:text-[11px] font-semibold text-slate-300 group-hover:text-white tabular-nums truncate w-full text-center transition-colors', 'invisible sm:visible' => $dense])>
                    {{ $formatCompact($val) }}
                </span>

                {{-- Batang Bar Chart --}}
                <span :class="selected === {{ $loop->index }} && 'ring-1 ring-slate-500'" class="w-full max-w-[56px] bg-slate-800/50 group-hover:bg-slate-800/80 rounded-t-lg overflow-hidden flex items-end p-0.5 transition-colors" style="height: 110px;">
                    <span
                        class="block w-full rounded-t-md transition-all duration-300 shadow-sm {{ $barGradient }}"
                        style="height: {{ $heightPercent }}%;"
                    ></span>
                </span>

                {{-- Label bulan / waktu --}}
                <span class="text-[10px] sm:text-[11px] text-slate-400 group-hover:text-slate-200 font-medium truncate w-full text-center transition-colors">
                    {{ $point['label'] }}
                </span>
            </button>
        @endforeach
    </div>

    <p class="sm:hidden mt-3 min-h-[1.25rem] text-xs text-center text-slate-400" aria-live="polite">
        <template x-if="selected !== null">
            <span><span class="text-slate-300" x-text="points[selected].label"></span>: <strong class="text-slate-100 tabular-nums" x-text="points[selected].value"></strong></span>
        </template>
        <template x-if="selected === null">
            <span>Ketuk batang untuk melihat nilainya</span>
        </template>
    </p>
</div>

