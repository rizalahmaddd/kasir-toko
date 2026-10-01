<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-base text-slate-100 leading-tight truncate">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="space-y-6">
        <div class="p-4 sm:p-8 bg-slate-900/80 border border-slate-800/80 rounded-xl">
            <div class="max-w-xl">
                <livewire:profile.update-profile-information-form />
            </div>
        </div>

        <div class="p-4 sm:p-8 bg-slate-900/80 border border-slate-800/80 rounded-xl">
            <div class="max-w-xl">
                <livewire:profile.update-password-form />
            </div>
        </div>

        <div class="p-4 sm:p-8 bg-slate-900/80 border border-slate-800/80 rounded-xl">
            <div class="max-w-xl">
                <livewire:profile.delete-user-form />
            </div>
        </div>
    </div>
</x-app-layout>
