<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\StoreType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\StorePresets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets up a tenant from a store-type preset (App\Support\StorePresets) and closes onboarding.
 * Safe to run again: categories and products that already exist by name are left alone.
 */
class StorePresetApplier
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @return array{categories_created: int, products_created: int, products_skipped: int}
     *
     * @throws RuntimeException when a preset was already applied and the store has sales.
     */
    public function apply(
        Tenant $tenant,
        StoreType $type,
        bool $includeSampleProducts = true,
        ?array $selectedCategories = null,
        ?array $customSettings = null,
    ): array {
        return $this->currentTenant->run($tenant, function () use ($tenant, $type, $includeSampleProducts, $selectedCategories, $customSettings) {
            if ($reason = $this->reapplyBlockedReason($tenant)) {
                throw new RuntimeException($reason);
            }

            return DB::transaction(function () use ($tenant, $type, $includeSampleProducts, $selectedCategories, $customSettings) {
                // One summary log entry instead of an audit row and realtime broadcast per product.
                $result = Model::withoutEvents(fn () => $this->createCatalog($type, $includeSampleProducts, $selectedCategories));

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

                $kept = array_diff(Features::disabledKeys(), StorePresets::MANAGED_FEATURES);
                Features::setDisabled([...$kept, ...StorePresets::disabledFeatures($type)]);

                $tenant->forceFill(['store_type' => $type, 'onboarded_at' => $tenant->onboarded_at ?? now()])->save();

                activity('settings')->causedBy(auth()->user())->log(sprintf(
                    'Preset toko %s diterapkan: %d kategori dan %d produk baru.',
                    $type->label(),
                    $result['categories_created'],
                    $result['products_created'],
                ));

                return $result;
            });
        });
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
     * @return array{categories_created: int, products_created: int, products_skipped: int}
     */
    private function createCatalog(StoreType $type, bool $includeSampleProducts, ?array $selectedCategories = null): array
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
            }

            if (! $includeSampleProducts) {
                continue;
            }

            foreach ($products as [$name, $cost, $price, $unit, $minStock]) {
                if ($existingNames->has(mb_strtolower($name))) {
                    continue;
                }

                if ($remaining !== null && $remaining <= 0) {
                    $result['products_skipped']++;

                    continue;
                }

                Product::query()->create([
                    'category_id' => $category->id,
                    'sku' => $this->numbers->next('PRD', 5),
                    'name' => $name,
                    'unit' => $unit,
                    'cost_price' => $cost,
                    'price' => $price,
                    'track_stock' => $minStock !== null,
                    'stock' => 0,
                    'min_stock' => $minStock ?? 0,
                    'is_active' => true,
                ]);

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
                    Category::query()->create([
                        'name' => $catTrimmed,
                        'sort_order' => (int) Category::query()->max('sort_order') + 1,
                        'is_active' => true,
                    ]);
                    $result['categories_created']++;
                }
            }
        }

        return $result;
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
