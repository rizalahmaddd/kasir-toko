{{-- Field bersama untuk pelunasan kasbon (detail transaksi & halaman Piutang): $payAmount, $payMethod, $payReference. --}}
<div>
    <x-input-label for="payAmount" value="Nominal diterima" />
    <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">Rp</span>
        <x-text-input wire:model="payAmount" id="payAmount" inputmode="numeric" class="w-full pl-10 text-lg font-bold tabular-nums" />
    </div>
    <x-input-error :messages="$errors->get('payAmount')" class="mt-1.5" />
</div>

<div>
    <x-input-label value="Metode" />
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
        @foreach ($methods as $method)
            <button type="button" wire:click="$set('payMethod', '{{ $method->value }}')" aria-pressed="{{ $payMethod === $method->value ? 'true' : 'false' }}"
                @class([
                    'flex flex-col items-center justify-center gap-1 min-h-[52px] rounded-lg border text-[11px] font-semibold transition',
                    'border-emerald-500/50 bg-emerald-500/10 text-emerald-400' => $payMethod === $method->value,
                    'border-slate-800 bg-slate-950 text-slate-300 hover:border-slate-700' => $payMethod !== $method->value,
                ])>
                <i data-lucide="{{ $method->icon() }}" class="w-4 h-4"></i>
                {{ $method->shortLabel() }}
            </button>
        @endforeach
    </div>
    @if ($payMethod === 'cash')
        <p class="text-[11px] text-slate-400 mt-1.5">Uang tunai masuk ke laci shift Anda yang sedang buka.</p>
    @endif
</div>

@if ($payMethod !== 'cash')
    <div>
        <x-input-label for="payReference" value="No. referensi (opsional)" />
        <x-text-input wire:model="payReference" id="payReference" class="w-full" />
        <x-input-error :messages="$errors->get('payReference')" class="mt-1.5" />
    </div>
@endif
