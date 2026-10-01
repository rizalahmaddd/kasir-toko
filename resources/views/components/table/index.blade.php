{{-- stack: di layar < 640px baris berubah jadi kartu "label … nilai" (lihat .table-stack di app.css). --}}
@props(['pagination' => null, 'stack' => true])

<div {{ $attributes->merge(['class' => 'bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden shadow-sm']) }}>
    <div class="overflow-x-auto overscroll-x-contain">
        <table @class(['w-full text-left text-xs', 'table-stack' => $stack])>
            @php($headerContent = $header ?? ($thead ?? null))
            @if (isset($headerContent))
                <thead class="bg-slate-950 text-slate-400 font-semibold uppercase tracking-wider border-b border-slate-800">
                    {{ $headerContent }}
                </thead>
            @endif
            {{ $slot }}
        </table>
    </div>

    @if ($pagination)
        <div class="border-t border-slate-800/80">
            <x-table.pagination :paginator="$pagination" />
        </div>
    @elseif (isset($footer))
        <div class="border-t border-slate-800/80">
            {{ $footer }}
        </div>
    @endif
</div>
