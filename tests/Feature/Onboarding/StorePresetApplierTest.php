<?php

use App\Enums\StoreType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\StorePresetApplier;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\PosSettings;
use App\Support\StorePresets;

function pendingTenant(array $attributes = []): Tenant
{
    return Tenant::factory()->pendingOnboarding()->create($attributes);
}

function inTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(CurrentTenant::class)->run($tenant, $callback);
}

it('creates the preset categories, settings, and feature toggles for its tenant only', function (StoreType $type) {
    $tenant = pendingTenant();
    $other = $this->tenant;
    inTenant($other, fn () => Category::factory()->create(['name' => 'Milik Toko Lain']));

    $result = app(StorePresetApplier::class)->apply($tenant, $type);

    inTenant($tenant, function () use ($type, $result) {
        expect(Category::query()->orderBy('sort_order')->pluck('name')->all())->toBe(StorePresets::categories($type))
            ->and(Product::query()->count())->toBe(StorePresets::sampleProductCount($type))
            ->and($result['products_created'])->toBe(StorePresets::sampleProductCount($type));

        foreach (StorePresets::settings($type) as $key => $value) {
            expect(Setting::get($key))->toBe($value, $key);
        }

        foreach (StorePresets::MANAGED_FEATURES as $feature) {
            expect(Features::enabled($feature))->toBe(! in_array($feature, StorePresets::disabledFeatures($type), true), $feature);
        }
    });

    $tenant->refresh();
    expect($tenant->store_type)->toBe($type)
        ->and($tenant->isOnboarded())->toBeTrue();

    inTenant($other, function () {
        expect(Category::query()->pluck('name')->all())->toBe(['Milik Toko Lain'])
            ->and(Product::query()->count())->toBe(0)
            ->and(Setting::get('pos.receipt_footer'))->toBeNull()
            ->and(Features::disabledKeys())->toBe([]);
    });
})->with(StoreType::cases());

it('sets cafe tax to PB1 10% and keeps made-to-order menu items out of stock tracking', function () {
    $tenant = pendingTenant();

    app(StorePresetApplier::class)->apply($tenant, StoreType::Cafe);

    inTenant($tenant, function () {
        expect(PosSettings::taxRate())->toBe(10.0)
            ->and(PosSettings::taxLabel())->toBe('PB1')
            ->and(PosSettings::allowCredit())->toBeFalse()
            ->and(Features::enabled('pos.receivables'))->toBeFalse()
            ->and(Product::query()->where('name', 'Cafe Latte')->sole()->track_stock)->toBeFalse()
            ->and(Product::query()->where('name', 'Air Mineral 600ml')->sole()->track_stock)->toBeTrue();
    });
});

it('gives sample products numbered SKUs, zero stock, and no barcode', function () {
    $tenant = pendingTenant();

    app(StorePresetApplier::class)->apply($tenant, StoreType::Minimarket);

    inTenant($tenant, function () {
        $product = Product::query()->orderBy('id')->first();

        expect($product->sku)->toBe('PRD-'.now()->year.'-00001')
            ->and((float) $product->stock)->toBe(0.0)
            ->and($product->barcode)->toBeNull()
            ->and($product->track_stock)->toBeTrue()
            ->and($product->category->name)->toBe('Minuman');
    });
});

it('creates only categories when sample products are left out', function () {
    $tenant = pendingTenant();

    $result = app(StorePresetApplier::class)->apply($tenant, StoreType::Warung, includeSampleProducts: false);

    expect($result['products_created'])->toBe(0)
        ->and(inTenant($tenant, fn () => [Category::query()->count(), Product::query()->count()]))->toBe([count(StorePresets::categories(StoreType::Warung)), 0]);
});

it('skips categories and products that already exist when applied again', function () {
    $tenant = pendingTenant();
    inTenant($tenant, function () {
        Category::factory()->create(['name' => 'Sembako', 'sort_order' => 1]);
        Product::factory()->create(['name' => 'Beras Medium 1kg']);
    });

    $applier = app(StorePresetApplier::class);
    $first = $applier->apply($tenant, StoreType::Warung);
    $second = $applier->apply($tenant->refresh(), StoreType::Warung);

    expect($first['categories_created'])->toBe(count(StorePresets::categories(StoreType::Warung)) - 1)
        ->and($first['products_created'])->toBe(StorePresets::sampleProductCount(StoreType::Warung) - 1)
        ->and($second)->toBe(['categories_created' => 0, 'products_created' => 0, 'products_skipped' => 0])
        ->and(inTenant($tenant, fn () => Product::query()->where('name', 'Beras Medium 1kg')->count()))->toBe(1);
});

it('stops adding sample products at the plan limit instead of failing', function () {
    config(['saas.plans.trial.max_products' => 5]);
    $tenant = pendingTenant(['plan' => Tenant::PLAN_TRIAL, 'trial_ends_at' => now()->addWeek()]);
    inTenant($tenant, fn () => Product::factory()->count(2)->create());

    $result = app(StorePresetApplier::class)->apply($tenant, StoreType::Fashion);

    expect($result['products_created'])->toBe(3)
        ->and($result['products_skipped'])->toBe(StorePresets::sampleProductCount(StoreType::Fashion) - 3)
        ->and(inTenant($tenant, fn () => Product::query()->count()))->toBe(5);
});

it('keeps feature toggles the preset does not manage', function () {
    $tenant = pendingTenant();
    inTenant($tenant, fn () => Features::setDisabled(['reports.activity-log', 'pos.customer-display']));

    app(StorePresetApplier::class)->apply($tenant, StoreType::Restaurant);

    expect(inTenant($tenant, fn () => Features::disabledKeys()))->toBe(['pos.receivables', 'reports.activity-log']);
});

it('rejects applying a preset again once the store has sales', function () {
    $tenant = pendingTenant();
    $applier = app(StorePresetApplier::class);
    $applier->apply($tenant, StoreType::Warung);
    inTenant($tenant, fn () => Sale::factory()->create());

    expect($applier->reapplyBlockedReason($tenant->refresh()))->not->toBeNull();

    $applier->apply($tenant, StoreType::Cafe);
})->throws(RuntimeException::class, 'sudah punya transaksi penjualan');

it('allows the first preset even when a cashier already recorded sales', function () {
    $tenant = pendingTenant();
    inTenant($tenant, fn () => Sale::factory()->create());

    app(StorePresetApplier::class)->apply($tenant, StoreType::Warung);

    expect($tenant->refresh()->store_type)->toBe(StoreType::Warung);
});

it('marks onboarding done without creating data when skipped', function () {
    $tenant = pendingTenant();

    app(StorePresetApplier::class)->skip($tenant);

    expect($tenant->refresh()->isOnboarded())->toBeTrue()
        ->and($tenant->store_type)->toBeNull()
        ->and(inTenant($tenant, fn () => [Category::query()->count(), Product::query()->count(), Setting::get('pos.receipt_footer')]))->toBe([0, 0, null]);
});

it('filters categories and applies custom settings when provided', function () {
    $tenant = pendingTenant();

    $result = app(StorePresetApplier::class)->apply(
        $tenant,
        StoreType::Cafe,
        includeSampleProducts: true,
        selectedCategories: ['Kopi'],
        customSettings: [
            'tax_enabled' => true,
            'tax_rate' => 12,
            'allow_credit' => false,
            'allow_negative_stock' => true,
        ],
    );

    expect($result['categories_created'])->toBe(1);

    inTenant($tenant, function () {
        expect(Category::query()->pluck('name')->all())->toBe(['Kopi'])
            ->and(Setting::get('pos.tax_enabled'))->toBe('1')
            ->and(Setting::get('pos.tax_rate'))->toBe('12')
            ->and(Setting::get('pos.allow_credit'))->toBe('0')
            ->and(Setting::get('pos.allow_negative_stock'))->toBe('1');
    });
});
