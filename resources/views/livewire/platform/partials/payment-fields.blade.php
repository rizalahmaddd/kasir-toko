<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="amount" value="Nominal Bayar (Rp)" />
        <x-text-input wire:model="amount" id="amount" inputmode="numeric" class="w-full tabular-nums" placeholder="Opsional" />
        <x-input-error :messages="$errors->get('amount')" class="mt-1.5" />
    </div>
    <div>
        <x-input-label for="note" value="Catatan" />
        <x-text-input wire:model="note" id="note" class="w-full" placeholder="Mis. transfer BCA" />
        <x-input-error :messages="$errors->get('note')" class="mt-1.5" />
    </div>
</div>
