<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\StoreType;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\OutletFeatures;
use App\Support\OutletSettings;
use App\Support\StorePresets;
use App\Support\StorePresets\PresetProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets up a tenant from a store-type preset (App\Support\StorePresets) and closes onboarding.
 * Safe to run again: categories and products that already exist by name are left alone.
 */
class StorePresetApplier
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private DocumentNumberGenerator $numbers,
        private BusinessCapabilities $capabilities,
        private VariantService $variants,
    ) {}

    /**
     * $capabilities null means the preset's own list; otherwise the business capabilities the owner
     * kept ticked in the wizard. On a re-apply, capabilities that already hold data stay on.
     *
     * @param  list<string>|null  $capabilities
     * @return array{categories_created: int, products_created: int, products_skipped: int, capabilities: list<string>, capabilities_kept: list<string>}
     *
     * @throws RuntimeException when a preset was already applied and the store has sales.
     */
    public function apply(
        Tenant $tenant,
        StoreType $type,
        bool $includeSampleProducts = true,
        ?array $selectedCategories = null,
        ?array $customSettings = null,
        ?array $capabilities = null,
    ): array {
        return $this->currentTenant->run($tenant, function () use ($tenant, $type, $includeSampleProducts, $selectedCategories, $customSettings, $capabilities) {
            if ($reason = $this->reapplyBlockedReason($tenant)) {
                throw new RuntimeException($reason);
            }

            $capabilities = array_values(array_intersect($capabilities ?? StorePresets::capabilities($type), Features::optInFeatures()));
            $kept = $tenant->isOnboarded() ? array_values(array_diff(array_intersect($this->capabilities->withData(), Features::enabledKeys()), $capabilities)) : [];

            return DB::transaction(function () use ($tenant, $type, $includeSampleProducts, $selectedCategories, $customSettings, $capabilities, $kept) {
                // One summary log entry instead of an audit row and realtime broadcast per product.
                $result = Model::withoutEvents(fn () => $this->createCatalog($type, $includeSampleProducts, $selectedCategories, $capabilities, null));

                if (in_array('business.modifiers', $capabilities, true)) {
                    Model::withoutEvents(fn () => $this->createModifierGroups($type));
                }

                $settings = StorePresets::settings($type);
                if ($customSettings !== null && is_array($customSettings)) {
                    if (array_key_exists('tax_enabled', $customSettings)) {
                        $settings['pos.tax_enabled'] = $customSettings['tax_enabled'] ? '1' : '0';
                    }
                    if (array_key_exists('tax_rate', $customSettings)) {
                        $settings['pos.tax_rate'] = (string) $customSettings['tax_rate'];
                    }
                    if (array_key_exists('tax_label', $customSettings) && ! empty($customSettings['tax_label'])) {
                        $settings['pos.tax_label'] = (string) $customSettings['tax_label'];
                    }
                    if (array_key_exists('allow_credit', $customSettings)) {
                        $settings['pos.allow_credit'] = $customSettings['allow_credit'] ? '1' : '0';
                    }
                    if (array_key_exists('allow_negative_stock', $customSettings)) {
                        $settings['pos.allow_negative_stock'] = $customSettings['allow_negative_stock'] ? '1' : '0';
                    }
                    if (array_key_exists('payment_methods', $customSettings) && is_array($customSettings['payment_methods'])) {
                        $settings['pos.payment_methods'] = json_encode(array_values(array_unique([
                            PaymentMethod::Cash->value,
                            ...$customSettings['payment_methods'],
                        ])));
                    }
                    if (array_key_exists('receipt_footer', $customSettings)) {
                        $settings['pos.receipt_footer'] = trim((string) $customSettings['receipt_footer']);
                    }
                }
                Setting::putMany($settings);

                $untouched = array_diff(Features::disabledKeys(), StorePresets::MANAGED_FEATURES);
                Features::setDisabled([...$untouched, ...StorePresets::disabledFeatures($type)]);
                $primary = Outlet::query()->where('is_primary', true)->first();

                if ($primary && Outlet::query()->count() > 1) {
                    $this->capabilities->syncOutlet($primary, [...$capabilities, ...$kept]);
                } else {
                    $this->capabilities->sync([...$capabilities, ...$kept]);
                }
                $this->seedExtraRoles($type);

                $tenant->forceFill(['store_type' => $type, 'onboarded_at' => $tenant->onboarded_at ?? now()])->save();

                activity('settings')->causedBy(auth()->user())->log(sprintf(
                    'Preset toko %s diterapkan: %d kategori dan %d produk baru.',
                    $type->label(),
                    $result['categories_created'],
                    $result['products_created'],
                ));

                return [...$result, 'capabilities' => $capabilities, 'capabilities_kept' => $kept];
            });
        });
    }

    /**
     * Preset untuk satu outlet, mis. outlet Apotek di toko Kelontong. Hanya menambah: kategori baru
     * dibatasi ke outlet ini, kapabilitas dan setting khas preset hanya berlaku di outlet ini, dan
     * pajak serta setting toko tidak disentuh. Boleh walau toko sudah punya penjualan.
     *
     * @param  list<string>|null  $capabilities  null = kapabilitas bawaan preset
     * @return array{categories_created: int, products_created: int, products_skipped: int, capabilities: list<string>}
     */
    public function applyToOutlet(
        Outlet $outlet,
        StoreType $type,
        bool $includeSampleProducts = true,
        ?array $selectedCategories = null,
        ?array $capabilities = null,
    ): array {
        $capabilities = array_values(array_intersect($capabilities ?? StorePresets::capabilities($type), Features::optInFeatures()));

        return DB::transaction(function () use ($outlet, $type, $includeSampleProducts, $selectedCategories, $capabilities) {
            $result = Model::withoutEvents(fn () => $this->createCatalog($type, $includeSampleProducts, $selectedCategories, $capabilities, $outlet));

            if (in_array('business.modifiers', $capabilities, true)) {
                Model::withoutEvents(fn () => $this->createModifierGroups($type));
            }

            $this->capabilities->syncOutlet($outlet, $capabilities);
            $this->seedExtraRoles($type);

            $disabled = StorePresets::disabledFeatures($type);
            OutletFeatures::setDisabled($outlet, $disabled);
            OutletSettings::putMany($outlet->id, [
                ...array_intersect_key(StorePresets::for($type)->settings(), array_flip(OutletSettings::KEYS)),
                ...(in_array('pos.receivables', $disabled, true) ? ['pos.allow_credit' => '0'] : []),
            ]);
            OutletSettings::forget($outlet->id);

            $outlet->forceFill(['store_type' => $type])->save();

            activity('settings')->performedOn($outlet)->causedBy(auth()->user())->log(sprintf(
                'Preset %s diterapkan ke outlet %s: %d kategori dan %d produk baru.',
                $type->label(),
                $outlet->name,
                $result['categories_created'],
                $result['products_created'],
            ));

            return [...$result, 'capabilities' => $capabilities];
        });
    }

    /**
     * Kategori bernama sama yang sudah dibatasi ke outlet lain ikut dibuka untuk outlet ini; kategori
     * untuk semua outlet dibiarkan.
     */
    private function shareWithOutlet(Category $category, Outlet $outlet): void
    {
        $outletIds = $category->outlets()->pluck('outlets.id')->map(fn ($id) => (int) $id)->all();

        if ($outletIds !== [] && ! in_array($outlet->id, $outletIds, true)) {
            $category->restrictToOutlets([...$outletIds, $outlet->id]);
        }
    }

    /**
     * Peran khas jenis toko (mis. apoteker). Peran yang sudah ada tidak diubah supaya kustomisasi pemilik aman.
     */
    private function seedExtraRoles(StoreType $type): void
    {
        foreach (StorePresets::for($type)->extraRoles() as $name => $permissions) {
            if (Role::query()->where('tenant_id', $this->currentTenant->id())->where('name', $name)->where('guard_name', 'web')->exists()) {
                continue;
            }

            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
            }

            Role::findOrCreate($name, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Grup yang sudah ada (berdasarkan nama) dibiarkan; grup baru dipasang ke produk di kategori contohnya.
     */
    private function createModifierGroups(StoreType $type): void
    {
        foreach (StorePresets::for($type)->modifierGroups() as $order => $preset) {
            if (ModifierGroup::query()->where('name', $preset->name)->exists()) {
                continue;
            }

            $group = ModifierGroup::query()->create(['name' => $preset->name, 'min_select' => $preset->min, 'max_select' => $preset->max, 'sort_order' => $order + 1, 'is_active' => true]);

            foreach ($preset->options as $index => [$name, $price]) {
                $group->modifiers()->create(['name' => $name, 'price' => $price, 'sort_order' => $index + 1, 'is_active' => true]);
            }

            $productIds = Product::query()->whereHas('category', fn ($query) => $query->whereIn('name', $preset->categories))->pluck('id');
            $group->products()->syncWithPivotValues($productIds, ['sort_order' => $order + 1]);
        }
    }

    /**
     * Ends onboarding without generating anything.
     */
    public function skip(Tenant $tenant): void
    {
        if ($tenant->isOnboarded()) {
            return;
        }

        $tenant->forceFill(['onboarded_at' => now()])->save();
    }

    /**
     * Null while a preset may be applied. During onboarding it always may; afterwards only
     * before the first sale, because changing tax and credit rules mid-trade skews reports.
     */
    public function reapplyBlockedReason(Tenant $tenant): ?string
    {
        if (! $tenant->isOnboarded()) {
            return null;
        }

        $hasSales = $this->currentTenant->run($tenant, fn () => Sale::query()->exists());

        return $hasSales
            ? 'Preset tidak bisa diterapkan lagi karena toko ini sudah punya transaksi penjualan.'
            : null;
    }

    /**
     * @param  list<string>  $capabilities
     * @return array{categories_created: int, products_created: int, products_skipped: int}
     */
    private function createCatalog(StoreType $type, bool $includeSampleProducts, ?array $selectedCategories, array $capabilities, ?Outlet $outlet): array
    {
        $result = ['categories_created' => 0, 'products_created' => 0, 'products_skipped' => 0];
        $existingNames = $includeSampleProducts ? Product::query()->pluck('name')->map(fn (string $name) => mb_strtolower($name))->flip() : collect();
        $remaining = $this->remainingProductSlots();

        $selectedNormalized = $selectedCategories !== null
            ? collect($selectedCategories)->map(fn ($c) => mb_strtolower(trim((string) $c)))->all()
            : null;

        foreach (StorePresets::catalog($type) as $categoryName => $products) {
            if ($selectedNormalized !== null && ! in_array(mb_strtolower(trim($categoryName)), $selectedNormalized, true)) {
                continue;
            }
            $category = Category::query()->where('name', $categoryName)->first();

            if (! $category) {
                $category = Category::query()->create([
                    'name' => $categoryName,
                    'sort_order' => (int) Category::query()->max('sort_order') + 1,
                    'is_active' => true,
                ]);
                $result['categories_created']++;
                $outlet && $category->restrictToOutlets([$outlet->id]);
            } elseif ($outlet) {
                $this->shareWithOutlet($category, $outlet);
            }

            if (! $includeSampleProducts) {
                continue;
            }

            foreach ($products as $preset) {
                if ($existingNames->has(mb_strtolower($preset->name))) {
                    continue;
                }

                if ($remaining !== null && $remaining <= 0) {
                    $result['products_skipped']++;

                    continue;
                }

                $this->createProduct($category, $preset, $capabilities);

                $result['products_created']++;
                $remaining = $remaining === null ? null : $remaining - 1;
            }
        }

        if ($selectedCategories !== null) {
            foreach ($selectedCategories as $catName) {
                $catTrimmed = trim((string) $catName);
                if ($catTrimmed === '') {
                    continue;
                }
                $exists = Category::query()->where('name', $catTrimmed)->exists();
                if (! $exists) {
                    $category = Category::query()->create([
                        'name' => $catTrimmed,
                        'sort_order' => (int) Category::query()->max('sort_order') + 1,
                        'is_active' => true,
                    ]);
                    $result['categories_created']++;
                    $outlet && $category->restrictToOutlets([$outlet->id]);
                }
            }
        }

        return $result;
    }

    /**
     * Data khusus usaha (atribut, golongan obat, batch, satuan) hanya diisi untuk kapabilitas yang dinyalakan.
     *
     * @param  list<string>  $capabilities
     */
    private function createProduct(Category $category, PresetProduct $preset, array $capabilities): void
    {
        $attributes = in_array('business.product-attributes', $capabilities, true) && $preset->attributes !== [] ? $preset->attributes : null;
        $pharmacy = in_array('business.prescription', $capabilities, true);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'sku' => $this->numbers->next('PRD', 5),
            'name' => $preset->name,
            'unit' => $preset->unit,
            'cost_price' => $preset->cost,
            'price' => $preset->price,
            'track_stock' => $preset->minStock !== null,
            'stock' => 0,
            'min_stock' => $preset->minStock ?? 0,
            'is_active' => true,
            'custom_attributes' => $attributes,
            'attributes_search' => $attributes ? Product::searchTextFor($attributes) : null,
            'drug_class' => $pharmacy ? $preset->drugClass?->value : null,
            'requires_prescription' => $pharmacy && $preset->requiresPrescription(),
            'track_batch' => $preset->trackBatch && $preset->minStock !== null && in_array('business.batch-expiry', $capabilities, true),
            'track_serial' => $preset->trackSerial && $preset->minStock !== null && in_array('business.serial-number', $capabilities, true),
            'warranty_days' => in_array('business.serial-number', $capabilities, true) ? $preset->warrantyDays : null,
        ]);

        // Batas paket produk juga menghitung SKU varian; kalau penuh, induknya tetap dibuat tanpa varian.
        if ($preset->variantOptions !== [] && in_array('business.variants', $capabilities, true)) {
            try {
                $this->variants->sync($product, $preset->variantOptions);
            } catch (ValidationException) {
                $product->variants()->delete();
                $product->forceFill(['variant_options' => null, 'track_stock' => $preset->minStock !== null])->save();
            }
        }

        if (in_array('business.multi-unit', $capabilities, true)) {
            foreach ($preset->units as $order => $unit) {
                $product->units()->create([
                    'name' => $unit['name'],
                    'factor' => $unit['factor'],
                    'price' => $unit['price'],
                    'sort_order' => $order + 1,
                ]);
            }
        }
    }

    /**
     * Free product slots under the plan, null when unlimited. Mirrors PlanLimits, but a preset
     * fills up to the limit instead of failing.
     */
    private function remainingProductSlots(): ?int
    {
        $limit = $this->currentTenant->get()?->limit('products');

        return $limit === null ? null : max(0, $limit - Product::query()->count());
    }
}
