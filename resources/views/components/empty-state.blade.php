@props(['icon' => 'inbox', 'title', 'description' => null])

{{-- Tiap tabel wajib punya empty state yang bilang kenapa kosong / apa langkah berikutnya,
     bukan sekadar "Tidak ada data". Caller mengisi $title spesifik ke konteksnya. --}}
<div class="p-10 text-center space-y-3">
    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-800 text-slate-400 mx-auto">
        <i data-lucide="{{ $icon }}" class="w-6 h-6"></i>
    </div>
    <h4 class="text-sm font-bold text-slate-200">{{ $title }}</h4>
    @if ($description)
        <p class="text-xs text-slate-400 max-w-md mx-auto leading-relaxed">{{ $description }}</p>
    @endif
    {{ $slot ?? '' }}
</div>
