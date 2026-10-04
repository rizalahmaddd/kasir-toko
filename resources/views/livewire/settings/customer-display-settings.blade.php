@php
    $currentTenant = app(\App\Support\CurrentTenant::class)->get();
@endphp

<div>
@if ($currentTenant && ! $currentTenant->isPro())
    <x-pro-paywall
        title="Layar Pelanggan (Customer Display) Khusus Pro"
        feature="Layar Pelanggan (Customer Display)"
        description="Gunakan monitor kedua atau smartphone/tablet sebagai layar interaktif pembeli dengan QRIS dinamis, slideshow promo, dan rincian struk real-time."
        icon="monitor-smartphone"
    />
@else
<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <p class="text-xs text-slate-400 max-w-2xl">Layar kedua yang menghadap pembeli. Kasir menghubungkannya dari tombol <strong class="text-slate-300">Layar Pelanggan</strong> di layar kasir: sebagai jendela di monitor kedua, atau di tablet/HP lain dengan memindai QR.</p>
        <x-secondary-button wire:click="openPreview" class="shrink-0">
            <i data-lucide="external-link" class="w-4 h-4"></i> Buka Layar Saya
        </x-secondary-button>
    </div>

    <form wire:submit="save" class="grid lg:grid-cols-[minmax(0,1fr)_24rem] gap-4 sm:gap-6 items-start">
        <div class="space-y-4 sm:space-y-5">
            <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="monitor-smartphone" class="w-4 h-4 text-slate-400"></i> Tampilan</h3>
                <x-checkbox-card wire:model.live="enabled" label="Aktifkan layar pelanggan" description="Kalau dimatikan, tombol Layar Pelanggan hilang dari kasir dan layar yang terhubung berhenti menerima data." />

                <div>
                    <x-input-label value="Tema" />
                    <x-segmented class="w-full sm:w-auto sm:inline-flex [&>*]:flex-1">
                        <x-tab-button :active="$theme === 'dark'" wire:click="$set('theme', 'dark')" icon="moon">Gelap</x-tab-button>
                        <x-tab-button :active="$theme === 'light'" wire:click="$set('theme', 'light')" icon="sun">Terang</x-tab-button>
                    </x-segmented>
                    <p class="text-[11px] text-slate-400 mt-1">Gelap lebih nyaman dilihat di toko yang redup dan menghemat baterai tablet OLED.</p>
                </div>

                <x-checkbox-card wire:model.live="showItems" label="Tampilkan daftar barang" description="Pembeli bisa mengecek setiap barang yang di-scan. Matikan kalau hanya ingin menampilkan total." />

                <div class="grid grid-cols-2 gap-3.5">
                    <div>
                        <x-input-label for="thankYouSeconds" value="Layar terima kasih (detik)" />
                        <x-text-input wire:model="thankYouSeconds" id="thankYouSeconds" type="number" min="2" max="60" class="w-full font-mono" />
                        <x-input-error :messages="$errors->get('thankYouSeconds')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="slideSeconds" value="Ganti slide tiap (detik)" />
                        <x-text-input wire:model="slideSeconds" id="slideSeconds" type="number" min="3" max="60" class="w-full font-mono" />
                        <x-input-error :messages="$errors->get('slideSeconds')" class="mt-1.5" />
                    </div>
                </div>
            </section>

            <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="megaphone" class="w-4 h-4 text-slate-400"></i> Sambutan & promo</h3>
                <div>
                    <x-input-label for="welcome" value="Kalimat sambutan *" />
                    <x-text-input wire:model.live.debounce.400ms="welcome" id="welcome" maxlength="150" class="w-full" />
                    <x-input-error :messages="$errors->get('welcome')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="promoText" value="Teks promo berjalan" />
                    <x-textarea wire:model.live.debounce.400ms="promoText" id="promoText" rows="2" maxlength="300" placeholder="Mis. Beli 2 kopi gratis 1 roti setiap Jumat · Terima QRIS semua bank" />
                    <p class="text-[11px] text-slate-400 mt-1">Bergulir di bagian bawah layar. Kosongkan untuk menyembunyikan.</p>
                    <x-input-error :messages="$errors->get('promoText')" class="mt-1.5" />
                </div>

                <div class="space-y-2">
                    <x-input-label value="Slideshow saat layar diam ({{ count($slides) }}/{{ \App\Support\CustomerDisplaySettings::MAX_SLIDES }})" />
                    @if ($slides)
                        <ul class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            @foreach ($slides as $index => $slide)
                                <li wire:key="slide-{{ md5($slide) }}" class="relative rounded-lg overflow-hidden border border-slate-800 bg-slate-950 aspect-video group">
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($slide) }}" alt="Slide {{ $index + 1 }}" class="w-full h-full object-cover">
                                    <div class="absolute inset-x-0 bottom-0 flex justify-between bg-slate-950/80">
                                        <div class="flex">
                                            <x-icon-button icon="chevron-left" :label="'Geser slide '.($index + 1).' ke kiri'" wire:click="moveSlide({{ $index }}, -1)" :disabled="$index === 0" />
                                            <x-icon-button icon="chevron-right" :label="'Geser slide '.($index + 1).' ke kanan'" wire:click="moveSlide({{ $index }}, 1)" :disabled="$loop->last" />
                                        </div>
                                        <x-icon-button icon="trash-2" tone="danger" :label="'Hapus slide '.($index + 1)" wire:click="removeSlide({{ $index }})" />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (count($slides) < \App\Support\CustomerDisplaySettings::MAX_SLIDES)
                        <x-file-input wire:model="newSlides" multiple accept="image/*" icon="images" label="Tambah gambar promo" hint="Bisa pilih beberapa sekaligus. Ukuran ideal 1920×1080 (landscape), maks. 4 MB per gambar." />
                    @endif
                    <x-input-error :messages="$errors->get('newSlides')" />
                    <x-input-error :messages="$errors->get('newSlides.*')" />
                    <p class="text-[11px] text-slate-400">Tanpa gambar, layar diam menampilkan logo, nama toko, dan sambutan.</p>
                </div>
            </section>

            <div class="flex justify-end">
                <x-primary-button wire:loading.attr="disabled" wire:target="save,newSlides" class="w-full sm:w-auto">
                    <x-loading-label target="save" loading="Menyimpan...">Simpan Pengaturan Layar</x-loading-label>
                </x-primary-button>
            </div>
        </div>

        {{-- Pratinjau: titik fokus halaman --}}
        <aside class="lg:sticky lg:top-4 space-y-2">
            <p class="text-xs font-semibold text-slate-400">Pratinjau layar diam</p>
            <div @class([
                'rounded-2xl overflow-hidden shadow-xl shadow-slate-950/40 border aspect-video flex flex-col',
                'bg-[#020617] border-[#1e293b] text-[#f1f5f9]' => $theme === 'dark',
                'bg-[#f8fafc] border-[#e2e8f0] text-[#0f172a]' => $theme === 'light',
            ])>
                <div class="relative flex-1 min-h-0 flex flex-col items-center justify-center text-center p-4 gap-1.5">
                    @if ($slides)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($slides[0]) }}" alt="" class="absolute inset-0 w-full h-full object-cover">
                        <div class="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-black/80 to-transparent"></div>
                        <p class="relative mt-auto self-start text-left text-sm font-extrabold text-white leading-tight">{{ $welcome }}</p>
                    @else
                        <p class="text-base font-extrabold">{{ \App\Support\Branding::companyName() }}</p>
                        <p class="text-[11px] opacity-70 leading-snug">{{ $welcome }}</p>
                    @endif
                </div>
                @if (filled($promoText))
                    <div class="shrink-0 bg-[#059669] text-[#020617] text-[10px] font-bold px-3 py-1 truncate">{{ $promoText }}</div>
                @endif
            </div>
            <p class="text-[11px] text-slate-400">Saat kasir menambah barang, layar berganti ke daftar belanja dan total. Saat pembayaran QRIS, QR bernominal tampil besar di tengah.</p>
        </aside>
    </form>
</div>
@endif
</div>
