@php
    $tabs = [
        'branding' => ['label' => 'Branding', 'icon' => 'palette', 'hint' => 'Nama & logo aplikasi'],
        'kop-surat' => ['label' => 'Kop Surat', 'icon' => 'building-2', 'hint' => 'Identitas di dokumen cetak'],
    ];
    $previewLogoUrl = $logo && ! $errors->has('logo') ? $logo->temporaryUrl() : null;
@endphp

<div class="space-y-4 sm:space-y-6">
    <p class="text-xs sm:text-sm text-slate-400 max-w-3xl">
        Semua identitas yang tampil di aplikasi dan dokumen cetak diambil dari halaman ini. Perubahan berlaku untuk seluruh pengguna begitu disimpan.
    </p>

    {{-- Bagian pengaturan. Pilihan tersimpan di URL (?tab=) supaya bertahan saat reload dan bisa dibagikan. --}}
    <div role="tablist" aria-label="{{ __('Bagian pengaturan') }}"
        class="flex gap-1 p-1 rounded-xl bg-slate-900 border border-slate-800 overflow-x-auto">
        @foreach ($tabs as $key => $item)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                @class([
                    'flex-1 min-w-[8.5rem] flex items-center gap-2.5 px-3 py-2 rounded-lg text-left min-h-[44px] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500',
                    'bg-slate-800 text-slate-100' => $tab === $key,
                    'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' => $tab !== $key,
                ])>
                <i data-lucide="{{ $item['icon'] }}" @class(['w-4 h-4 shrink-0', 'text-emerald-400' => $tab === $key])></i>
                <span class="min-w-0">
                    <span class="block text-xs font-semibold">{{ $item['label'] }}</span>
                    <span class="hidden sm:block text-[11px] text-slate-400 truncate">{{ $item['hint'] }}</span>
                </span>
            </button>
        @endforeach
    </div>

    @if ($tab === 'branding')
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6 items-start" wire:key="tab-branding">
            <form wire:submit="saveBranding" class="lg:col-span-7 bg-slate-900 border border-slate-800 rounded-xl">
                <div class="p-4 sm:p-6 space-y-5">
                    <div>
                        <h2 class="font-bold text-sm text-slate-100">Branding Aplikasi</h2>
                        <p class="text-xs text-slate-400 mt-0.5">Tampil di sidebar, halaman login, dan judul tab browser.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="app_name" value="Nama Aplikasi *" />
                            <x-text-input wire:model.live.debounce.150ms="app_name" id="app_name" type="text" class="w-full mt-1" placeholder="Nama singkat yang dikenal staf" />
                            <x-input-error :messages="$errors->get('app_name')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="app_tagline" value="Tagline" />
                            <x-text-input wire:model.live.debounce.150ms="app_tagline" id="app_tagline" type="text" class="w-full mt-1" placeholder="Keterangan singkat di bawah nama" />
                            <x-input-error :messages="$errors->get('app_tagline')" class="mt-1.5" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="logo" value="Logo" />
                        <div class="mt-1 flex flex-col sm:flex-row sm:items-center gap-3 p-3 rounded-lg border border-slate-800">
                            <div class="shrink-0">
                                @if ($previewLogoUrl)
                                    <img src="{{ $previewLogoUrl }}" alt="" class="w-14 h-14 rounded-lg object-contain bg-white p-1">
                                @else
                                    <x-brand-mark size="w-8 h-8" padding="p-3" radius="rounded-lg" />
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <x-file-input wire:model="logo" id="logo" accept="image/png,image/jpeg,image/webp" :file="$logo" icon="image-up" label="Pilih logo" hint="PNG, JPG, atau WebP, maks. 1 MB. Bentuk persegi paling rapi." />
                            </div>
                            @if (\App\Support\Branding::logoUrl() && ! $logo)
                                <button type="button" wire:click="removeLogo"
                                    class="shrink-0 self-start sm:self-center text-xs font-semibold text-rose-400 hover:text-rose-300 min-h-[44px] px-2 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">
                                    Hapus logo
                                </button>
                            @endif
                        </div>
                        <x-input-error :messages="$errors->get('logo')" class="mt-1.5" />
                    </div>
                </div>

                <div class="px-4 sm:px-6 py-3 border-t border-slate-800 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveBranding,logo" class="min-h-[44px] px-6">
                        <span wire:loading.remove wire:target="saveBranding">Simpan Branding</span>
                        <span wire:loading wire:target="saveBranding">Menyimpan...</span>
                    </x-primary-button>
                </div>
            </form>

            {{-- Titik fokus tab ini (DESIGN.md): pratinjau tempat branding benar-benar muncul. --}}
            <aside class="lg:col-span-5 bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-6 space-y-4 shadow-xl">
                <h2 class="font-bold text-sm text-slate-100">Pratinjau</h2>

                <div class="space-y-1.5">
                    <p class="text-[11px] font-semibold text-slate-400">Tab browser</p>
                    <div class="flex items-center gap-2 px-3 py-2 rounded-t-lg bg-slate-800 border border-slate-700 text-xs text-slate-200 max-w-xs">
                        @if ($favicon = $previewLogoUrl ?? \App\Support\Branding::logoUrl())
                            <img src="{{ $favicon }}" alt="" class="w-4 h-4 object-contain shrink-0">
                        @else
                            <i data-lucide="globe" class="w-4 h-4 text-slate-400 shrink-0"></i>
                        @endif
                        <span class="truncate">Dashboard - {{ $app_name ?: 'Nama Aplikasi' }}</span>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <p class="text-[11px] font-semibold text-slate-400">Sidebar</p>
                    <div class="flex items-center gap-2.5 p-3 rounded-lg bg-slate-950 border border-slate-800">
                        @if ($previewLogoUrl)
                            <img src="{{ $previewLogoUrl }}" alt="" class="w-8 h-8 rounded-lg object-contain bg-white p-0.5 shrink-0">
                        @else
                            <x-brand-mark size="w-5 h-5" padding="p-1.5" radius="rounded-lg" class="shrink-0" />
                        @endif
                        <div class="min-w-0">
                            <div class="font-bold text-slate-100 text-sm leading-tight truncate">{{ $app_name ?: 'Nama Aplikasi' }}</div>
                            @if ($app_tagline)
                                <div class="text-[10px] text-slate-400 font-medium truncate">{{ $app_tagline }}</div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <p class="text-[11px] font-semibold text-slate-400">Halaman login</p>
                    <div class="p-4 rounded-lg bg-slate-950 border border-slate-800 flex flex-col items-center text-center gap-2">
                        @if ($previewLogoUrl)
                            <img src="{{ $previewLogoUrl }}" alt="" class="w-12 h-12 rounded-xl object-contain bg-white p-1">
                        @else
                            <x-brand-mark />
                        @endif
                        <div>
                            <div class="font-bold text-slate-100 text-sm">{{ $app_name ?: 'Nama Aplikasi' }}</div>
                            @if ($app_tagline)
                                <div class="text-[11px] text-slate-400">{{ $app_tagline }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    @elseif ($tab === 'kop-surat')
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6 items-start" wire:key="tab-kop-surat">
            <form wire:submit="save" class="lg:col-span-5 bg-slate-900 border border-slate-800 rounded-xl">
                <div class="p-4 sm:p-6 space-y-5">
                    <div>
                        <h2 class="font-bold text-sm text-slate-100">Kop Surat</h2>
                        <p class="text-xs text-slate-400 mt-0.5">Dicetak di kepala semua dokumen cetak dan ekspor PDF.</p>
                    </div>

                    <div>
                        <x-input-label for="company_name" value="Nama Resmi Perusahaan *" />
                        <x-text-input wire:model.live.debounce.150ms="company_name" id="company_name" type="text" class="w-full mt-1 font-semibold uppercase tracking-wide" placeholder="Contoh: PT Nama Perusahaan Anda" />
                        <x-input-error :messages="$errors->get('company_name')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="company_tagline" value="Slogan / Bidang Usaha" />
                        <x-text-input wire:model.live.debounce.150ms="company_tagline" id="company_tagline" type="text" class="w-full mt-1" placeholder="Contoh: Pemasok bahan baku industri" />
                        <x-input-error :messages="$errors->get('company_tagline')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="company_address" value="Alamat" />
                        <x-textarea wire:model.live.debounce.150ms="company_address" id="company_address" rows="3" placeholder="Alamat kantor pusat" class="mt-1" />
                        <x-input-error :messages="$errors->get('company_address')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="company_phone" value="Telepon" />
                        <x-text-input wire:model.live.debounce.150ms="company_phone" id="company_phone" type="text" class="w-full mt-1 font-mono" placeholder="Nomor kantor yang bisa dihubungi" />
                        <x-input-error :messages="$errors->get('company_phone')" class="mt-1.5" />
                    </div>
                </div>

                <div class="px-4 sm:px-6 py-3 border-t border-slate-800 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save" class="min-h-[44px] px-6">
                        <span wire:loading.remove wire:target="save">Simpan Kop Surat</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-primary-button>
                </div>
            </form>

            {{-- Titik fokus tab ini: lembar kertas putih seperti hasil cetak (DESIGN.md "Tema"). --}}
            <aside class="lg:col-span-7 bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-6 space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-bold text-sm text-slate-100">Pratinjau Kop Surat</h2>
                    <span class="text-[11px] text-slate-400">Contoh: Kartu Pelanggan</span>
                </div>

                <div class="paper-sheet bg-white text-slate-900 rounded-lg p-4 sm:p-6 shadow-2xl border border-slate-300 font-sans select-none">
                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4 border-b-2 border-slate-900 pb-4 mb-4">
                        <div class="space-y-1 min-w-0 flex-1">
                            <h3 class="font-black text-lg text-slate-900 tracking-wide uppercase leading-tight break-words">
                                {{ $company_name ?: 'Nama Perusahaan' }}
                            </h3>
                            @if ($company_tagline)
                                <p class="text-xs text-slate-600 font-medium">{{ $company_tagline }}</p>
                            @endif
                            @if ($company_address || $company_phone)
                                <p class="text-[11px] text-slate-600 leading-relaxed">
                                    {{ $company_address }}
                                    @if ($company_address && $company_phone)
                                        <span class="mx-1 text-slate-400">&bull;</span>
                                    @endif
                                    @if ($company_phone)
                                        <span class="font-mono">Telp: {{ $company_phone }}</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="text-left sm:text-right shrink-0">
                            <div class="doc-title-badge no-dark-invert inline-block bg-slate-900 !text-white font-mono font-extrabold text-xs px-3 py-1.5 rounded tracking-wider">
                                KARTU PELANGGAN
                            </div>
                            <div class="text-[11px] text-slate-500 font-mono mt-2">Tgl: {{ now()->translatedFormat('d M Y') }}</div>
                        </div>
                    </div>

                    {{-- Isi dokumen hanya kerangka abu-abu: yang dipratinjau di sini kop suratnya. --}}
                    <div class="space-y-2.5" aria-hidden="true">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="h-12 rounded bg-slate-100"></div>
                            <div class="h-12 rounded bg-slate-100"></div>
                        </div>
                        <div class="h-8 rounded bg-slate-100"></div>
                        <div class="h-8 rounded bg-slate-100 w-2/3"></div>
                    </div>
                </div>
            </aside>
        </div>
    @endif
</div>
