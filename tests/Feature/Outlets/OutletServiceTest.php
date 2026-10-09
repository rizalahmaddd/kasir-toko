<?php

use App\Models\CashShift;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductOutletPrice;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OutletsLockedNotification;
use App\Services\OutletService;
use App\Services\Pos\ShiftService;
use App\Services\TenantSubscriptionManager;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use App\Support\OutletSettings;
use App\Support\PlanLimits;
use App\Support\SaasPlans;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

function outletInput(string $name, string $code, array $extra = []): array
{
    return ['name' => $name, 'code' => $code, ...$extra];
}

function validationMessage(callable $callback): string
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return collect($exception->errors())->flatten()->first();
    }

    throw new RuntimeException('Expected a validation error.');
}

beforeEach(function () {
    $this->owner = actingAsSuperAdmin();
    $this->service = app(OutletService::class);
});

test('the free and trial plans allow a single outlet and pro allows five', function () {
    expect(SaasPlans::find('free')['max_outlets'])->toBe(1)
        ->and(SaasPlans::find('trial')['max_outlets'])->toBe(1)
        ->and(SaasPlans::find('pro')['max_outlets'])->toBe(5)
        ->and(SaasPlans::find('lifetime')['max_outlets'])->toBe(5);

    $this->tenant->update(['plan' => 'free']);
    expect(validationMessage(fn () => $this->service->create(outletInput('Cabang', 'CB1'), $this->owner)))->toContain('dibatasi 1 outlet');

    $this->tenant->update(['plan' => 'trial', 'trial_ends_at' => now()->addDays(5)]);
    expect(validationMessage(fn () => $this->service->create(outletInput('Cabang', 'CB1'), $this->owner)))->toContain('dibatasi 1 outlet');

    $this->tenant->update(['plan' => 'pro']);
    foreach (range(2, 5) as $number) {
        $this->service->create(outletInput("Cabang {$number}", "CB{$number}"), $this->owner);
    }

    expect(Outlet::count())->toBe(5)
        ->and(validationMessage(fn () => $this->service->create(outletInput('Cabang 6', 'CB6'), $this->owner)))->toContain('dibatasi 5 outlet');
});

test('a per-shop override raises or lowers the plan limit', function () {
    $this->tenant->update(['plan' => 'free', 'max_outlets_override' => 2]);
    $this->service->create(outletInput('Cabang', 'CB1'), $this->owner);

    expect(Outlet::count())->toBe(2)
        ->and(PlanLimits::count('outlets'))->toBe(2)
        ->and($this->tenant->fresh()->maxOutlets())->toBe(2);

    $this->tenant->update(['plan' => 'pro', 'max_outlets_override' => 1]);

    expect($this->tenant->fresh()->maxOutlets())->toBe(1);
});

test('the outlet limit can be changed per plan by the platform admin settings', function () {
    SaasPlans::save([...SaasPlans::all(), 'pro' => [...SaasPlans::find('pro'), 'max_outlets' => 3]]);

    expect($this->tenant->fresh()->maxOutlets())->toBe(3);

    SaasPlans::save([...SaasPlans::all(), 'pro' => [...SaasPlans::find('pro'), 'max_outlets' => 0]]);

    expect($this->tenant->fresh()->maxOutlets())->toBe(1);
});

test('creating an outlet seeds zero stock rows and gives other limited users explicit access to the main outlet', function () {
    $product = Product::factory()->create(['stock' => 5]);
    $clerk = User::factory()->limitedToOutlets()->create();

    $branch = $this->service->create(outletInput('Cabang Dago', 'dgo', ['address' => 'Jl. Dago 1']), $this->owner);

    expect($branch->code)->toBe('DGO')
        ->and($branch->is_primary)->toBeFalse()
        ->and((float) ProductStock::query()->where('product_id', $product->id)->where('outlet_id', $branch->id)->value('stock'))->toBe(0.0)
        ->and($clerk->outlets()->pluck('outlets.id')->all())->toBe([primaryOutlet()->id])
        ->and($this->owner->outlets()->count())->toBe(0);
});

test('outlet names and codes are unique per shop and the code stays uppercase letters and digits', function () {
    $rules = OutletService::rules();

    $duplicate = validator(['name' => primaryOutlet()->name, 'code' => 'PST'], $rules);
    $badCode = validator(['name' => 'Cabang', 'code' => 'dgo-1'], $rules);
    $other = Tenant::factory()->create();
    app(CurrentTenant::class)->run($other, fn () => Outlet::query()->where('tenant_id', $other->id)->update(['name' => 'Cabang Bebas', 'code' => 'FRE']));
    $crossTenant = validator(['name' => 'Cabang Bebas', 'code' => 'FRE'], $rules);

    expect($duplicate->errors()->keys())->toContain('name', 'code')
        ->and($badCode->errors()->keys())->toContain('code')
        ->and($crossTenant->passes())->toBeTrue();
});

test('the outlet code cannot change after the outlet has a sale', function () {
    $branch = $this->service->create(outletInput('Cabang', 'CB1'), $this->owner);
    $this->service->update($branch, outletInput('Cabang Baru', 'CB9'));

    expect($branch->fresh()->code)->toBe('CB9');

    app(CurrentOutlet::class)->set($branch);
    app(ShiftService::class)->open($this->owner, 0);

    expect(validationMessage(fn () => $this->service->update($branch->fresh(), outletInput('Cabang Baru', 'CB1'))))->toContain('Kode outlet tidak bisa diubah');
});

test('only one outlet is primary and the primary outlet cannot be deactivated or deleted', function () {
    $branch = $this->service->create(outletInput('Cabang', 'CB1'), $this->owner);

    $this->service->setPrimary($branch, $this->owner);

    expect($branch->fresh()->is_primary)->toBeTrue()
        ->and(Outlet::where('is_primary', true)->count())->toBe(1)
        ->and(validationMessage(fn () => $this->service->deactivate($branch->fresh(), $this->owner)))->toContain('Outlet utama')
        ->and(validationMessage(fn () => $this->service->delete($branch->fresh(), $this->owner)))->toContain('Outlet utama');
});

test('an outlet with an open shift cannot be deactivated and an outlet with history cannot be deleted', function () {
    $branch = $this->service->create(outletInput('Cabang', 'CB1'), $this->owner);
    app(CurrentOutlet::class)->set($branch);
    $shift = app(ShiftService::class)->open($this->owner, 0);

    expect(validationMessage(fn () => $this->service->deactivate($branch, $this->owner)))->toContain('shift kasir yang terbuka');

    app(ShiftService::class)->close($shift, 0, $this->owner);
    $this->service->deactivate($branch, $this->owner);

    expect($branch->fresh()->is_active)->toBeFalse()
        ->and(validationMessage(fn () => $this->service->delete($branch->fresh(), $this->owner)))->toContain('riwayat');
});

test('an outlet without history can be deleted together with its stock rows', function () {
    $product = Product::factory()->create(['stock' => 3]);
    $branch = $this->service->create(outletInput('Cabang', 'CB1'), $this->owner);

    $this->service->delete($branch, $this->owner);

    expect(Outlet::count())->toBe(1)
        ->and(ProductStock::query()->where('product_id', $product->id)->count())->toBe(1);
});

test('reactivating an outlet respects the plan limit', function () {
    $this->tenant->update(['plan' => 'pro', 'max_outlets_override' => 2]);
    $one = $this->service->create(outletInput('Cabang 1', 'CB1'), $this->owner);
    $this->service->deactivate($one, $this->owner);
    $this->service->create(outletInput('Cabang 2', 'CB2'), $this->owner);

    expect(validationMessage(fn () => $this->service->activate($one->fresh(), $this->owner)))->toContain('dibatasi 2 outlet');
});

test('outlets beyond the plan limit are locked by priority while the primary stays operational', function () {
    $second = $this->service->create(outletInput('Cabang 2', 'CB2'), $this->owner);
    $third = $this->service->create(outletInput('Cabang 3', 'CB3'), $this->owner);
    $this->service->setPriorities([$third->id, $second->id], $this->owner);

    $this->tenant->update(['plan' => 'free']);
    expect($this->tenant->fresh()->operationalOutletIds())->toBe([primaryOutlet()->id]);

    $this->tenant->update(['plan' => 'pro', 'max_outlets_override' => 2]);
    expect($this->tenant->fresh()->operationalOutletIds())->toBe([primaryOutlet()->id, $third->id])
        ->and($second->fresh()->isLockedByPlan())->toBeTrue()
        ->and($third->fresh()->isOperational())->toBeTrue();

    $this->tenant->update(['max_outlets_override' => null]);
    expect($second->fresh()->isOperational())->toBeTrue();
});

test('the operating outlet can only be changed once per 30 days while over the limit', function () {
    $second = $this->service->create(outletInput('Cabang 2', 'CB2'), $this->owner);
    $third = $this->service->create(outletInput('Cabang 3', 'CB3'), $this->owner);
    $this->tenant->update(['plan' => 'pro', 'max_outlets_override' => 2]);

    $this->service->setPriorities([$third->id, $second->id], $this->owner);

    expect($this->tenant->fresh()->outlet_priority_changed_at)->not->toBeNull()
        ->and(validationMessage(fn () => $this->service->setPriorities([$second->id, $third->id], $this->owner)))->toContain('baru bisa diganti lagi');

    $this->tenant->update(['outlet_priority_changed_at' => now()->subDays(31)]);
    $this->service->setPriorities([$second->id, $third->id], $this->owner);

    expect($this->tenant->fresh()->operationalOutletIds())->toBe([primaryOutlet()->id, $second->id]);
});

test('priorities can change freely while the shop is within its limit', function () {
    $second = $this->service->create(outletInput('Cabang 2', 'CB2'), $this->owner);
    $third = $this->service->create(outletInput('Cabang 3', 'CB3'), $this->owner);

    $this->service->setPriorities([$third->id, $second->id], $this->owner);
    $this->service->setPriorities([$second->id, $third->id], $this->owner);

    expect($this->tenant->fresh()->outlet_priority_changed_at)->toBeNull();
});

test('copying an outlet replaces overrides and prices with a snapshot of the source', function () {
    $product = Product::factory()->create(['price' => 10000]);
    $other = Product::factory()->create(['price' => 5000]);
    $source = $this->service->create(outletInput('Sumber', 'SRC'), $this->owner);
    $this->service->setProductPrice($product->id, $source->id, 12500);
    OutletSettings::putMany($source->id, ['pos.tax_enabled' => '1', 'pos.tax_rate' => '11']);

    $copy = $this->service->create(outletInput('Salinan', 'CPY'), $this->owner, $source->id);
    $this->service->setProductPrice($other->id, $copy->id, 4000);
    $this->service->copyConfiguration($source, $copy);

    expect(ProductOutletPrice::query()->where('outlet_id', $copy->id)->pluck('price', 'product_id')->all())->toBe([$product->id => 12500])
        ->and(OutletSettings::overrides($copy->id))->toBe(['pos.tax_enabled' => '1', 'pos.tax_rate' => '11']);

    $this->service->setProductPrice($product->id, $source->id, 99000);
    OutletSettings::put($source->id, 'pos.tax_rate', '5');

    expect($product->priceAt($copy->id))->toBe(12500)
        ->and(OutletSettings::overrides($copy->id)['pos.tax_rate'])->toBe('11')
        ->and($product->priceAt($source->id))->toBe(99000)
        ->and($other->priceAt($copy->id))->toBe(5000);
});

test('limited user access always keeps at least one outlet', function () {
    $branch = $this->service->create(outletInput('Cabang', 'CB1'), $this->owner);
    $clerk = User::factory()->limitedToOutlets()->create();
    $this->service->syncOutletUsers($branch, [$clerk->id]);

    expect($clerk->outlets()->pluck('outlets.id')->all())->toContain($branch->id);

    $clerk->outlets()->detach(primaryOutlet()->id);

    expect(validationMessage(fn () => $this->service->syncOutletUsers($branch, [])))->toContain('minimal satu outlet');

    $clerk->forceFill(['all_outlets' => true])->save();
    $this->service->syncOutletUsers($branch, []);

    expect($clerk->outlets()->count())->toBe(1);
});

test('syncing user access lets the owner stay on all outlets', function () {
    $branch = $this->service->create(outletInput('Cabang', 'CB1'), $this->owner);

    $this->service->syncUserAccess($this->owner, false, [$branch->id]);

    expect($this->owner->fresh()->all_outlets)->toBeTrue()
        ->and($this->owner->outlets()->count())->toBe(0);
});

test('downgrading a plan notifies the owner which outlets are now locked and removes nothing', function () {
    Notification::fake();
    $platformOwner = $this->owner;
    $second = $this->service->create(outletInput('Cabang Dago', 'DGO'), $platformOwner);
    $this->service->create(outletInput('Cabang Lembang', 'LMB'), $platformOwner);

    app(CurrentTenant::class)->run(null, fn () => app(TenantSubscriptionManager::class)->update($this->tenant->fresh(), [
        'name' => $this->tenant->name,
        'plan' => 'free',
        'status' => Tenant::STATUS_ACTIVE,
        'access_ends_at' => null,
    ]));

    Notification::assertSentTo($platformOwner, OutletsLockedNotification::class, fn ($notification) => $notification->lockedNames === ['Cabang Dago', 'Cabang Lembang'] && $notification->maxOutlets === 1);
    expect(Outlet::count())->toBe(3)
        ->and($second->fresh()->isLockedByPlan())->toBeTrue()
        ->and(Sale::count())->toBe(0)
        ->and(CashShift::count())->toBe(0);
});
