@props(['rows' => 6, 'cols' => 5])

<div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
    <div class="hidden sm:grid gap-4 px-4 py-3 bg-slate-950 border-b border-slate-800" style="grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr))">
        @for ($c = 0; $c < $cols; $c++)
            <span class="sk sk-text w-16"></span>
        @endfor
    </div>
    <div class="divide-y divide-slate-800/60">
        @for ($r = 0; $r < $rows; $r++)
            <div class="grid grid-cols-2 sm:grid-cols-[var(--sk-cols)] gap-x-4 gap-y-2 px-4 py-3.5" style="--sk-cols: repeat({{ $cols }}, minmax(0, 1fr))">
                @for ($c = 0; $c < $cols; $c++)
                    <span @class(['sk sk-text', 'w-3/4' => $c === 0, 'w-1/2' => $c > 0, 'hidden sm:inline-block' => $c > 2])></span>
                @endfor
            </div>
        @endfor
    </div>
</div>
