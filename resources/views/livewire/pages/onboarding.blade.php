<?php

use App\Enums\PaymentMethod;
use App\Enums\StoreType;
use App\Models\Tenant;
use App\Services\StorePresetApplier;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\NumberFormatter as Num;
use App\Support\StorePresets;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app', ['heading' => 'Persiapan Toko'])] #[Title('Persiapan Toko')] class extends Component
{
    public ?string $storeType = null;

    public bool $includeSampleProducts = true;

    public array $selectedCategories = [];

    public bool $taxEnabled = false;

    public string $taxRate = '11';

    public string $taxLabel = 'PPN';

    public bool $allowCredit = true;

    public bool $allowNegativeStock = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->isSuperAdmin(), 403);
    }

    /**
     * Langkah 1: pilih jenis toko, lalu lanjut ke pratinjau dengan default fleksibel.
     */
    public function choose(string $type): void
    {
        $this->storeType = StoreType::tryFrom($type)?->value;
        if (! $this->storeType) {
            return;
        }

        $storeTypeEnum = StoreType::from($this->storeType);
        $this->selectedCategories = StorePresets::categories($storeTypeEnum);
        $this->includeSampleProducts = true;

        $summary = StorePresets::settingsSummary($storeTypeEnum);
        $this->taxEnabled = $summary['tax_enabled'];
        $this->taxRate = (string) $summary['tax_rate'];
        $this->taxLabel = $summary['tax_label'];
        $this->allowCredit = $summary['allow_credit'];
        $this->allowNegativeStock = $summary['allow_negative_stock'];
    }

    public function toggleCategory(string $category): void
    {
        if (in_array($category, $this->selectedCategories, true)) {
            $this->selectedCategories = array_values(array_diff($this->selectedCategories, [$category]));
        } else {
            $this->selectedCategories[] = $category;
        }
    }

    public function selectAllCategories(): void
    {
        if ($this->storeType) {
            $this->selectedCategories = StorePresets::categories(StoreType::from($this->storeType));
        }
    }

    public function clearAllCategories(): void
    {
        $this->selectedCategories = [];
    }

    public function back(): void
    {
        $this->reset('storeType', 'selectedCategories');
    }

    public function apply(StorePresetApplier $applier): void
    {
        abort_unless(Auth::user()->isSuperAdmin(), 403);

        $validated = $this->validate([
            'storeType' => ['required', Rule::enum(StoreType::class)],
            'includeSampleProducts' => ['boolean'],
            'taxEnabled' => ['boolean'],
            'taxRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'taxLabel' => ['nullable', 'string', 'max:20'],
            'allowCredit' => ['boolean'],
            'allowNegativeStock' => ['boolean'],
        ], [], ['storeType' => 'jenis toko']);

        $type = StoreType::from($validated['storeType']);
        $customSettings = [
            'tax_enabled' => $this->taxEnabled,
            'tax_rate' => $this->taxRate,
            'tax_label' => $this->taxLabel ?: 'PPN',
            'allow_credit' => $this->allowCredit,
            'allow_negative_stock' => $this->allowNegativeStock,
        ];

        try {
            $result = $applier->apply(
                $this->tenant(),
                $type,
                $validated['includeSampleProducts'],
                $this->selectedCategories,
                $customSettings,
            );
        } catch (RuntimeException $e) {
            $this->addError('storeType', $e->getMessage());

            return;
        }

        $message = $result['products_created'] > 0
            ? "Toko siap dipakai: {$result['categories_created']} kategori dan {$result['products_created']} produk contoh ditambahkan."
            : "Toko siap dipakai: {$result['categories_created']} kategori ditambahkan.";

        if ($result['products_skipped'] > 0) {
            $message .= " {$result['products_skipped']} produk contoh tidak ditambahkan karena batas paket.";
        }

        session()->flash('notify', ['message' => $message, 'type' => 'success']);
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function skip(StorePresetApplier $applier): void
    {
        abort_unless(Auth::user()->isSuperAdmin(), 403);

        $applier->skip($this->tenant());

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function with(StorePresetApplier $applier): array
    {
        $tenant = $this->tenant();
        $type = $this->storeType ? StoreType::from($this->storeType) : null;

        return [
            'isReapply' => $tenant->isOnboarded(),
            'currentType' => $tenant->store_type,
            'blockedReason' => $applier->reapplyBlockedReason($tenant),
            'selected' => $type,
            'categories' => $type ? StorePresets::categories($type) : [],
            'sampleProductCount' => $type ? StorePresets::sampleProductCount($type) : 0,
            'settings' => $type ? StorePresets::settingsSummary($type) : null,
            'featureChanges' => $type ? $this->featureChanges($type) : [],
        ];
    }

    /**
     * @return list<array{label: string, enabled: bool}>
     */
    private function featureChanges(StoreType $type): array
    {
        $disabled = StorePresets::disabledFeatures($type);

        return array_map(function (string $key) use ($disabled) {
            [$module, $feature] = explode('.', $key, 2);

            return ['label' => Features::MODULES[$module]['features'][$feature]['label'], 'enabled' => ! in_array($key, $disabled, true)];
        }, StorePresets::MANAGED_FEATURES);
    }

    private function tenant(): Tenant
    {
        return app(CurrentTenant::class)->get();
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    @if ($blockedReason)
        <div class="bg-slate-900 border border-slate-800 rounded-xl">
            <x-empty-state icon="lock" title="Preset tidak bisa diterapkan lagi" :description="$blockedReason.' Ubah kategori, produk, dan pengaturan kasir satu per satu dari menunya masing-masing.'">
                <a href="{{ route('settings.company-profile', ['tab' => 'jenis-toko']) }}" wire:navigate
                    class="inline-flex items-center justify-center gap-2 min-h-[44px] px-4 rounded-lg border border-slate-700 bg-slate-800 text-xs font-semibold text-slate-300 hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                    Kembali ke Pengaturan
                </a>
            </x-empty-state>
        </div>
    @elseif (! $selected)
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold text-slate-400">Langkah 1 dari 2</p>
                <h1 class="text-lg sm:text-xl font-bold text-slate-100 mt-0.5">Toko Anda jenis apa?</h1>
                <p class="text-xs sm:text-sm text-slate-400 mt-1">
                    Kami siapkan kategori, contoh produk, pajak, dan aturan kasir yang umum untuk jenis toko ini. Semuanya bisa diubah lagi nanti.
                </p>
            </div>
            @unless ($isReapply)
                <x-text-button size="sm" wire:click="skip" wire:loading.attr="disabled" class="self-start sm:self-auto">
                    <x-loading-label target="skip" loading="Menyiapkan...">Lewati, mulai dari kosong</x-loading-label>
                </x-text-button>
            @endunless
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
            @foreach (\App\Enums\StoreType::cases() as $type)
                <button type="button" wire:click="choose('{{ $type->value }}')" wire:key="type-{{ $type->value }}"
                    class="group flex items-start gap-3 p-4 min-h-[44px] rounded-xl border border-slate-800 bg-slate-900 text-left hover:border-emerald-500/50 hover:bg-slate-800/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 transition-colors">
                    <span class="inline-flex items-center justify-center w-10 h-10 shrink-0 rounded-lg bg-slate-800 text-slate-300 group-hover:text-emerald-400">
                        <i data-lucide="{{ $type->icon() }}" class="w-5 h-5"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="flex items-center gap-2">
                            <span class="text-sm font-bold text-slate-100">{{ $type->label() }}</span>
                            @if ($currentType === $type)
                                <x-badge color="emerald">DIPAKAI</x-badge>
                            @endif
                        </span>
                        <span class="block text-xs text-slate-400 mt-1 leading-relaxed">{{ $type->description() }}</span>
                    </span>
                </button>
            @endforeach
        </div>

        @if ($isReapply)
            <p class="text-xs text-slate-400">
                Menerapkan preset lagi menambah kategori dan produk yang belum ada, lalu mengganti pengaturan pajak, kasbon, dan struk.
                <a href="{{ route('settings.company-profile', ['tab' => 'jenis-toko']) }}" wire:navigate class="font-semibold text-emerald-400 hover:underline">Batal</a>
            </p>
        @endif
    @else
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold text-slate-400">Langkah 2 dari 2</p>
                <h1 class="text-lg sm:text-xl font-bold text-slate-100 mt-0.5 flex items-center gap-2">
                    <i data-lucide="{{ $selected->icon() }}" class="w-5 h-5 text-emerald-400 shrink-0"></i>
                    {{ $selected->label() }}
                </h1>
                <p class="text-xs sm:text-sm text-slate-400 mt-1">Periksa yang akan disiapkan sebelum diterapkan.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6 items-start">
            <div class="lg:col-span-7 space-y-4">
                <section class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-5 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2">
                            <i data-lucide="tags" class="w-4 h-4 text-emerald-400"></i> Kategori ({{ count($selectedCategories) }} dari {{ count($categories) }} dipilih)
                        </h2>
                        <div class="flex items-center gap-2 text-xs">
                            <x-text-button tone="emerald" size="sm" wire:click="selectAllCategories">Pilih semua</x-text-button>
                            <span class="text-slate-600">·</span>
                            <x-text-button size="sm" wire:click="clearAllCategories">Batal semua</x-text-button>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($categories as $category)
                            @php $active = in_array($category, $selectedCategories, true); @endphp
                            <button type="button" wire:click="toggleCategory(@js($category))"
                                @class([
                                    'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium transition cursor-pointer select-none',
                                    'border-emerald-500/50 bg-emerald-500/10 text-emerald-300 ring-1 ring-emerald-500/20' => $active,
                                    'border-slate-800 bg-slate-800/40 text-slate-400 hover:border-slate-700' => ! $active,
                                ])>
                                <i data-lucide="{{ $active ? 'check' : 'plus' }}" class="w-3.5 h-3.5"></i>
                                <span>{{ $category }}</span>
                            </button>
                        @endforeach
                    </div>
                    <p class="text-[11px] text-slate-400">Centang kategori yang ingin ditambahkan. Produk contoh hanya akan dibuat untuk kategori terpilih.</p>
                </section>

                <section class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-5 space-y-3">
                    <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="package" class="w-4 h-4 text-slate-400"></i> Produk contoh</h2>
                    @if ($sampleProductCount > 0)
                        <x-checkbox-card wire:model.live="includeSampleProducts" label="Sertakan produk contoh"
                            :description="$sampleProductCount.' produk dengan harga jual dan modal perkiraan, stok awal 0. Ubah harganya atau hapus kapan saja dari Master Data.'" />
                    @else
                        <p class="text-xs text-slate-400">Jenis toko ini tidak punya produk contoh. Tambahkan produk Anda sendiri dari Master Data.</p>
                    @endif
                </section>

                <section class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-5 space-y-3">
                    <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="toggle-right" class="w-4 h-4 text-slate-400"></i> Fitur</h2>
                    <ul class="space-y-2">
                        @foreach ($featureChanges as $change)
                            <li class="flex items-center justify-between gap-3 text-xs">
                                <span class="text-slate-200">{{ $change['label'] }}</span>
                                <x-badge :color="$change['enabled'] ? 'emerald' : 'slate'">{{ $change['enabled'] ? 'AKTIF' : 'NONAKTIF' }}</x-badge>
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-[11px] text-slate-400">Fitur lain tidak diubah. Semuanya bisa dinyalakan lagi di Pengaturan Fitur.</p>
                </section>
            </div>

            {{-- Titik fokus layar ini: penyesuaian pengaturan kasir yang langsung berlaku. --}}
            <aside class="lg:col-span-5 rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-4 sm:p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-bold text-sm text-slate-100 flex items-center gap-2">
                        <i data-lucide="sliders-horizontal" class="w-4 h-4 text-emerald-400"></i>
                        Pengaturan kasir
                    </h2>
                    <span class="text-[10px] text-emerald-400 font-semibold uppercase tracking-wider bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20">Fleksibel</span>
                </div>

                <div class="space-y-3 text-xs">
                    {{-- Sakelar Pajak --}}
                    <div class="rounded-xl border border-slate-800/80 bg-slate-900/60 p-3 space-y-2">
                        <label class="flex items-center justify-between cursor-pointer">
                            <span class="font-semibold text-slate-200">Pajak ({{ $taxLabel }} {{ Num::quantity((float)$taxRate) }}%)</span>
                            <input type="checkbox" wire:model.live="taxEnabled" class="rounded border-slate-700 bg-slate-800 text-emerald-500 focus:ring-emerald-500">
                        </label>
                        @if ($taxEnabled)
                            <div class="flex items-center gap-2 pt-2 border-t border-slate-800/60">
                                <span class="text-slate-400 text-[11px]">Tarif:</span>
                                <input type="number" wire:model.live="taxRate" min="0" max="100" class="w-16 rounded border-slate-700 bg-slate-950 px-2 py-1 text-right text-xs text-slate-100 font-bold">
                                <span class="text-slate-400">%</span>
                            </div>
                        @endif
                    </div>

                    {{-- Sakelar Kasbon --}}
                    <div class="rounded-xl border border-slate-800/80 bg-slate-900/60 p-3">
                        <label class="flex items-center justify-between cursor-pointer">
                            <div>
                                <span class="font-semibold text-slate-200 block">Bolehkan kasbon</span>
                                <span class="text-[11px] text-slate-400 block mt-0.5">Izinkan piutang untuk pelanggan</span>
                            </div>
                            <input type="checkbox" wire:model.live="allowCredit" class="rounded border-slate-700 bg-slate-800 text-emerald-500 focus:ring-emerald-500">
                        </label>
                    </div>

                    {{-- Sakelar Stok Minus --}}
                    <div class="rounded-xl border border-slate-800/80 bg-slate-900/60 p-3">
                        <label class="flex items-center justify-between cursor-pointer">
                            <div>
                                <span class="font-semibold text-slate-200 block">Jual saat stok habis</span>
                                <span class="text-[11px] text-slate-400 block mt-0.5">Bolehkan transaksi saat stok 0 / minus</span>
                            </div>
                            <input type="checkbox" wire:model.live="allowNegativeStock" class="rounded border-slate-700 bg-slate-800 text-emerald-500 focus:ring-emerald-500">
                        </label>
                    </div>

                    {{-- Ringkasan Pembayaran & Nominal Cepat --}}
                    <div class="pt-2 border-t border-slate-800/80 space-y-2 text-slate-400">
                        <div class="flex items-start justify-between gap-3">
                            <span>Pembayaran</span>
                            <span class="text-right text-slate-200 font-medium">
                                {{ collect($settings['payment_methods'])->map(fn (string $method) => PaymentMethod::from($method)->shortLabel())->join(', ') }}
                            </span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span>Nominal cepat</span>
                            <span class="text-right font-mono text-slate-200">
                                {{ collect($settings['quick_cash'])->map(fn (int $amount) => Num::quantity($amount))->join(' · ') }}
                            </span>
                        </div>
                    </div>
                </div>

                <x-input-error :messages="$errors->get('storeType')" />

                <div class="flex flex-col-reverse sm:flex-row gap-2 pt-1">
                    <x-secondary-button wire:click="back" class="sm:flex-1">Ganti Jenis Toko</x-secondary-button>
                    <x-primary-button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply" class="sm:flex-1">
                        <x-loading-label target="apply" loading="Menyiapkan toko...">Terapkan Preset</x-loading-label>
                    </x-primary-button>
                </div>
            </aside>
        </div>
    @endif
</div>
