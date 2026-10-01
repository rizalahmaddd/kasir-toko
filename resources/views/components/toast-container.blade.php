{{--
    Notifikasi hasil aksi (simpan/hapus dsb). Dipicu lewat $this->dispatch('notify', message:, type:)
    dari komponen Livewire manapun (lihat WithCrudActions::notify()), atau lewat session flash
    'notify' untuk aksi yang diakhiri redirect (mis. simpan branding). Ikon pakai SVG inline,
    bukan data-lucide, supaya tidak bergantung pada timing re-render JS Lucide untuk elemen
    yang murni disisipkan Alpine (x-for), bukan lewat morph Livewire.
--}}
<div
    x-data="{
        toasts: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: detail.message, type: detail.type ?? 'success' });
            setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, 4000);
        },
    }"
    @notify.window="push($event.detail)"
    @if (session('notify'))
        x-init="push(@js(session('notify')))"
    @endif
    class="fixed bottom-[calc(5rem+env(safe-area-inset-bottom))] md:bottom-5 inset-x-4 sm:inset-x-auto sm:right-5 z-[60] space-y-2 sm:w-full sm:max-w-xs"
    style="padding-bottom: env(safe-area-inset-bottom, 0px);"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            :class="toast.type === 'error' ? 'border-rose-500/40 text-rose-300' : 'border-emerald-500/40 text-emerald-300'"
            class="bg-slate-900 border rounded-lg px-4 py-3 text-xs font-medium shadow-xl flex items-start gap-2.5"
        >
            <svg x-show="toast.type !== 'error'" class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <svg x-show="toast.type === 'error'" class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 4.35c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <span x-text="toast.message" class="text-slate-100"></span>
        </div>
    </template>
</div>
