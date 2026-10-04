@php
    $shift = $this->shift;
    $products = $this->products;
    $hasMore = $products->count() > $limit;
    $config = $this->config() + ['hasShift' => (bool) $shift];
@endphp

<div x-data="posCashier(@js($config))"
    @keydown.window="onKeydown($event)"
    @pos-shift-opened.window="config.hasShift = true"
    class="h-full flex flex-col">

    {{-- Bar atas --}}
    <header class="shrink-0 border-b border-slate-800/80 bg-slate-900 pt-[env(safe-area-inset-top)]">
        <div class="h-14 flex items-center gap-1.5 sm:gap-3 px-1.5 sm:px-4">
            <a href="{{ route('dashboard') }}" wire:navigate aria-label="Kembali ke menu utama" title="Menu utama"
                class="inline-flex items-center justify-center w-11 h-11 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800 shrink-0">
                <i data-lucide="layout-grid" class="w-5 h-5"></i>
            </a>

            <div class="min-w-0 flex-1">
                <h1 class="font-bold text-sm sm:text-base text-slate-100 leading-tight truncate">{{ \App\Support\Branding::appName() }}</h1>
                @if ($shift)
                    <a href="{{ route('shifts.show', $shift) }}" wire:navigate class="block text-[11px] text-slate-400 hover:text-slate-200 truncate">
                        <span class="font-mono">{{ $shift->number }}</span>
                        <span>· {{ auth()->user()->name }} · sejak {{ $shift->opened_at->isToday() ? $shift->opened_at->format('H:i') : $shift->opened_at->translatedFormat('d M H:i') }}</span>
                    </a>
                @else
                    <button type="button" @click="$dispatch('open-modal', 'open-shift')" class="text-[11px] font-semibold text-amber-400 hover:text-amber-300">Shift belum dibuka, ketuk untuk membuka</button>
                @endif
            </div>

            <div class="flex items-center gap-1 shrink-0">
                <span x-show="!online" x-cloak class="hidden sm:inline-flex"><x-badge color="amber">OFFLINE</x-badge></span>

                <button type="button" @click="openHeld()" title="Transaksi tertunda"
                    class="relative inline-flex items-center gap-2 h-11 px-2.5 sm:px-3 rounded-lg text-slate-300 hover:text-slate-100 hover:bg-slate-800 text-xs font-semibold">
                    <i data-lucide="clock" class="w-5 h-5"></i>
                    <span class="hidden lg:inline">Tertunda</span>
                    @if ($heldCount > 0)
                        <span class="absolute top-1 right-1 lg:static min-w-[18px] h-[18px] px-1 rounded-full bg-amber-500 text-slate-950 text-[10px] font-bold flex items-center justify-center">{{ $heldCount }}</span>
                    @endif
                </button>

                @if ($shift)
                    <button type="button" @click="$dispatch('open-modal', 'cash-movement')" title="Kas masuk / keluar"
                        class="inline-flex items-center gap-2 h-11 px-2.5 sm:px-3 rounded-lg text-slate-300 hover:text-slate-100 hover:bg-slate-800 text-xs font-semibold">
                        <i data-lucide="wallet" class="w-5 h-5"></i>
                        <span class="hidden lg:inline">Kas</span>
                    </button>
                @endif

                @php
                    $tenantForDisplay = app(\App\Support\CurrentTenant::class)->get();
                    $isProForDisplay = $tenantForDisplay?->isPro() ?? true;
                @endphp
                <button type="button" x-show="config.display.enabled" @click="openDisplayPanel()" title="Layar pelanggan"
                    class="inline-flex items-center gap-2 h-11 px-2.5 sm:px-3 rounded-lg text-slate-300 hover:text-slate-100 hover:bg-slate-800 text-xs font-semibold">
                    <i data-lucide="monitor-smartphone" class="w-5 h-5 {{ ! $isProForDisplay ? 'text-amber-400' : '' }}"></i>
                    <span class="hidden xl:inline">Layar Pelanggan</span>
                    @if (! $isProForDisplay)
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-extrabold uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-500/30">PRO</span>
                    @endif
                </button>

                <a href="{{ route('sales.index') }}" wire:navigate title="Riwayat transaksi"
                    class="hidden sm:inline-flex items-center gap-2 h-11 px-3 rounded-lg text-slate-300 hover:text-slate-100 hover:bg-slate-800 text-xs font-semibold">
                    <i data-lucide="receipt" class="w-5 h-5"></i>
                    <span class="hidden lg:inline">Riwayat</span>
                </a>

                <x-theme-toggle />
            </div>
        </div>

        <div x-show="!online" x-cloak class="px-4 py-1.5 bg-amber-500/10 border-t border-amber-500/30 text-[11px] text-amber-300 flex items-center gap-2">
            <i data-lucide="wifi-off" class="w-3.5 h-3.5 shrink-0"></i>
            <span>Koneksi terputus. Keranjang tetap tersimpan di perangkat ini; pembayaran bisa diproses lagi setelah tersambung.</span>
        </div>

        @if ($shift && ! $shift->opened_at->isToday())
            <div class="px-4 py-1.5 bg-amber-500/10 border-t border-amber-500/30 text-[11px] text-amber-300 flex items-center gap-2">
                <i data-lucide="triangle-alert" class="w-3.5 h-3.5 shrink-0"></i>
                <span>Shift ini dibuka {{ $shift->opened_at->translatedFormat('l, d M') }}. Kalau sudah ganti hari, tutup dulu di <a href="{{ route('shifts.show', $shift) }}" wire:navigate class="underline font-semibold">rekap shift</a> supaya laporan per hari rapi.</span>
            </div>
        @endif
    </header>

    <div class="flex-1 min-h-0 flex">
        {{-- Katalog produk --}}
        <section class="flex-1 min-w-0 flex flex-col">
            <div class="shrink-0 p-2.5 sm:p-4 pb-2 space-y-2.5">
                <div class="flex items-center gap-2">
                    <div class="relative flex-1 group">
                        <i data-lucide="scan-barcode" aria-hidden="true" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-emerald-400 transition-colors pointer-events-none"></i>
                        <input type="search" x-ref="search" autocomplete="off" enterkeyhint="search"
                            wire:model.live.debounce.300ms="search"
                            @keydown.enter.prevent="submitSearch($event.target)"
                            placeholder="Ketik nama produk, scan barcode, atau SKU..."
                            aria-label="Cari produk atau scan barcode"
                            class="w-full h-11 sm:h-12 bg-slate-900 border border-slate-800 rounded-xl pl-10 pr-12 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition shadow-inner">
                        <div class="hidden sm:flex items-center gap-1 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none">
                            <kbd class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-[10px] font-mono text-slate-400">F2</kbd>
                        </div>
                    </div>
                    <button type="button" x-show="camera.supported" x-cloak @click="startCamera()" title="Scan pakai kamera perangkat"
                        class="inline-flex items-center justify-center w-11 sm:w-12 h-11 sm:h-12 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-emerald-400 hover:border-emerald-500/40 shrink-0 transition active:scale-95 shadow-inner cursor-pointer">
                        <i data-lucide="camera" class="w-5 h-5"></i>
                        <span class="sr-only">Scan pakai kamera</span>
                    </button>
                </div>

                @if ($this->categories->isNotEmpty())
                    <div class="-mx-2.5 sm:-mx-4 px-2.5 sm:px-4 overflow-x-auto no-scrollbar">
                        <div class="flex items-center gap-1.5 w-max">
                            <x-tab-button size="sm" :active="$categoryId === null" wire:click="selectCategory(null)">Semua</x-tab-button>
                            @foreach ($this->categories as $category)
                                <x-tab-button size="sm" :active="$categoryId === $category->id" wire:click="selectCategory({{ $category->id }})" wire:key="cat-{{ $category->id }}">{{ $category->name }}</x-tab-button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="relative flex-1 min-h-0 overflow-y-auto overscroll-contain custom-scrollbar px-2.5 sm:px-4 pb-[calc(6rem+env(safe-area-inset-bottom))] md:pb-4">
                <div wire:loading.delay wire:target="search,selectCategory,loadMore" class="absolute inset-x-0 top-0 h-0.5 bg-emerald-500/60 z-10"></div>

                @if ($products->isEmpty())
                    <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
                        <x-empty-state
                            icon="package-search"
                            :title="$search !== '' ? 'Produk tidak ditemukan' : 'Belum ada produk aktif'"
                            :description="$search !== '' ? 'Periksa ejaan atau kode barcode-nya. Produk nonaktif tidak muncul di kasir.' : 'Tambahkan produk dulu di menu Produk supaya bisa dijual di sini.'"
                        >
                            @if ($search === '' && auth()->user()->can('manage-master-data'))
                                <x-feature-link :href="route('master-data.products')" wire:navigate class="inline-flex text-xs font-semibold text-emerald-400 hover:underline">Buka halaman Produk</x-feature-link>
                            @endif
                        </x-empty-state>
                    </div>
                @else
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] sm:grid-cols-[repeat(auto-fill,minmax(10.5rem,1fr))] gap-2.5 sm:gap-3">
                        @foreach ($products->take($limit) as $product)
                            @php
                                $stock = (float) $product->stock;
                                $allowNegative = \App\Support\PosSettings::allowNegativeStock();
                                $out = $product->track_stock && $stock <= 0 && ! $allowNegative;
                                $negativeStock = $product->track_stock && $stock <= 0 && $allowNegative;
                                $low = $product->track_stock && ! $out && ! $negativeStock && $stock <= (float) $product->min_stock;
                            @endphp
                            <button type="button" wire:key="product-{{ $product->id }}"
                                data-product="{{ json_encode(\App\Livewire\Pos\Cashier::productPayload($product)) }}"
                                @click="add(JSON.parse($el.dataset.product))"
                                @class([
                                    'group relative text-left rounded-xl border p-2 sm:p-2.5 flex flex-col gap-2 transition-all duration-150 active:scale-[0.97] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 cursor-pointer shadow-sm hover:shadow-md hover:border-slate-700/80 select-none',
                                    'bg-slate-900 border-slate-800/80 hover:bg-slate-800/60' => ! $out,
                                    'bg-slate-900/50 border-slate-800/60 opacity-60' => $out,
                                ])
                                :class="qtyInCart({{ $product->id }}) > 0 ? '!border-emerald-500/70 !bg-emerald-500/10 ring-1 ring-emerald-500/30' : ''">

                                {{-- Image / Badge Container --}}
                                <div class="relative w-full aspect-[4/3] rounded-lg overflow-hidden bg-slate-800 border border-slate-700/40">
                                    @if ($imageUrl = $product->imageUrl())
                                        <img src="{{ $imageUrl }}" alt="{{ $product->name }}" loading="lazy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
                                    @else
                                        <div class="w-full h-full bg-slate-800 flex flex-col items-center justify-center p-2">
                                            <span class="w-9 h-9 rounded-lg bg-slate-700/80 border border-slate-600/50 text-emerald-600 dark:text-emerald-400 text-xs font-bold flex items-center justify-center uppercase shadow-inner">
                                                {{ mb_substr($product->name, 0, 2) }}
                                            </span>
                                            <span class="text-[9px] text-slate-400 mt-1 truncate max-w-full font-mono">{{ $product->sku ?: 'ITEM' }}</span>
                                        </div>
                                    @endif

                                    @if ($out)
                                        <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-[1px] flex items-center justify-center">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-extrabold uppercase tracking-wider bg-rose-500 text-white shadow-sm">Habis</span>
                                        </div>
                                    @elseif ($negativeStock)
                                        <div class="absolute top-1.5 left-1.5">
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-500 text-slate-950 shadow-sm flex items-center gap-1">
                                                <span>Stok {{ \App\Support\NumberFormatter::quantity($stock) }}</span>
                                            </span>
                                        </div>
                                    @endif

                                    {{-- Cart counter badge --}}
                                    <div x-show="qtyInCart({{ $product->id }}) > 0" x-cloak
                                        class="absolute top-1.5 right-1.5 min-w-[24px] h-6 px-1.5 rounded-full bg-emerald-500 text-white text-xs font-black shadow-md flex items-center justify-center tabular-nums ring-2 ring-slate-900">
                                        <span x-text="quantity(qtyInCart({{ $product->id }}))"></span>
                                    </div>
                                </div>

                                {{-- Product Name --}}
                                <span class="text-xs sm:text-[13px] font-semibold text-slate-100 group-hover:text-emerald-600 dark:group-hover:text-white leading-snug line-clamp-2 min-h-[2rem]">
                                    {{ $product->name }}
                                </span>

                                {{-- Price & Stock Footer --}}
                                <span class="mt-auto flex items-end justify-between gap-1.5 pt-0.5 border-t border-slate-800/40">
                                    <span class="text-sm sm:text-[15px] font-extrabold text-emerald-600 dark:text-emerald-400 tabular-nums tracking-tight">
                                        {{ \App\Support\NumberFormatter::currency($product->price) }}
                                    </span>
                                    @if ($product->track_stock)
                                        <span @class([
                                            'text-[10px] tabular-nums whitespace-nowrap',
                                            'text-rose-400 font-bold' => $out,
                                            'text-amber-400 font-bold px-1.5 py-0.5 rounded bg-amber-500/10 border border-amber-500/20' => $negativeStock || $low,
                                            'text-slate-400 font-medium' => ! $out && ! $negativeStock && ! $low,
                                        ])>{{ $out ? 'Habis' : ($negativeStock ? 'Stok: '.\App\Support\NumberFormatter::quantity($stock).' '.$product->unit : \App\Support\NumberFormatter::quantity($stock).' '.$product->unit) }}</span>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>

                    @if ($hasMore)
                        <div class="pt-3 text-center">
                            <x-secondary-button wire:click="loadMore" wire:loading.attr="disabled">
                                <x-loading-label target="loadMore" loading="Memuat...">Tampilkan produk lainnya</x-loading-label>
                            </x-secondary-button>
                        </div>
                    @endif
                @endif
            </div>
        </section>

        {{-- Keranjang: panel kanan di tablet/laptop, lembar penuh di ponsel --}}
        <div wire:ignore class="contents">
            @include('livewire.pos.partials.cart')
        </div>
    </div>

    {{-- Ringkasan keranjang di ponsel --}}
    <div wire:ignore class="md:hidden fixed inset-x-0 bottom-0 z-30 p-2.5 pb-[calc(0.625rem+env(safe-area-inset-bottom))] bg-slate-950/95 backdrop-blur border-t border-slate-800">
        <button type="button" @click="cartOpen = true"
            class="w-full h-14 rounded-xl bg-emerald-600 text-slate-950 font-bold flex items-center gap-3 px-4 shadow-lg shadow-emerald-950/40 disabled:opacity-50"
            :class="!cart.items.length && 'bg-slate-800 !text-slate-400 shadow-none'">
            <span class="relative">
                <i data-lucide="shopping-basket" class="w-5 h-5"></i>
            </span>
            <span class="text-sm" x-text="cart.items.length ? `${quantity(itemCount)} barang` : 'Keranjang kosong'"></span>
            <span class="ml-auto text-base tabular-nums" x-text="rupiah(total)"></span>
            <i data-lucide="chevron-up" class="w-5 h-5"></i>
        </button>
    </div>

    <div wire:ignore>
        {{-- Pembayaran dulu: modal pelanggan yang dibuka dari dalamnya harus tampil di atasnya. --}}
        @include('livewire.pos.partials.payment')
        @include('livewire.pos.partials.modals')
    </div>

    {{-- Shift & kas: form Livewire biasa --}}
    <x-modal name="open-shift" :show="! $shift" max-width="md" focusable>
        <form wire:submit="openShift" class="p-5 sm:p-6 space-y-5">
            <x-modal-header title="Buka shift kasir" icon="wallet" closeable>
                Hitung uang di laci sebelum mulai berjualan. Angka ini jadi patokan saat tutup shift.
            </x-modal-header>

            <div>
                <x-input-label for="openingCash" value="Modal awal di laci" />
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">Rp</span>
                    <x-text-input wire:model="openingCash" id="openingCash" inputmode="numeric" pattern="[0-9]*" class="w-full pl-10 text-lg font-bold tabular-nums" placeholder="0" />
                </div>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    @foreach ([0, 100000, 200000, 300000, 500000] as $preset)
                        <x-secondary-button size="xs" wire:click="$set('openingCash', '{{ $preset }}')">{{ \App\Support\NumberFormatter::currency($preset) }}</x-secondary-button>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('openingCash')" class="mt-1.5" />
            </div>

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Nanti saja</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="openShift" loading="Membuka...">Buka Shift</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-modal>

    @if ($shift)
        <x-modal name="cash-movement" max-width="md" focusable>
            <form wire:submit="recordCash" class="p-5 sm:p-6 space-y-5">
                <x-modal-header title="Kas masuk / keluar" icon="wallet" closeable>
                    Catat uang yang keluar atau masuk laci di luar penjualan, supaya hitungan tutup shift tetap cocok.
                </x-modal-header>

                <x-segmented class="w-full [&>*]:flex-1">
                    <x-tab-button :active="$cashType === 'out'" wire:click="$set('cashType', 'out')" icon="arrow-up-right">Kas keluar</x-tab-button>
                    <x-tab-button :active="$cashType === 'in'" wire:click="$set('cashType', 'in')" icon="arrow-down-left">Kas masuk</x-tab-button>
                </x-segmented>

                <div>
                    <x-input-label for="cashAmount" value="Nominal" />
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">Rp</span>
                        <x-text-input wire:model="cashAmount" id="cashAmount" inputmode="numeric" pattern="[0-9]*" class="w-full pl-10 font-bold tabular-nums" placeholder="0" />
                    </div>
                    <x-input-error :messages="$errors->get('cashAmount')" class="mt-1.5" />
                </div>

                <div>
                    <x-input-label for="cashReason" value="Keperluan" />
                    <x-text-input wire:model="cashReason" id="cashReason" class="w-full" placeholder="Mis. beli galon, setor ke pemilik, tambah uang kecil" />
                    <x-input-error :messages="$errors->get('cashReason')" class="mt-1.5" />
                </div>

                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="recordCash" loading="Menyimpan...">Simpan Catatan Kas</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </x-modal>
    @endif
</div>
