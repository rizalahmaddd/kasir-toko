@php
    $abiLabels = [
        'arm64-v8a' => ['title' => 'Android 64-bit (arm64-v8a)', 'description' => 'Untuk hampir semua HP Android keluaran 2017 ke atas. Pilih ini kalau ragu.'],
        'armeabi-v7a' => ['title' => 'Android 32-bit (armeabi-v7a)', 'description' => 'Untuk HP Android lama atau kelas bawah yang gagal memasang versi 64-bit.'],
        'x86_64' => ['title' => 'Intel/AMD 64-bit (x86_64)', 'description' => 'Untuk emulator, Chromebook, dan tablet berprosesor Intel/AMD.'],
        'universal' => ['title' => 'Universal', 'description' => 'Berjalan di semua perangkat, ukurannya lebih besar.'],
    ];
    $primary = $release['files'][0] ?? null;
@endphp

<x-legal-layout title="Unduh Aplikasi Android" subtitle="Unduh Aplikasi Android">
    <div class="space-y-6">
        @if ($release === null)
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
                <x-empty-state icon="smartphone" title="Aplikasi Android belum tersedia"
                    description="Belum ada versi yang dirilis. Untuk sementara, pakai {{ \App\Support\Branding::appName() }} lewat browser dengan masuk ke akun Anda." />
                <div class="pb-10 text-center">
                    <x-primary-button :href="route('login')">Masuk lewat Browser</x-primary-button>
                </div>
            </div>
        @else
            <section class="rounded-2xl p-6 sm:p-10 bg-gradient-to-br from-slate-900 to-slate-800 border border-slate-800 shadow-lg text-slate-100 no-dark-invert">
                <div class="flex flex-col sm:flex-row sm:items-center gap-6">
                    <x-brand-mark size="w-14 h-14" padding="p-3" radius="rounded-2xl" class="shrink-0" />
                    <div class="flex-1 min-w-0 space-y-1.5">
                        <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-white">
                            {{ \App\Support\Branding::appName() }} untuk Android
                        </h1>
                        <p class="text-sm text-slate-300">
                            Versi {{ $release['version'] }}@if ($release['build']) (build {{ $release['build'] }})@endif
                            · dirilis {{ \Illuminate\Support\Carbon::parse($release['published_at'])->timezone(config('app.timezone'))->translatedFormat('j F Y') }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex flex-col sm:flex-row sm:items-center gap-3">
                    <x-primary-button size="sm" :href="route('app.download.apk', $primary['abi'])" download>
                        <i data-lucide="download" class="w-4 h-4"></i>
                        <span>Unduh APK ({{ Number::fileSize($primary['size'], precision: 1) }})</span>
                    </x-primary-button>
                    <span class="text-xs text-slate-400">{{ $abiLabels[$primary['abi']]['title'] ?? $primary['abi'] }}</span>
                </div>

                @if ($release['notes'])
                    <div class="mt-6 pt-5 border-t border-slate-700/80">
                        <h2 class="text-xs font-semibold text-slate-300 mb-1.5">Yang baru di versi ini</h2>
                        <p class="text-sm text-slate-300 whitespace-pre-line">{{ $release['notes'] }}</p>
                    </div>
                @endif
            </section>

            <section class="bg-white dark:bg-slate-900 rounded-xl p-6 sm:p-8 border border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Panduan Pemasangan Cepat</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4">
                    <div class="p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/40 space-y-1.5">
                        <span class="w-6 h-6 rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 text-xs font-bold flex items-center justify-center">1</span>
                        <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100">Unduh APK</h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">Ketuk tombol unduh di atas dari browser ponsel Android Anda.</p>
                    </div>
                    <div class="p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/40 space-y-1.5">
                        <span class="w-6 h-6 rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 text-xs font-bold flex items-center justify-center">2</span>
                        <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100">Izinkan Pemasangan</h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">Buka file dari notifikasi unduhan, lalu izinkan instal dari sumber ini jika diminta.</p>
                    </div>
                    <div class="p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/40 space-y-1.5">
                        <span class="w-6 h-6 rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 text-xs font-bold flex items-center justify-center">3</span>
                        <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100">Buka &amp; Masuk</h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">Buka aplikasi kasir dan masuk dengan akun toko Anda yang sama.</p>
                    </div>
                </div>
                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                    Memperbarui ke versi baru cukup pasang APK terbaru di atas aplikasi lama; data dan login Anda tidak hilang.
                </p>
            </section>

            <details class="group bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <summary class="px-6 py-4 flex items-center justify-between cursor-pointer select-none hover:bg-slate-50/50 dark:hover:bg-slate-800/40 transition">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white">Varian Lain &amp; Informasi Teknis (Opsional)</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Untuk perangkat khusus (32-bit lama, Chromebook, emulator) dan verifikasi SHA-256.</p>
                    </div>
                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 transition-transform group-open:rotate-180"></i>
                </summary>
                <ul class="divide-y divide-slate-200 dark:divide-slate-800 border-t border-slate-200 dark:border-slate-800">
                    @foreach ($release['files'] as $file)
                        <li class="px-6 py-3.5 flex flex-col sm:flex-row sm:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $abiLabels[$file['abi']]['title'] ?? $file['abi'] }}</span>
                                    @if ($loop->first)
                                        <x-badge color="emerald">DISARANKAN</x-badge>
                                    @endif
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $abiLabels[$file['abi']]['description'] ?? '' }}</p>
                                <p class="text-[10px] text-slate-400 mt-0.5 font-mono break-all select-all">SHA-256: {{ $file['sha256'] }}</p>
                            </div>
                            <x-secondary-button size="sm" :href="route('app.download.apk', $file['abi'])" download class="shrink-0">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                <span>Unduh ({{ Number::fileSize($file['size'], precision: 1) }})</span>
                            </x-secondary-button>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
</x-legal-layout>
