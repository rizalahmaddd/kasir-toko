@props(['icon' => 'check-circle', 'message'])

{{-- Empty state ringkas di dalam panel dashboard: kalimatnya menjelaskan arti "kosong" untuk panel itu. --}}
<div class="py-6 text-center">
    <div class="w-10 h-10 rounded-full bg-slate-800/80 flex items-center justify-center mx-auto mb-2">
        <i data-lucide="{{ $icon }}" class="w-5 h-5 text-emerald-400"></i>
    </div>
    <p class="text-xs text-slate-400 max-w-xs mx-auto leading-relaxed">{{ $message }}</p>
</div>
