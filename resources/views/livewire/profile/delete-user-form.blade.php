<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="space-y-4 sm:space-y-6">
    <header>
        <h2 class="text-base font-bold text-slate-100">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-xs text-slate-400">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >{{ __('Delete Account') }}</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable max-width="md">
        <form wire:submit="deleteUser" class="p-4 sm:p-6 space-y-4">
            <div class="flex items-start gap-3.5">
                <div class="w-11 h-11 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center justify-center shrink-0 shadow-sm mt-0.5">
                    <i data-lucide="user-x" class="w-5 h-5"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold text-slate-100 leading-snug">
                        {{ __('Hapus Akun Pengguna?') }}
                    </h2>
                    <p class="mt-1 text-xs text-slate-400 leading-relaxed">
                        {{ __('Setelah akun dihapus, seluruh akses dan sesi login Anda akan dihentikan secara permanen. Masukkan kata sandi akun untuk mengonfirmasi.') }}
                    </p>
                </div>
            </div>

            <div class="bg-rose-950/20 border border-rose-900/30 rounded-xl p-3 text-xs text-rose-300/90 flex items-center gap-2">
                <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0 text-rose-400"></i>
                <span>Tindakan ini permanen dan tidak dapat dibatalkan.</span>
            </div>

            <div>
                <x-input-label for="password" value="{{ __('Kata Sandi Akun') }}" />
                <x-text-input
                    wire:model="password"
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-full text-xs"
                    placeholder="{{ __('Masukkan kata sandi untuk konfirmasi') }}"
                />
                <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
            </div>

            <div class="pt-2 border-t border-slate-800/80 flex flex-col-reverse sm:flex-row sm:justify-end gap-2.5 [&>*]:w-full sm:[&>*]:w-auto">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Batal') }}
                </x-secondary-button>
                <x-danger-button wire:loading.attr="disabled">
                    <x-loading-label target="deleteUser" loading="Menghapus...">{{ __('Ya, Hapus Akun') }}</x-loading-label>
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
