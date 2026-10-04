@props(['title' => 'Hapus data ini?', 'description' => 'Tindakan ini tidak bisa dibatalkan.'])

{{-- Dipakai di dalam view komponen Livewire yang pakai App\Livewire\Concerns\WithCrudActions.
     Buka/tutup selalu lewat event open-modal/close-modal('confirm-delete') yang di-dispatch
     trait tersebut, bukan lewat binding :show reaktif: morph Livewire+Alpine mempertahankan
     state x-data lokal, jadi x-data="{ show: @js($show) }" cuma dibaca sekali saat mount. --}}
<x-modal name="confirm-delete" :show="false" max-width="sm">
    <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
        <x-modal-header icon="trash-2" tone="rose" :title="$title">{{ $description }}</x-modal-header>

        <div class="bg-rose-950/20 border border-rose-900/30 rounded-xl p-3 text-xs text-rose-300/90 flex items-center gap-2">
            <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0 text-rose-400"></i>
            <span>Data yang terhapus tidak dapat dipulihkan kembali dari sistem.</span>
        </div>

        <x-modal-actions>
            <x-secondary-button @click="$dispatch('close')" wire:click="cancelDelete" class="justify-center">
                {{ __('Batal') }}
            </x-secondary-button>
            <x-danger-button wire:click="delete" wire:loading.attr="disabled" class="justify-center">
                <x-loading-label target="delete" loading="Menghapus...">{{ __('Ya, Hapus Data') }}</x-loading-label>
            </x-danger-button>
        </x-modal-actions>
    </div>
</x-modal>
