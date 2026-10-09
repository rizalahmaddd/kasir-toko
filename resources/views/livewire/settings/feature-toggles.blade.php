<div class="space-y-5 sm:space-y-6">
    {{-- Header Section --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-2 border-b border-slate-800/80">
        <div>
            <div class="flex items-center gap-2">
                <x-badge color="sky"><i data-lucide="shield-check" class="w-3 h-3"></i> Superadmin Control</x-badge>
                <span class="text-xs text-slate-500 font-mono">Kill-Switch System</span>
            </div>
            <h1 class="text-lg sm:text-xl font-bold text-slate-100 flex items-center gap-2.5 mt-1.5">
                <span class="p-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 shrink-0">
                    <i data-lucide="sliders-horizontal" class="w-5 h-5"></i>
                </span>
                <span>{{ __('Pengaturan Sakelar Fitur Sistem') }}</span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-400 mt-1 max-w-3xl leading-relaxed">
                Sakelar darurat (kill-switch) untuk mengaktifkan atau menonaktifkan modul operasional secara global. Fitur yang dimatikan langsung disembunyikan dari menu dan diblokir dari semua akun pengguna tanpa menghapus data atau hak akses yang sudah ada.
            </p>
        </div>

        <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto">
            @if ($changesCount > 0)
                <x-secondary-button size="sm" type="button" wire:click="resetChanges">
                    <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                    {{ __('Batal Perubahan') }} ({{ $changesCount }})
                </x-secondary-button>
            @endif
            <x-secondary-button size="sm" tone="emerald" type="button" wire:click="enableAll">
                <i data-lucide="check-check" class="w-3.5 h-3.5"></i>
                {{ __('Nyalakan Semua Fitur') }}
            </x-secondary-button>
        </div>
    </div>

    {{-- Stats KPI Bar (Ops Control Room Focal Block) --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        {{-- Card 1: Status Proteksi --}}
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-3.5 sm:p-4 flex items-center gap-3.5">
            <div @class([
                'w-10 h-10 rounded-xl flex items-center justify-center shrink-0 border',
                'bg-emerald-500/10 text-emerald-400 border-emerald-500/20 shadow-sm shadow-emerald-500/10' => $disabledFeaturesCount === 0,
                'bg-amber-500/10 text-amber-400 border-amber-500/20 shadow-sm shadow-amber-500/10' => $disabledFeaturesCount > 0,
            ])>
                <i data-lucide="{{ $disabledFeaturesCount === 0 ? 'shield-check' : 'shield-alert' }}" class="w-5 h-5"></i>
            </div>
            <div class="min-w-0">
                <span class="block text-[11px] font-medium text-slate-400 uppercase tracking-wider">{{ __('Status Sistem') }}</span>
                <span class="block text-sm sm:text-base font-bold text-slate-100 truncate">
                    {{ $disabledFeaturesCount === 0 ? __('Semua Beroperasi') : __(':count Fitur Dibatasi', ['count' => $disabledFeaturesCount]) }}
                </span>
                <span class="block text-[10px] text-slate-500 truncate">
                    {{ $disabledFeaturesCount === 0 ? __('100% modul beroperasi penuh') : __('Sebagian modul dinonaktifkan') }}
                </span>
            </div>
        </div>

        {{-- Card 2: Modul Aktif --}}
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-3.5 sm:p-4 flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-xl bg-slate-800/80 border border-slate-700/60 text-slate-300 flex items-center justify-center shrink-0">
                <i data-lucide="layers" class="w-5 h-5"></i>
            </div>
            <div class="min-w-0">
                <span class="block text-[11px] font-medium text-slate-400 uppercase tracking-wider">{{ __('Modul Aktif') }}</span>
                <span class="block text-sm sm:text-base font-bold text-slate-100">
                    {{ $activeModulesCount }} <span class="text-xs text-slate-400 font-normal">/ {{ $totalModules }} modul</span>
                </span>
                <span class="block text-[10px] text-slate-500 truncate">
                    {{ $activeModulesCount === $totalModules ? __('Seluruh modul terbuka') : __(':count modul dimatikan', ['count' => $totalModules - $activeModulesCount]) }}
                </span>
            </div>
        </div>

        {{-- Card 3: Total Sub-Fitur --}}
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-3.5 sm:p-4 flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                <i data-lucide="check-circle-2" class="w-5 h-5"></i>
            </div>
            <div class="min-w-0">
                <span class="block text-[11px] font-medium text-slate-400 uppercase tracking-wider">{{ __('Sub-Fitur Aktif') }}</span>
                <span class="block text-sm sm:text-base font-bold text-slate-100">
                    {{ $activeFeaturesCount }} <span class="text-xs text-slate-400 font-normal">/ {{ $totalFeatures }} fitur</span>
                </span>
                <span class="block text-[10px] text-slate-500 truncate">
                    {{ round(($activeFeaturesCount / max($totalFeatures, 1)) * 100) }}% fungsionalitas berjalan
                </span>
            </div>
        </div>

        {{-- Card 4: Perubahan --}}
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-3.5 sm:p-4 flex items-center gap-3.5">
            <div @class([
                'w-10 h-10 rounded-xl flex items-center justify-center shrink-0 border',
                'bg-slate-800/80 text-slate-400 border-slate-700/60' => $changesCount === 0,
                'bg-amber-500/20 text-amber-300 border-amber-500/40 animate-pulse' => $changesCount > 0,
            ])>
                <i data-lucide="{{ $changesCount > 0 ? 'file-edit' : 'check' }}" class="w-5 h-5"></i>
            </div>
            <div class="min-w-0">
                <span class="block text-[11px] font-medium text-slate-400 uppercase tracking-wider">{{ __('Perubahan') }}</span>
                <span class="block text-sm sm:text-base font-bold text-slate-100">
                    @if ($changesCount > 0)
                        <span class="text-amber-400">{{ $changesCount }} sakelar diubah</span>
                    @else
                        <span>Tersimpan</span>
                    @endif
                </span>
                <span class="block text-[10px] text-slate-500 truncate">
                    {{ $changesCount > 0 ? __('Menunggu klik Simpan') : __('Sesuai konfigurasi sistem') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Search & Interactive Filter Toolbar --}}
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 sm:p-4 space-y-3 shadow-sm">
        <div class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
            {{-- Search Bar --}}
            <x-search-input class="flex-1 max-w-lg" variant="form" wire:model.live.debounce.150ms="search" placeholder="Cari modul, fitur, atau kata kunci (misal: PO, Surat Jalan, SPK, Stok, Payroll, Tutup Buku)..." aria-label="Cari modul atau fitur" :clearable="$search !== ''" />

            {{-- Category & Status Filters --}}
            <div class="flex flex-wrap items-center gap-2">
                {{-- Category Filter --}}
                <div class="shrink-0">
                    <x-select wire:model.live="categoryFilter" class="text-xs py-1.5 min-w-[170px]">
                        <option value="all">{{ __('Semua Kategori') }}</option>
                        @foreach ($categories as $catKey => $catLabel)
                            <option value="{{ $catKey }}">{{ $catLabel }}</option>
                        @endforeach
                    </x-select>
                </div>

                {{-- Status Filter Buttons --}}
                <x-segmented class="shrink-0">
                    <x-tab-button size="sm" wire:click="$set('statusFilter', 'all')" :active="$statusFilter === 'all'">{{ __('Semua') }}</x-tab-button>
                    <x-tab-button size="sm" wire:click="$set('statusFilter', 'active')" :active="$statusFilter === 'active'">{{ __('Aktif') }}</x-tab-button>
                    <x-tab-button size="sm" wire:click="$set('statusFilter', 'inactive')" :active="$statusFilter === 'inactive'">{{ __('Nonaktif') }}</x-tab-button>
                </x-segmented>
            </div>
        </div>

        {{-- Toolbar Status Bar & Accordion Actions --}}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pt-2 border-t border-slate-800/60 text-xs text-slate-400">
            <div class="flex items-center gap-2 flex-wrap">
                @if ($search !== '' || $statusFilter !== 'all' || $categoryFilter !== 'all')
                    <span class="text-slate-300 font-medium">
                        Menampilkan <strong>{{ count($filteredModules) }}</strong> modul
                        @if ($search !== '')
                            untuk pencarian "<span class="text-emerald-400">{{ $search }}</span>"
                        @endif
                    </span>
                    <x-text-button tone="emerald" size="sm" wire:click="resetFilters">
                        <i data-lucide="rotate-ccw" class="w-3 h-3"></i> {{ __('Reset Filter') }}
                    </x-text-button>
                @else
                    <span>Menampilkan seluruh <strong>{{ $totalModules }}</strong> modul sistem (<strong>{{ $totalFeatures }}</strong> fitur)</span>
                @endif
            </div>

            <div class="flex items-center gap-2 self-end sm:self-auto">
                <x-text-button wire:click="expandAllModules">
                    {{ __('Buka Semua') }}
                </x-text-button>
                <span class="text-slate-700">•</span>
                <x-text-button wire:click="collapseAllModules">
                    {{ __('Tutup Semua') }}
                </x-text-button>
            </div>
        </div>
    </div>

    {{-- Main Modules Form --}}
    <form wire:submit="save" class="space-y-4 sm:space-y-6">
        @if (empty($filteredModules))
            <div class="bg-slate-900 border border-slate-800 rounded-xl">
                <x-empty-state icon="search-x" :title="__('Tidak Ada Fitur yang Ditemukan')"
                    :description="__('Tidak ditemukan modul atau fitur yang cocok dengan kata kunci pencarian atau filter yang Anda pilih.')">
                    <div class="pt-2">
                        <x-secondary-button wire:click="resetFilters">
                            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                            {{ __('Bersihkan Pencarian & Filter') }}
                        </x-secondary-button>
                    </div>
                </x-empty-state>
            </div>
        @else
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5 items-start">
                @foreach ($filteredModules as $moduleKey => $module)
                    @php
                        $moduleOn = $state[$moduleKey]['on'] ?? true;
                        $allModuleFeatures = $modules[$moduleKey]['features'] ?? [];
                        $activeCount = collect($state[$moduleKey]['features'] ?? [])->filter()->count();
                        $totalCount = count($allModuleFeatures);
                        $isCollapsed = !empty($collapsed[$moduleKey]) && empty(trim($search));
                        $isPartiallyActive = $moduleOn && $activeCount > 0 && $activeCount < $totalCount;
                        $isFullyActive = $moduleOn && $activeCount === $totalCount;
                    @endphp

                    <section wire:key="module-{{ $moduleKey }}"
                        @class([
                            'min-w-0 bg-slate-900 border rounded-xl overflow-hidden transition-all duration-200 shadow-sm',
                            'border-emerald-500/30' => $moduleOn && $changesCount > 0 && (($initialState[$moduleKey]['on'] ?? true) !== $moduleOn),
                            'border-slate-800 hover:border-slate-700/80' => !(($initialState[$moduleKey]['on'] ?? true) !== $moduleOn),
                        ])>
                        {{-- Module Header Card --}}
                        <div class="p-4 sm:px-5 sm:py-4 border-b border-slate-800/90 bg-slate-900/95 flex flex-col gap-3">
                            <div class="flex items-center justify-between gap-3">
                                {{-- Module Identity & Icon --}}
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    <div @class([
                                        'w-9 h-9 rounded-xl border flex items-center justify-center shrink-0 transition-colors',
                                        'bg-emerald-500/10 text-emerald-400 border-emerald-500/20 shadow-sm shadow-emerald-500/10' => $moduleOn,
                                        'bg-slate-800 text-slate-500 border-slate-700/50' => !$moduleOn,
                                    ])>
                                        <i data-lucide="{{ $module['icon'] }}" class="w-4 h-4"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-bold text-sm text-slate-100">{{ $module['label'] }}</span>
                                            @if ($moduleOn)
                                                @if ($isFullyActive)
                                                    <x-badge color="emerald">{{ $activeCount }}/{{ $totalCount }} Aktif</x-badge>
                                                @else
                                                    <x-badge color="amber">{{ $activeCount }}/{{ $totalCount }} Aktif</x-badge>
                                                @endif
                                            @else
                                                <x-badge color="rose">Modul Nonaktif</x-badge>
                                            @endif
                                        </div>
                                        <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1 leading-snug">
                                            {{ $module['description'] ?? 'Modul fungsional aplikasi.' }}
                                        </p>
                                        @if ($moduleKey === 'business' && app(\App\Support\CurrentOutlet::class)->isMultiOutlet())
                                            <p class="text-[11px] text-slate-400 mt-1 leading-snug">Berlaku untuk semua outlet. Untuk satu outlet saja, atur di <x-feature-link :href="route('settings.outlets')" wire:navigate class="text-emerald-400 hover:text-emerald-300">Outlet</x-feature-link>.</p>
                                        @endif
                                    </div>
                                </div>

                                {{-- Module Switch & Accordion Toggle --}}
                                <div class="flex items-center gap-3 shrink-0">
                                    <button type="button" wire:click="toggleCollapse('{{ $moduleKey }}')"
                                        class="p-1 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800 transition"
                                        title="{{ $isCollapsed ? 'Buka rincian' : 'Tutup rincian' }}"
                                        aria-label="{{ $isCollapsed ? 'Buka rincian' : 'Tutup rincian' }}">
                                        <i data-lucide="{{ $isCollapsed ? 'chevron-down' : 'chevron-up' }}" class="w-4 h-4"></i>
                                    </button>
                                    <div class="pl-2 border-l border-slate-800">
                                        <x-feature-switch id="module-{{ $moduleKey }}" wire:model.live="state.{{ $moduleKey }}.on" :label="'Modul '.$module['label']" />
                                    </div>
                                </div>
                            </div>

                            {{-- Mini Progress Bar --}}
                            @if ($moduleOn)
                                <div class="space-y-1">
                                    <div class="w-full bg-slate-950 rounded-full h-1.5 overflow-hidden border border-slate-800">
                                        <div @class([
                                            'h-full rounded-full transition-all duration-300',
                                            'bg-emerald-500' => $isFullyActive,
                                            'bg-amber-400' => $isPartiallyActive,
                                            'bg-slate-700' => $activeCount === 0,
                                        ]) style="width: {{ round(($activeCount / max($totalCount, 1)) * 100) }}%"></div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        {{-- Collapsible Content: Features List --}}
                        @if (! $isCollapsed)
                            <div>
                                {{-- Disabled Module Warning Banner --}}
                                @if (! $moduleOn)
                                    <div class="p-3 sm:px-5 bg-rose-950/20 border-b border-rose-900/30 flex items-center justify-between gap-3 text-rose-300">
                                        <div class="flex items-center gap-2 text-xs">
                                            <i data-lucide="shield-alert" class="w-4 h-4 shrink-0 text-rose-400"></i>
                                            <span>Seluruh modul dimatikan. Semua sub-fitur di bawah otomatis terkunci.</span>
                                        </div>
                                        <x-text-button tone="rose" wire:click="enableModule('{{ $moduleKey }}')" class="shrink-0">
                                            {{ __('Nyalakan Modul') }}
                                        </x-text-button>
                                    </div>
                                @else
                                    {{-- Quick Module Sub-Actions --}}
                                    <div class="px-4 sm:px-5 py-2 bg-slate-950/50 border-b border-slate-800/60 flex items-center justify-between text-[11px] text-slate-400">
                                        <span>Daftar Sub-Fitur ({{ count($module['features']) }})</span>
                                        <div class="flex items-center gap-2">
                                            <x-text-button tone="emerald" size="sm" wire:click="toggleAllInModule('{{ $moduleKey }}', true)">
                                                {{ __('Aktifkan Semua') }}
                                            </x-text-button>
                                            <span class="text-slate-700">•</span>
                                            <x-text-button size="sm" wire:click="toggleAllInModule('{{ $moduleKey }}', false)">
                                                {{ __('Matikan Semua') }}
                                            </x-text-button>
                                        </div>
                                    </div>
                                @endif

                                {{-- Features Items --}}
                                <ul @class([
                                    'divide-y divide-slate-800/60 transition-opacity',
                                    'opacity-40 pointer-events-none' => !$moduleOn,
                                ])>
                                    @foreach ($module['features'] as $featureKey => $feature)
                                        @php
                                            $featureOn = $state[$moduleKey]['features'][$featureKey] ?? true;
                                            $isFeatureDirty = ($initialState[$moduleKey]['features'][$featureKey] ?? true) !== $featureOn;
                                        @endphp
                                        <li wire:key="feature-{{ $moduleKey }}-{{ $featureKey }}"
                                            @class([
                                                'transition-colors',
                                                'bg-amber-500/5' => $isFeatureDirty,
                                                'hover:bg-slate-800/40' => !$isFeatureDirty,
                                            ])>
                                            <label for="feature-{{ $moduleKey }}-{{ $featureKey }}"
                                                class="flex items-start sm:items-center justify-between gap-3 px-4 sm:px-5 min-h-[52px] py-3 cursor-pointer select-none">
                                                <div class="min-w-0 flex-1 pr-2">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span @class([
                                                            'text-xs font-semibold',
                                                            'text-slate-100' => $featureOn && $moduleOn,
                                                            'text-slate-400' => !($featureOn && $moduleOn),
                                                        ])>
                                                            {{ $feature['label'] }}
                                                        </span>
                                                        <span class="text-[10px] font-mono text-slate-500 bg-slate-950 px-1.5 py-0.5 rounded border border-slate-800/80">
                                                            {{ $moduleKey }}.{{ $featureKey }}
                                                        </span>
                                                        @if ($isFeatureDirty)
                                                            <span class="text-[9px] font-semibold text-amber-400 bg-amber-500/10 px-1 rounded border border-amber-500/20">
                                                                {{ __('Diubah') }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    @if (! empty($feature['description']))
                                                        <p class="text-[11px] text-slate-400 mt-0.5 leading-relaxed">
                                                            {{ $feature['description'] }}
                                                        </p>
                                                    @endif
                                                </div>

                                                <div class="shrink-0 flex items-center gap-2.5 self-center">
                                                    <span @class([
                                                        'text-[10px] font-bold uppercase tracking-wider hidden sm:inline-block',
                                                        'text-emerald-400' => $featureOn && $moduleOn,
                                                        'text-slate-500' => !($featureOn && $moduleOn),
                                                    ])>
                                                        {{ $featureOn && $moduleOn ? __('Aktif') : __('Nonaktif') }}
                                                    </span>
                                                    <x-feature-switch id="feature-{{ $moduleKey }}-{{ $featureKey }}"
                                                        wire:model.live="state.{{ $moduleKey }}.features.{{ $featureKey }}"
                                                        :label="$feature['label']" />
                                                </div>
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif

        {{-- Floating Action Bar (Sticky Footer) --}}
        <div class="sticky bottom-20 md:bottom-4 z-20 flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 sm:px-5 py-3 rounded-xl bg-slate-900/95 backdrop-blur border border-slate-800 shadow-2xl">
            <div class="flex items-center gap-2.5">
                @if ($changesCount > 0)
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-ping shrink-0"></span>
                    <div>
                        <p class="text-xs font-bold text-amber-400">
                            {{ __('Terdapat :count perubahan sakelar belum disimpan.', ['count' => $changesCount]) }}
                        </p>
                        <p class="text-[11px] text-slate-400">
                            {{ __('Klik tombol simpan untuk langsung menerapkan perubahan ke seluruh sistem.') }}
                        </p>
                    </div>
                @else
                    <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></span>
                    <div>
                        <p class="text-xs font-semibold text-slate-200">
                            {{ __('Semua sakelar tersimpan.') }}
                        </p>
                        <p class="text-[11px] text-slate-400">
                            {{ __('Pengaturan sakelar berlaku untuk seluruh pengguna dan sidebar navigasi.') }}
                        </p>
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-2 self-end sm:self-auto shrink-0">
                @if ($changesCount > 0)
                    <x-secondary-button wire:click="resetChanges" class="min-h-[40px] px-4">
                        <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                        {{ __('Kembalikan') }}
                    </x-secondary-button>
                @endif

                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save" class="min-h-[40px] px-6 shadow-lg shadow-emerald-500/10">
                    <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-1.5">
                        <i data-lucide="check" class="w-4 h-4"></i>
                        {{ __('Simpan Pengaturan Fitur') }}
                    </span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-1.5">
                        <i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>
                        {{ __('Menyimpan...') }}
                    </span>
                </x-primary-button>
            </div>
        </div>
    </form>
</div>

