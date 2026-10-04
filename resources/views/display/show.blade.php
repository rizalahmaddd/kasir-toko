<!DOCTYPE html>
<html lang="id" class="h-full {{ $config['theme'] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $config['storeName'] }} - Layar Pelanggan</title>
        @if ($config['logo'])
            <link rel="icon" href="{{ $config['logo'] }}">
        @endif
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <style>
            @keyframes display-ticker { from { transform: translateX(0); } to { transform: translateX(-50%); } }
            .display-ticker { animation: display-ticker 35s linear infinite; }
            @media (prefers-reduced-motion: reduce) { .display-ticker { animation: none; } }

            @keyframes display-flash { 
                0% { background-color: rgb(16 185 129 / 0.28); } 
                100% { background-color: transparent; } 
            }
            .display-flash { animation: display-flash 1.6s cubic-bezier(0.16, 1, 0.3, 1) 1; }

            .custom-scrollbar::-webkit-scrollbar { width: 5px; }
            .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
            .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(51, 65, 85, 0.5); border-radius: 9999px; }
            .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(100, 116, 139, 0.7); }
        </style>
    </head>
    <body class="h-full font-sans antialiased bg-slate-950 text-slate-100 overflow-hidden select-none">
        <div x-data="customerDisplay(@js($config))" x-cloak
            @pointermove="showControls()" @pointerdown="showControls()"
            :class="!controlsVisible && 'cursor-none'"
            class="h-full flex flex-col bg-slate-950 text-slate-100">

            {{-- HEADER: Minimal, modern, slim (Adaptif terang & gelap) --}}
            <header class="shrink-0 flex items-center justify-between gap-4 px-6 h-14 border-b border-slate-200 dark:border-slate-800 bg-white/95 dark:bg-slate-900/95 backdrop-blur-md z-30 pt-[env(safe-area-inset-top)] shadow-sm">
                <div class="flex items-center gap-3 min-w-0">
                    @if ($config['logo'])
                        <img src="{{ $config['logo'] }}" alt="" class="h-6 sm:h-7 w-auto max-w-[6rem] object-contain">
                    @else
                        <x-brand-mark size="w-6 h-6" padding="p-1" radius="rounded-md" />
                    @endif
                    <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 truncate tracking-tight">{{ $config['storeName'] }}</p>
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-[10px] sm:text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400 animate-pulse"></span>
                        <span>Kasir Siap</span>
                    </span>
                    <span x-show="fetchFailed && !socketConnected" class="inline-flex items-center gap-1 text-[11px] font-medium text-amber-500 dark:text-amber-400">
                        <i data-lucide="wifi-off" class="w-3.5 h-3.5"></i>
                        <span>Menyambung...</span>
                    </span>
                </div>

                <div class="flex items-center gap-3">
                    <span class="hidden sm:inline text-xs text-slate-500 dark:text-slate-400 font-medium" x-text="dateString"></span>
                    <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono font-bold text-xs sm:text-sm text-slate-800 dark:text-slate-200 shadow-sm" x-text="clock"></span>
                </div>
            </header>

            {{-- MAIN: 2 BAGIAN SAJA (KIRI: PROMO SLIDE MENGECIL, KANAN: TRANSAKSI) --}}
            <main class="relative flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden bg-slate-950">
                
                {{-- UNPAIRED ALERT --}}
                <div x-show="unpaired" class="absolute inset-0 flex flex-col items-center justify-center text-center p-8 gap-4 z-50 bg-slate-950/95 backdrop-blur-xl">
                    <div class="w-16 h-16 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center">
                        <i data-lucide="unplug" class="w-8 h-8"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-slate-100">Layar Ini Terputus</h2>
                    <p class="text-sm text-slate-400 max-w-xs">Kode layar diganti dari kasir. Hubungkan kembali dari menu Layar Pelanggan.</p>
                </div>

                {{-- SISI KIRI: PROMO SLIDE (MENYUSUT/MENGECIL KETIKA ADA TRANSAKSI, FULL KETIKA IDLE) --}}
                <div class="relative shrink-0 flex flex-col bg-slate-950 overflow-hidden transition-all duration-500 ease-in-out"
                     :class="stage === 'idle'
                         ? 'w-full h-full border-none'
                         : 'w-full lg:w-[48%] xl:w-[50%] h-64 sm:h-80 lg:h-full border-b lg:border-b-0 lg:border-r border-slate-800'">
                    
                    @if (count($config['slides']) > 0)
                        <div class="relative w-full h-full flex items-center justify-center overflow-hidden bg-slate-950">
                            @foreach ($config['slides'] as $index => $slide)
                                <div x-show="slide === {{ $index }}" 
                                     x-transition.opacity.duration.700ms
                                     class="absolute inset-0 w-full h-full flex items-center justify-center overflow-hidden">
                                    
                                    {{-- Ambient blur backdrop agar warna slide menyatu mewah --}}
                                    <img src="{{ $slide }}" alt="" aria-hidden="true" 
                                         class="absolute inset-0 w-full h-full object-cover blur-3xl opacity-40 scale-110 pointer-events-none">
                                    
                                    {{-- Scrim halus --}}
                                    <div class="absolute inset-0 bg-slate-950/30 pointer-events-none"></div>

                                    {{-- Foto slide asli: MENGECIL (object-contain) saat ada chart agar promo 100% terbaca --}}
                                    <img src="{{ $slide }}" alt="Slide Promosi {{ $index + 1 }}" 
                                         class="relative z-10 transition-all duration-500 ease-in-out drop-shadow-2xl"
                                         :class="stage === 'idle'
                                             ? 'w-full h-full object-cover'
                                             : 'max-w-full max-h-[85%] object-contain p-3 sm:p-5'">
                                </div>
                            @endforeach

                            {{-- Welcome overlay: tertata di bagian bawah slide --}}
                            <div class="absolute bottom-4 left-4 right-4 z-20 pointer-events-auto transition-all duration-500"
                                 :class="stage === 'idle' ? 'sm:bottom-6 sm:left-6 max-w-xl' : 'max-w-full'">
                                <div class="p-3.5 sm:p-4 rounded-2xl bg-slate-950/80 backdrop-blur-xl border border-white/15 shadow-2xl space-y-1.5">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] sm:text-xs font-bold uppercase tracking-wider text-emerald-400 px-2 py-0.5 rounded-full bg-emerald-500/20 border border-emerald-500/30">
                                            Info Toko
                                        </span>
                                        @if (count($config['slides']) > 1)
                                            <div class="flex items-center gap-1.5">
                                                @foreach ($config['slides'] as $index => $slide)
                                                    <span class="h-1.5 rounded-full transition-all duration-300"
                                                          :class="slide === {{ $index }} ? 'w-5 bg-emerald-400' : 'w-1.5 bg-white/40'"></span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <p class="font-bold text-white leading-snug drop-shadow-sm"
                                       :class="stage === 'idle' ? 'text-sm sm:text-base' : 'text-xs sm:text-sm line-clamp-1'">
                                        {{ $config['welcome'] }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @else
                        {{-- Hero Toko jika tanpa slides --}}
                        <div class="relative h-full flex flex-col justify-between p-6 sm:p-8 text-slate-200">
                            <div class="space-y-3" :class="stage === 'idle' ? 'my-auto max-w-xl mx-auto text-center' : ''">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-xs font-bold uppercase tracking-wider">
                                    Selamat Datang
                                </span>
                                <h2 class="font-black text-slate-100 tracking-tight"
                                    :class="stage === 'idle' ? 'text-3xl sm:text-5xl' : 'text-2xl sm:text-3xl'">
                                    {{ $config['storeName'] }}
                                </h2>
                                <p class="text-sm sm:text-base text-slate-400 leading-relaxed"
                                   :class="stage === 'idle' ? 'mx-auto max-w-md' : ''">
                                    {{ $config['welcome'] }}
                                </p>
                            </div>

                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 space-y-2"
                                 :class="stage === 'idle' ? 'max-w-md mx-auto w-full' : ''">
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Pembayaran Diterima</p>
                                <div class="flex items-center gap-3 text-xs font-semibold text-slate-300">
                                    <span class="flex items-center gap-1"><i data-lucide="banknote" class="w-4 h-4 text-emerald-400"></i> Tunai</span>
                                    <span>&bull;</span>
                                    <span class="flex items-center gap-1"><i data-lucide="qr-code" class="w-4 h-4 text-emerald-400"></i> QRIS</span>
                                    <span>&bull;</span>
                                    <span class="flex items-center gap-1"><i data-lucide="credit-card" class="w-4 h-4 text-emerald-400"></i> Debit</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- SISI KANAN: PANEL TRANSAKSI (HANYA MUNCUL KETIKA ADA BARANG / TRANSAKSI) --}}
                <div x-show="!unpaired && stage !== 'idle'"
                     x-transition:enter="transition ease-out duration-500"
                     x-transition:enter-start="opacity-0 translate-x-12"
                     x-transition:enter-end="opacity-100 translate-x-0"
                     x-transition:leave="transition ease-in duration-300"
                     x-transition:leave-start="opacity-100 translate-x-0"
                     x-transition:leave-end="opacity-0 translate-x-12"
                     class="flex-1 min-h-0 flex flex-col bg-slate-900/95 relative overflow-hidden">
                    
                    {{-- KONDISI 1: CART / PAYMENT (Ada transaksi - Items scrollable + Total sticky di bawah) --}}
                    <div x-show="stage === 'cart' || stage === 'payment'" class="flex-1 min-h-0 flex flex-col h-full">
                        
                        {{-- Header List Barang --}}
                        <div class="shrink-0 px-6 py-3.5 border-b border-slate-800 bg-slate-900/80 flex items-center justify-between">
                            <div class="flex items-center gap-2 min-w-0">
                                <i data-lucide="receipt" class="w-4 h-4 text-emerald-400"></i>
                                <span class="text-sm font-bold text-slate-100 truncate" x-text="state.customer ? `Belanjaan ${state.customer}` : 'Daftar Belanjaan'"></span>
                            </div>
                            <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-slate-800 text-emerald-400 border border-slate-700" 
                                  x-text="`${quantity(state.count)} barang`"></span>
                        </div>

                        {{-- Scrollable List Items --}}
                        <ul x-ref="itemList" class="flex-1 min-h-0 overflow-y-auto px-6 py-2 divide-y divide-slate-800/70 custom-scrollbar">
                            <template x-for="(item, index) in state.items ?? []" :key="index">
                                <li class="py-3 flex items-start justify-between gap-3 rounded-lg -mx-2 px-2 transition-colors"
                                    :class="flashIndex === index && 'display-flash'">
                                    <div class="flex items-start gap-2.5 min-w-0 flex-1">
                                        <span class="inline-flex items-center justify-center min-w-[32px] px-1.5 py-0.5 rounded-md bg-slate-800 border border-slate-700 text-xs font-extrabold text-emerald-400 font-mono shrink-0 mt-0.5"
                                              x-text="`${quantity(item.qty)}×`"></span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm sm:text-base font-bold text-slate-100 truncate" x-text="item.name"></p>
                                            <p class="text-xs text-slate-400 tabular-nums" x-text="`@ ${rupiah(item.price)} ${item.unit ? '/ ' + item.unit : ''}`"></p>
                                            <span x-show="item.discount > 0" class="inline-block mt-0.5 text-[10px] font-semibold text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20"
                                                  x-text="`Hemat ${rupiah(item.discount)}`"></span>
                                        </div>
                                    </div>
                                    <p class="text-sm sm:text-base font-bold text-slate-100 font-mono tabular-nums shrink-0" x-text="rupiah(item.total)"></p>
                                </li>
                            </template>
                        </ul>

                        {{-- TOTAL PEMBAYARAN STICKY DI BAWAH --}}
                        <div class="shrink-0 sticky bottom-0 p-5 sm:p-6 bg-slate-900 border-t border-slate-800 shadow-2xl z-20 space-y-3">
                            {{-- Rincian Hemat / Subtotal --}}
                            <div class="flex justify-between items-center text-xs text-slate-400" x-show="state.discount > 0 || state.tax > 0">
                                <span>Subtotal: <strong class="text-slate-200 font-mono" x-text="rupiah(state.subtotal)"></strong></span>
                                <span x-show="state.discount > 0" class="text-emerald-400 font-semibold font-mono" x-text="`Hemat -${rupiah(state.discount)}`"></span>
                            </div>

                            {{-- Total Jumbo --}}
                            <div class="flex items-baseline justify-between gap-4">
                                <span class="text-xs sm:text-sm font-bold uppercase tracking-wider text-slate-400">Total Pembayaran</span>
                                <span class="text-3xl sm:text-5xl font-black text-slate-100 font-mono tabular-nums tracking-tight" x-text="rupiah(state.total)"></span>
                            </div>

                            {{-- Saat tahap pembayaran: Uang diterima & Kembalian --}}
                            <template x-if="stage === 'payment' && state.payment">
                                <div class="pt-3 border-t border-slate-800 space-y-2">
                                    <div x-show="state.payment.received > 0" class="flex justify-between items-center text-xs text-slate-300">
                                        <span>Uang Diterima</span>
                                        <span class="font-bold text-slate-100 font-mono text-sm" x-text="rupiah(state.payment.received)"></span>
                                    </div>
                                    <div x-show="state.payment.change > 0" class="p-3 rounded-xl bg-emerald-500/15 border border-emerald-500/40 flex justify-between items-center">
                                        <span class="text-xs uppercase font-extrabold text-emerald-300">Kembalian</span>
                                        <span class="text-2xl sm:text-3xl font-black text-emerald-400 font-mono tabular-nums" x-text="rupiah(state.payment.change)"></span>
                                    </div>
                                    <div x-show="state.payment.shortfall > 0 && (state.payment.received > 0 || state.payment.credit)" class="p-2.5 rounded-lg bg-amber-500/10 border border-amber-500/30 flex justify-between items-center text-xs text-amber-400 font-bold">
                                        <span x-text="state.payment.credit ? 'Dicatat Kasbon' : 'Kurang'"></span>
                                        <span class="font-mono text-sm" x-text="rupiah(state.payment.shortfall)"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- KONDISI 2: QRIS --}}
                    <div x-show="stage === 'qris'" class="flex-1 flex flex-col items-center justify-center p-6 text-center gap-4">
                        <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold uppercase tracking-wider">
                            Pindai QRIS
                        </span>
                        <div>
                            <p class="text-xs text-slate-400 uppercase font-semibold">Total Tagihan</p>
                            <p class="text-3xl sm:text-4xl font-black text-slate-100 font-mono mt-0.5" x-text="rupiah(state.qris?.amount)"></p>
                        </div>
                        <div class="p-4 rounded-2xl bg-white shadow-xl border-2 border-emerald-500/30">
                            <img :src="qrisSrc" alt="QRIS" class="w-48 sm:w-56 aspect-square [image-rendering:pixelated]">
                        </div>
                        <p class="text-xs text-slate-400 max-w-xs">Nominal sudah terisi otomatis di aplikasi m-Banking / E-Wallet Anda.</p>
                    </div>

                    {{-- KONDISI 3: DONE (Terima kasih) --}}
                    <div x-show="stage === 'done'" class="flex-1 flex flex-col items-center justify-center p-6 text-center gap-4">
                        <div class="w-16 h-16 rounded-2xl bg-emerald-500/20 border border-emerald-500 text-emerald-400 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                            <i data-lucide="check" class="w-8 h-8 stroke-[3]"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl sm:text-3xl font-extrabold text-slate-100">Terima Kasih!</h3>
                            <p class="text-xs sm:text-sm text-slate-400 mt-1">Transaksi Anda telah selesai diproses.</p>
                        </div>
                        <template x-if="state.done?.change > 0">
                            <div class="p-4 rounded-2xl bg-emerald-500/15 border border-emerald-500/40 w-full max-w-xs">
                                <p class="text-xs font-bold text-emerald-300 uppercase tracking-wider">Kembalian Anda</p>
                                <p class="text-3xl font-black text-emerald-400 font-mono mt-1" x-text="rupiah(state.done.change)"></p>
                            </div>
                        </template>
                        <p class="text-xs text-slate-500 font-mono" x-text="`Struk: ${state.done?.number}`"></p>
                    </div>

                </div>
            </main>

            {{-- FOOTER: Ticker running promo --}}
            @if (filled($config['promo']))
                <footer class="shrink-0 h-10 overflow-hidden border-t border-slate-200 dark:border-slate-800 bg-white/95 dark:bg-slate-900/95 text-emerald-600 dark:text-emerald-400 flex items-center z-30 pb-[env(safe-area-inset-bottom)] shadow-sm">
                    <div class="display-ticker flex whitespace-nowrap text-xs font-semibold text-slate-700 dark:text-slate-200">
                        @for ($i = 0; $i < 2; $i++)
                            <span class="px-8 flex items-center gap-2">
                                <i data-lucide="megaphone" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400"></i>
                                <span>{{ $config['promo'] }}</span>
                            </span>
                            <span class="px-8 flex items-center gap-2" aria-hidden="true">
                                <i data-lucide="megaphone" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400"></i>
                                <span>{{ $config['promo'] }}</span>
                            </span>
                        @endfor
                    </div>
                </footer>
            @endif

            {{-- Fullscreen button --}}
            <button type="button" @click="toggleFullscreen()" x-show="controlsVisible" x-transition.opacity
                class="fixed right-4 bottom-14 z-40 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/90 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold shadow-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition cursor-pointer">
                <i :data-lucide="isFullscreen ? 'minimize' : 'maximize'" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400"></i>
                <span x-text="isFullscreen ? 'Keluar' : 'Layar Penuh'"></span>
            </button>
        </div>

        @livewireScripts
    </body>
</html>
