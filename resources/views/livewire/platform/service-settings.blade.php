<div class="space-y-4 sm:space-y-6">
    <p class="text-xs text-slate-400">Aturan untuk toko yang baru mendaftar. Toko yang sudah terdaftar tidak ikut berubah.</p>

    <form wire:submit="save" class="grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-4 sm:gap-6 items-start">
        <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
            <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="user-plus" class="w-4 h-4 text-slate-400"></i> Pendaftaran</h3>
            <x-checkbox-card wire:model="registrationOpen" label="Buka pendaftaran toko baru" description="Kalau dimatikan, halaman daftar di web dan aplikasi mobile menolak pendaftaran. Toko yang sudah ada tetap bisa masuk." />
            <div>
                <x-input-label for="trialDays" value="Lama uji coba (hari) *" />
                <x-text-input wire:model="trialDays" id="trialDays" type="number" min="1" max="90" class="w-full sm:w-40 tabular-nums" />
                <p class="text-[11px] text-slate-400 mt-1">Dihitung sejak toko mendaftar. Perpanjangan per toko diatur dari halaman Toko Pelanggan.</p>
                <x-input-error :messages="$errors->get('trialDays')" class="mt-1.5" />
            </div>
            <div class="pt-1">
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="save" loading="Menyimpan...">Simpan Pengaturan</x-loading-label>
                </x-primary-button>
            </div>
        </section>

        <aside class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3">
            <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="layers" class="w-4 h-4 text-slate-400"></i> Paket</h3>
            <ul class="divide-y divide-slate-800/60 text-xs">
                @foreach ($plans as $key => $plan)
                    <li class="py-2.5 flex items-center justify-between gap-3">
                        <span class="font-semibold text-slate-200">{{ $plan['label'] }}</span>
                        <span class="text-slate-400 text-right">
                            {{ $plan['max_users'] ? $plan['max_users'].' pengguna' : 'Pengguna tanpa batas' }} ·
                            {{ $plan['max_products'] ? \App\Support\NumberFormatter::quantity($plan['max_products']).' produk' : 'produk tanpa batas' }}
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="text-[11px] text-slate-500">Batas paket diatur di <code class="font-mono">config/saas.php</code>.</p>
        </aside>
    </form>
</div>
