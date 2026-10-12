<x-layouts.self-order :title="$storeName.' - Pesan Menu'" :logo="$logo" :captcha="(bool) $captchaSiteKey">
    <div x-data="selfOrderMenu(@js($config))" x-cloak class="mx-auto max-w-xl min-h-dvh flex flex-col bg-slate-950 text-slate-100">
        {{-- TOP APP BAR --}}
        <header class="sticky top-0 z-20 border-b border-slate-700/80 bg-slate-900/95 backdrop-blur-md px-4 pt-[calc(0.75rem+env(safe-area-inset-top))] pb-3 shadow-xs">
            <div class="flex items-center gap-3">
                <button type="button" x-show="view !== 'menu'" @click="backToMenu()"
                    class="-ml-1 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-700 bg-slate-900 text-slate-200 shadow-2xs hover:bg-slate-800 active:scale-95 transition"
                    aria-label="Kembali ke menu">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </button>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <h1 class="truncate text-base font-extrabold text-slate-100 tracking-tight">{{ $storeName }}</h1>
                    </div>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        @if ($table)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30 text-[11px] font-bold">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                Meja {{ $table->name }} · Makan di Tempat
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-700 dark:text-amber-400 border border-amber-500/30 text-[11px] font-bold">
                                Bawa Pulang (Take Away)
                            </span>
                        @endif
                        @if ($outletName && $outletName !== $storeName)
                            <span class="truncate text-[11px] text-slate-400 font-medium">· {{ $outletName }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </header>

        {{-- VIEW: MENU KATALOG --}}
        <main x-show="view === 'menu'" class="flex-1 px-4 pb-36 pt-3 space-y-3">
            {{-- Active Order Banner --}}
            <template x-if="activeOrder">
                <a :href="activeOrder.url" class="flex items-center justify-between gap-3 rounded-2xl border border-emerald-500/40 bg-emerald-500/10 p-3.5 shadow-xs hover:border-emerald-500/60 transition">
                    <div class="min-w-0 flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-slate-100" x-text="'Pesanan Aktif #' + activeOrder.number"></p>
                            <p class="text-[11px] text-slate-300 truncate" x-text="activeOrder.status_label + ' · Ketuk untuk memantau status'"></p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1 text-xs font-extrabold text-emerald-700 dark:text-emerald-400 bg-slate-900 px-2.5 py-1 rounded-lg border border-emerald-500/30 shrink-0 shadow-2xs">
                        Status <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                    </span>
                </a>
            </template>

            {{-- Closed / Pause Notification --}}
            <div x-show="!open && !loading" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 shadow-xs text-slate-200">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div class="min-w-0 text-xs">
                        <p class="font-extrabold text-slate-100 text-sm">Pemesanan Sedang Ditutup</p>
                        <p class="mt-0.5 text-slate-300 leading-relaxed" x-text="closedMessage"></p>
                    </div>
                </div>
            </div>

            {{-- Error Alert --}}
            <div x-show="error && view === 'menu'" role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-700 dark:text-rose-300 font-semibold" x-text="error"></div>

            {{-- Search Bar --}}
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                </div>
                <input id="menu-search" type="search" x-model.debounce.200ms="search" placeholder="Cari makanan atau minuman..." autocomplete="off"
                    class="h-11 w-full rounded-2xl border border-slate-700 bg-slate-900 pl-10 pr-4 text-sm text-slate-100 placeholder:text-slate-400 shadow-xs focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 transition">
            </div>

            {{-- Categories Filter Pills --}}
            <div x-show="categories.length > 1" class="-mx-4 flex items-center gap-2 overflow-x-auto px-4 py-1.5 scrollbar-none">
                <button type="button" @click="categoryId = null" :aria-pressed="categoryId === null"
                    :class="categoryId === null 
                        ? 'bg-emerald-600 text-white font-semibold shadow-xs border-emerald-600' 
                        : 'bg-slate-900 text-slate-300 border-slate-700/80 hover:border-slate-600 hover:text-slate-100 font-medium'"
                    class="inline-flex h-9 shrink-0 items-center rounded-full border px-4 text-xs whitespace-nowrap transition-all active:scale-95">Semua</button>
                <template x-for="category in categories" :key="category.id">
                    <button type="button" @click="categoryId = category.id" :aria-pressed="categoryId === category.id"
                        :class="categoryId === category.id 
                            ? 'bg-emerald-600 text-white font-semibold shadow-xs border-emerald-600' 
                            : 'bg-slate-900 text-slate-300 border-slate-700/80 hover:border-slate-600 hover:text-slate-100 font-medium'"
                        class="inline-flex h-9 shrink-0 items-center rounded-full border px-4 text-xs whitespace-nowrap transition-all active:scale-95" x-text="category.name"></button>
                </template>
            </div>

            {{-- Loading State --}}
            <div x-show="loading" class="py-24 text-center">
                <div class="inline-block w-8 h-8 border-3 border-emerald-600 border-t-transparent rounded-full animate-spin"></div>
                <p class="mt-3 text-xs font-semibold text-slate-400">Memuat daftar menu lezat...</p>
            </div>

            {{-- Load Error --}}
            <div x-show="loadError" class="py-20 text-center bg-slate-900 rounded-2xl border border-slate-700 p-6">
                <p class="text-sm font-bold text-slate-100">Menu belum bisa dimuat</p>
                <p class="text-xs text-slate-400 mt-1">Periksa koneksi internet Anda atau muat ulang halaman.</p>
                <button type="button" @click="loadMenu()" class="mt-4 inline-flex h-10 items-center rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white shadow-xs hover:bg-emerald-700">Muat Ulang Menu</button>
            </div>

            {{-- Empty Search / Empty Menu --}}
            <div x-show="!loading && !loadError && !visibleProducts.length" class="py-20 text-center bg-slate-900 rounded-2xl border border-slate-700 p-6">
                <div class="w-12 h-12 rounded-2xl bg-slate-800 text-slate-400 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </div>
                <p class="text-sm font-bold text-slate-100" x-text="products.length ? 'Menu tidak ditemukan' : 'Belum ada menu tersedia'"></p>
                <p class="text-xs text-slate-400 mt-1 max-w-xs mx-auto" x-text="products.length ? 'Coba gunakan kata kunci lain atau pilih kategori Semua.' : 'Silakan tanyakan langsung ke kasir untuk pesanan.'"></p>
            </div>

            {{-- Product Cards List --}}
            <div class="space-y-3 pt-1">
                <template x-for="product in visibleProducts" :key="product.id">
                    <div class="group relative rounded-2xl border border-slate-700/80 bg-slate-900 p-3.5 shadow-xs transition hover:border-slate-600 flex gap-3.5"
                        :class="isSoldOut(product) && 'opacity-60 bg-slate-900/60'">
                        {{-- Image Thumbnail --}}
                        <div class="relative h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-slate-800 border border-slate-700/80 shadow-2xs">
                            <template x-if="product.image">
                                <img :src="product.image" alt="" loading="lazy" class="h-full w-full object-cover">
                            </template>
                            <template x-if="!product.image">
                                <div class="flex h-full w-full items-center justify-center bg-slate-800 text-slate-400">
                                    <svg class="h-7 w-7 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 8h1a4 4 0 0 1 0 8h-1"/>
                                        <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>
                                        <line x1="6" y1="1" x2="6" y2="4"/>
                                        <line x1="10" y1="1" x2="10" y2="4"/>
                                        <line x1="14" y1="1" x2="14" y2="4"/>
                                    </svg>
                                </div>
                            </template>
                            <template x-if="isSoldOut(product)">
                                <div class="absolute inset-0 bg-slate-950/75 backdrop-blur-2xs flex items-center justify-center">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-white bg-rose-600 px-2 py-0.5 rounded-full">Habis</span>
                                </div>
                            </template>
                        </div>

                        {{-- Product Details (Top: Title & Hint, Bottom: Price & Button) --}}
                        <div class="min-w-0 flex-1 flex flex-col justify-between py-0.5">
                            <div>
                                <h2 class="text-sm font-semibold text-slate-100 leading-snug line-clamp-2" x-text="product.name"></h2>
                                <template x-if="product.variants && product.variants.length > 0">
                                    <p class="text-[11px] font-medium text-slate-400 mt-0.5">Ada varian</p>
                                </template>
                            </div>

                            <div class="mt-2.5 flex items-center justify-between gap-2">
                                <div class="flex items-baseline gap-1 min-w-0">
                                    <span class="text-sm sm:text-base font-bold text-emerald-600 dark:text-emerald-400 tabular-nums" x-text="displayPrice(product)"></span>
                                    <template x-if="product.unit">
                                        <span class="text-xs text-slate-400 font-normal" x-text="'/' + product.unit"></span>
                                    </template>
                                </div>

                                {{-- Action Button / Stepper --}}
                                <div class="shrink-0" x-show="open && !isSoldOut(product)">
                                    {{-- Plain item with no modifiers/variants: show +/- stepper when in cart --}}
                                    <template x-if="plainLine(product) && !product.variants.length && !product.modifier_groups.length">
                                        <div class="flex items-center gap-1 rounded-xl border border-slate-700 bg-slate-800 p-0.5 shadow-2xs">
                                            <button type="button" @click="decrement(plainLine(product))"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-900 border border-slate-700 text-slate-200 shadow-2xs hover:bg-slate-700 active:scale-95 transition"
                                                :aria-label="'Kurangi ' + product.name">
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                            </button>
                                            <span class="w-6 text-center text-xs font-bold tabular-nums text-slate-100" x-text="plainLine(product).quantity"></span>
                                            <button type="button" @click="increment(plainLine(product))"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600 text-white shadow-2xs hover:bg-emerald-700 active:scale-95 transition"
                                                :aria-label="'Tambah ' + product.name">
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                            </button>
                                        </div>
                                    </template>

                                    {{-- Item with options or not yet in cart: show + Tambah button --}}
                                    <template x-if="!(plainLine(product) && !product.variants.length && !product.modifier_groups.length)">
                                        <button type="button" @click="choose(product)"
                                            class="inline-flex h-8 items-center gap-1.5 rounded-xl bg-emerald-600 px-3 text-xs font-semibold text-white shadow-2xs hover:bg-emerald-700 active:scale-95 transition">
                                            <span>+ Tambah</span>
                                            <span x-show="qtyInFamily(product) > 0" class="rounded-full bg-white px-1.5 py-0.2 text-[10px] font-bold text-emerald-700 tabular-nums" x-text="qtyInFamily(product)"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </main>

        {{-- VIEW: OPTIONS & MODIFIERS (PILIHAN VARIAN / TAMBAHAN) --}}
        <template x-if="picker">
            <main x-show="view === 'options'" class="flex-1 px-4 pb-32 pt-3 space-y-4">
                <div class="rounded-2xl border border-slate-700 bg-slate-900 p-4 shadow-xs">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Pilihan Menu</span>
                    <h1 class="text-lg font-bold text-slate-100 mt-0.5" x-text="picker.product.name"></h1>
                </div>

                {{-- Varian --}}
                <template x-if="picker.product.variants.length">
                    <fieldset class="rounded-2xl border border-slate-700 bg-slate-900 p-4 shadow-xs">
                        <legend class="text-xs font-extrabold text-slate-100 uppercase tracking-wider">Pilih Varian *</legend>
                        <div class="mt-3 space-y-2">
                            <template x-for="variant in picker.product.variants" :key="variant.id">
                                <button type="button" @click="pickVariant(variant)" :disabled="variant.sold_out" :aria-pressed="picker.variant?.id === variant.id"
                                    :class="picker.variant?.id === variant.id ? 'border-emerald-600 bg-emerald-500/10 ring-1 ring-emerald-600' : 'border-slate-700 bg-slate-800 hover:border-slate-600'"
                                    class="flex min-h-[48px] w-full items-center justify-between gap-3 rounded-xl border px-3.5 py-2.5 text-left transition disabled:opacity-40">
                                    <span class="flex items-center gap-2.5">
                                        <span class="w-4 h-4 rounded-full border-2 flex items-center justify-center"
                                            :class="picker.variant?.id === variant.id ? 'border-emerald-600 bg-emerald-600' : 'border-slate-600'">
                                            <span x-show="picker.variant?.id === variant.id" class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                        </span>
                                        <span class="text-xs font-bold text-slate-100" x-text="variant.variant || variant.name"></span>
                                    </span>
                                    <span class="shrink-0 text-xs font-bold"
                                        :class="variant.sold_out ? 'text-rose-600 dark:text-rose-400' : 'text-slate-100'"
                                        x-text="variant.sold_out ? 'Habis' : rupiah(variant.price)"></span>
                                </button>
                            </template>
                        </div>
                    </fieldset>
                </template>

                {{-- Modifier Groups --}}
                <template x-for="group in pickerGroups()" :key="group.id">
                    <fieldset class="rounded-2xl border border-slate-700 bg-slate-900 p-4 shadow-xs">
                        <div class="flex items-baseline justify-between gap-2">
                            <legend class="text-xs font-extrabold text-slate-100 uppercase tracking-wider" x-text="group.name"></legend>
                            <span class="text-[11px] font-semibold text-slate-400" x-text="group.rule"></span>
                        </div>
                        <div class="mt-3 space-y-2">
                            <template x-for="modifier in group.modifiers" :key="modifier.id">
                                <button type="button" @click="toggleModifier(group, modifier)" :aria-pressed="isChosen(modifier)"
                                    :class="isChosen(modifier) ? 'border-emerald-600 bg-emerald-500/10 ring-1 ring-emerald-600' : 'border-slate-700 bg-slate-800 hover:border-slate-600'"
                                    class="flex min-h-[48px] w-full items-center justify-between gap-3 rounded-xl border px-3.5 py-2.5 text-left transition">
                                    <span class="flex items-center gap-2.5">
                                        <span class="w-4 h-4 rounded-md border-2 flex items-center justify-center"
                                            :class="isChosen(modifier) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-600'">
                                            <svg x-show="isChosen(modifier)" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                        </span>
                                        <span class="text-xs font-bold text-slate-100" x-text="modifier.name"></span>
                                    </span>
                                    <span class="shrink-0 text-xs font-semibold text-slate-300" x-text="modifier.price > 0 ? '+' + rupiah(modifier.price) : 'Gratis'"></span>
                                </button>
                            </template>
                        </div>
                    </fieldset>
                </template>

                {{-- Catatan Dapur --}}
                <div class="rounded-2xl border border-slate-700 bg-slate-900 p-4 shadow-xs">
                    <label for="picker-note" class="text-xs font-extrabold text-slate-100 uppercase tracking-wider">Catatan untuk Dapur (Opsional)</label>
                    <input id="picker-note" type="text" x-model="picker.note" maxlength="150" placeholder="Contoh: Es sedikit, tidak terlalu pedas"
                        class="mt-2 h-11 w-full rounded-xl border border-slate-700 bg-slate-800 px-3.5 text-xs text-slate-100 placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 transition">
                </div>

                {{-- Error --}}
                <p x-show="picker.error" role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-xs font-bold text-rose-600 dark:text-rose-300" x-text="picker.error"></p>

                {{-- Sticky Bottom Action --}}
                <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-700/80 bg-slate-900/95 backdrop-blur-md px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] shadow-lg">
                    <button type="button" @click="confirmPicker()"
                        class="mx-auto flex h-12 w-full max-w-xl items-center justify-center gap-2 rounded-2xl bg-emerald-600 text-sm font-bold text-white shadow-md shadow-emerald-600/30 hover:bg-emerald-700 active:scale-98 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-2z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                        <span x-text="pickerPrice === null ? 'Masukkan ke Keranjang' : 'Masukkan ke Keranjang · ' + rupiah(pickerPrice)"></span>
                    </button>
                </div>
            </main>
        </template>

        {{-- VIEW: KERANJANG & CHECKOUT --}}
        <main x-show="view === 'cart'" class="flex-1 px-4 pb-12 pt-3 space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-bold text-slate-100">Rincian Pesanan</h1>
                <button type="button" @click="backToMenu()" class="text-xs font-bold text-emerald-600 dark:text-emerald-400 hover:underline">+ Tambah Menu</button>
            </div>

            {{-- Cart Items Card --}}
            <div class="rounded-2xl border border-slate-700 bg-slate-900 shadow-xs divide-y divide-slate-800 overflow-hidden">
                <template x-for="line in cart.items" :key="line.key">
                    <div class="p-3.5 flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-slate-100 leading-snug" x-text="line.name"></p>
                            <p x-show="line.modifiers.length" class="text-xs text-slate-400 mt-0.5" x-text="line.modifiers.map((m) => m.name).join(', ')"></p>
                            <p x-show="line.note" class="text-xs text-amber-700 dark:text-amber-400 italic mt-0.5" x-text="'Catatan: ' + line.note"></p>
                            <p class="mt-1 text-xs font-bold text-emerald-600 dark:text-emerald-400 tabular-nums" x-text="rupiah(lineTotal(line))"></p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1 rounded-xl border border-slate-700 bg-slate-800 p-1">
                            <button type="button" @click="decrement(line)" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-900 border border-slate-700 text-slate-200 shadow-2xs hover:bg-slate-700 active:scale-95 transition" :aria-label="'Kurangi ' + line.name">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                            </button>
                            <span class="w-6 text-center text-xs font-bold tabular-nums text-slate-100" x-text="line.quantity"></span>
                            <button type="button" @click="increment(line)" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600 text-white shadow-2xs hover:bg-emerald-700 active:scale-95 transition" :aria-label="'Tambah ' + line.name">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Price Breakdown Receipt --}}
            <div class="rounded-2xl border border-slate-700 bg-slate-900 p-4 shadow-xs">
                <dl class="space-y-2 text-xs">
                    <div class="flex justify-between text-slate-300"><dt>Subtotal</dt><dd class="tabular-nums font-semibold text-slate-100" x-text="rupiah(totals.subtotal)"></dd></div>
                    <div x-show="totals.serviceAmount > 0" class="flex justify-between text-slate-300"><dt>Service charge</dt><dd class="tabular-nums font-semibold text-slate-100" x-text="rupiah(totals.serviceAmount)"></dd></div>
                    <div x-show="totals.taxAmount > 0" class="flex justify-between text-slate-300"><dt>{{ $config['taxLabel'] }}</dt><dd class="tabular-nums font-semibold text-slate-100" x-text="rupiah(totals.taxAmount)"></dd></div>
                    <div class="flex justify-between border-t border-slate-700 pt-2 text-sm font-bold text-slate-100">
                        <dt>Total Pembayaran</dt>
                        <dd class="tabular-nums text-emerald-600 dark:text-emerald-400 font-extrabold" x-text="rupiah(totals.total)"></dd>
                    </div>
                </dl>
            </div>

            {{-- Checkout Form --}}
            <form @submit.prevent="submit()" class="space-y-4" novalidate>
                <div class="rounded-2xl border border-slate-700 bg-slate-900 p-4 shadow-xs space-y-3.5">
                    <h2 class="text-xs font-extrabold uppercase tracking-wider text-slate-100">Informasi Pemesan</h2>

                    <div>
                        <label for="customer-name" class="block text-xs font-bold text-slate-200">Nama Pemesan *</label>
                        <input id="customer-name" x-ref="name" type="text" x-model="form.name" maxlength="100" autocomplete="given-name" required placeholder="Nama Anda"
                            class="mt-1 h-11 w-full rounded-xl border border-slate-700 bg-slate-800 px-3.5 text-xs text-slate-100 placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 transition">
                    </div>

                    <div>
                        <label for="customer-phone" class="block text-xs font-bold text-slate-200">Nomor WhatsApp <span class="font-normal text-slate-400">(opsional, untuk bukti pesanan)</span></label>
                        <input id="customer-phone" type="tel" inputmode="tel" x-model="form.phone" maxlength="25" autocomplete="tel" placeholder="08123456789"
                            class="mt-1 h-11 w-full rounded-xl border border-slate-700 bg-slate-800 px-3.5 text-xs text-slate-100 placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 transition">
                    </div>

                    <div>
                        <label for="order-notes" class="block text-xs font-bold text-slate-200">Catatan Khusus <span class="font-normal text-slate-400">(opsional)</span></label>
                        <textarea id="order-notes" x-model="form.notes" maxlength="200" rows="2" placeholder="Catatan untuk seluruh pesanan"
                            class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-800 px-3.5 py-2 text-xs text-slate-100 placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 transition"></textarea>
                    </div>

                    <div class="hidden" aria-hidden="true">
                        <label for="website">Website</label>
                        <input id="website" type="text" x-model="form.website" tabindex="-1" autocomplete="off">
                    </div>
                </div>

                {{-- Cara Bayar --}}
                <div class="rounded-2xl border border-slate-700 bg-slate-900 p-4 shadow-xs">
                    <fieldset>
                        <legend class="text-xs font-extrabold uppercase tracking-wider text-slate-100">Metode Pembayaran</legend>
                        @if ($config['qris'])
                            <div class="mt-3 space-y-2">
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition"
                                    :class="form.method === 'cashier' ? 'border-emerald-600 bg-emerald-500/10 ring-1 ring-emerald-600' : 'border-slate-700 bg-slate-800 hover:border-slate-600'">
                                    <input type="radio" name="method" value="cashier" x-model="form.method" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-slate-100">Bayar di Kasir</p>
                                        <p class="text-[11px] text-slate-400">{{ $config['payFirst'] ? 'Tunai atau kartu, saat pesanan dikonfirmasi.' : 'Dibayar setelah selesai makan di meja.' }}</p>
                                    </div>
                                </label>
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition"
                                    :class="form.method === 'qris' ? 'border-emerald-600 bg-emerald-500/10 ring-1 ring-emerald-600' : 'border-slate-700 bg-slate-800 hover:border-slate-600'">
                                    <input type="radio" name="method" value="qris" x-model="form.method" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <p class="text-xs font-bold text-slate-100">QRIS Sekarang</p>
                                            <span class="px-1.5 py-0.2 rounded bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30 text-[9px] font-black uppercase">Instan</span>
                                        </div>
                                        <p class="text-[11px] text-slate-400">Kode QRIS muncul langsung setelah pesanan dikirim.</p>
                                    </div>
                                </label>
                            </div>
                        @else
                            <div class="mt-2 text-xs text-slate-300 bg-slate-800 p-3 rounded-xl border border-slate-700">
                                {{ $config['payFirst'] ? 'Pembayaran dilakukan di kasir saat pesanan dikonfirmasi.' : 'Pembayaran dilakukan di kasir setelah selesai makan.' }}
                            </div>
                        @endif
                    </fieldset>
                </div>

                @if ($captchaSiteKey)
                    <div x-ref="captcha" class="flex justify-center"></div>
                @endif

                <p x-show="error" role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs font-bold text-rose-600 dark:text-rose-300" x-text="error"></p>

                <button type="submit" :disabled="submitting || !open"
                    class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-emerald-600 text-sm font-bold text-white shadow-md shadow-emerald-600/30 hover:bg-emerald-700 disabled:opacity-50 active:scale-98 transition">
                    <span x-text="submitting ? 'Mengirim pesanan...' : (open ? 'Kirim Pesanan · ' + rupiah(totals.total) : 'Pemesanan Sedang Ditutup')"></span>
                </button>
            </form>
        </main>

        {{-- FLOATING BOTTOM CART BAR (MENU VIEW) --}}
        <div x-show="view === 'menu' && cart.items.length"
            class="fixed inset-x-0 bottom-4 z-30 px-4 pointer-events-none flex justify-center pb-[env(safe-area-inset-bottom)]">
            <button type="button" @click="openCart()"
                class="pointer-events-auto mx-auto flex h-14 w-full max-w-lg items-center justify-between rounded-2xl bg-emerald-600 px-4 text-white shadow-xl shadow-slate-950/25 hover:bg-emerald-700 active:scale-[0.99] transition-all">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/15 text-white shrink-0">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-2z"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <path d="M16 10a4 4 0 0 1-8 0"/>
                        </svg>
                    </div>
                    <div class="text-left">
                        <div class="flex items-center gap-1.5">
                            <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-emerald-800 tabular-nums" x-text="itemCount + ' Menu'"></span>
                            <span class="text-xs font-semibold text-emerald-50">Keranjang</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2.5">
                    <span class="text-sm font-extrabold tracking-tight tabular-nums" x-text="rupiah(totals.total)"></span>
                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-white/20 text-white">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                    </div>
                </div>
            </button>
        </div>
    </div>
</x-layouts.self-order>
