<?php

use App\Livewire\Platform\ActivityLog;
use App\Livewire\Platform\Payments;
use App\Livewire\Platform\ServiceSettings;
use App\Livewire\Platform\Tenants;
use App\Livewire\Platform\TenantShow;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantSubscriptionLog;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\SaasPlans;
use App\Support\SaasSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('edits plan limits from the panel and applies them to shops on that plan', function () {
    $tenant = Tenant::factory()->create(['plan' => 'free']);
    actingAsPlatformAdmin();

    Livewire::test(ServiceSettings::class)
        ->call('openEditPlan', 'free')
        ->set('planLabel', 'Gratis Plus')
        ->set('planPrice', 'Rp 0')
        ->set('planMaxUsers', '5')
        ->set('planMaxProducts', '')
        ->call('savePlan')
        ->assertHasNoErrors();

    expect($tenant->planLabel())->toBe('Gratis Plus')
        ->and($tenant->limit('users'))->toBe(5)
        ->and($tenant->limit('products'))->toBeNull()
        ->and(SaasPlans::find('free')['price'])->toBe(0);
});

it('adds a new plan and only deletes plans no shop uses', function () {
    Tenant::factory()->create(['plan' => 'free']);
    actingAsPlatformAdmin();

    $page = Livewire::test(ServiceSettings::class)
        ->call('openCreatePlan')
        ->set('planKey', 'premium')
        ->set('planLabel', 'Premium')
        ->call('savePlan')
        ->assertHasNoErrors();

    expect(SaasPlans::keys())->toContain('premium');

    $page->call('confirmDeletePlan', 'free')->call('delete');
    $page->call('confirmDeletePlan', 'trial')->call('delete');
    $page->call('confirmDeletePlan', 'premium')->call('delete');

    expect(SaasPlans::keys())->toContain('free', 'trial')->not->toContain('premium');
});

it('rejects a plan code that already exists', function () {
    actingAsPlatformAdmin();

    Livewire::test(ServiceSettings::class)
        ->call('openCreatePlan')
        ->set('planKey', 'pro')
        ->set('planLabel', 'Pro Lagi')
        ->call('savePlan')
        ->assertHasErrors(['planKey' => 'not_in']);
});

it('sets monthly and yearly discounts on plans from the panel', function () {
    actingAsPlatformAdmin();

    Livewire::test(ServiceSettings::class)
        ->call('openEditPlan', 'pro')
        ->set('planPrice', '25000')
        ->set('planMonthlyDiscount', '5000')
        ->set('planYearlyPrice', '250000')
        ->set('planYearlyDiscount', '50000')
        ->call('savePlan')
        ->assertHasNoErrors();

    $plan = SaasPlans::find('pro');
    expect($plan['price'])->toBe(25000)
        ->and($plan['monthly_discount'])->toBe(5000)
        ->and(SaasPlans::effectiveMonthlyPrice($plan))->toBe(20000)
        ->and($plan['yearly_price'])->toBe(250000)
        ->and($plan['yearly_discount'])->toBe(50000)
        ->and(SaasPlans::effectiveYearlyPrice($plan))->toBe(200000);
});

it('sets discounts easily using final price or percentage', function () {
    actingAsPlatformAdmin();

    // 1. Set diskon bulanan pakai nilai akhir (Harga normal 30.000, Nilai akhir 24.000 -> diskon 6.000 / 20%)
    // 2. Set diskon tahunan pakai persentase (Harga normal 300.000, Diskon 25% -> diskon 75.000, nilai akhir 225.000)
    Livewire::test(ServiceSettings::class)
        ->call('openEditPlan', 'pro')
        ->set('planPrice', '30000')
        ->set('planMonthlyFinalPrice', '24000')
        ->assertSet('planMonthlyDiscount', '6000')
        ->assertSet('planMonthlyDiscountPercent', '20')
        ->set('planYearlyPrice', '300000')
        ->set('planYearlyDiscountPercent', '25')
        ->assertSet('planYearlyDiscount', '75000')
        ->assertSet('planYearlyFinalPrice', '225000')
        ->call('savePlan')
        ->assertHasNoErrors();

    $plan = SaasPlans::find('pro');
    expect($plan['price'])->toBe(30000)
        ->and($plan['monthly_discount'])->toBe(6000)
        ->and(SaasPlans::effectiveMonthlyPrice($plan))->toBe(24000)
        ->and($plan['yearly_price'])->toBe(300000)
        ->and($plan['yearly_discount'])->toBe(75000)
        ->and(SaasPlans::effectiveYearlyPrice($plan))->toBe(225000);
});

it('rejects discounts that exceed plan prices', function () {
    actingAsPlatformAdmin();

    Livewire::test(ServiceSettings::class)
        ->call('openEditPlan', 'pro')
        ->set('planPrice', '20000')
        ->set('planMonthlyDiscount', '25000')
        ->set('planYearlyPrice', '199000')
        ->set('planYearlyDiscount', '200000')
        ->call('savePlan')
        ->assertHasErrors([
            'planMonthlyDiscount',
            'planYearlyDiscount',
        ]);
});

it('rejects promo final prices that exceed normal plan prices', function () {
    actingAsPlatformAdmin();

    Livewire::test(ServiceSettings::class)
        ->call('openEditPlan', 'pro')
        ->set('planPrice', '20000')
        ->set('planMonthlyFinalPrice', '25000')
        ->set('planYearlyPrice', '199000')
        ->set('planYearlyFinalPrice', '250000')
        ->call('savePlan')
        ->assertHasErrors([
            'planMonthlyFinalPrice',
            'planYearlyFinalPrice',
        ]);
});

it('ignores service settings written under a shop', function () {
    $this->tenant->update(['plan' => Tenant::PLAN_TRIAL]);
    Setting::put(SaasPlans::SETTING_KEY, json_encode(['trial' => ['label' => 'Bebas', 'max_users' => null, 'max_products' => null]]));
    Setting::put(SaasSettings::TRIAL_DAYS, '365');

    expect($this->tenant->fresh()->limit('products'))->toBe(config('saas.plans.trial.max_products'))
        ->and(SaasSettings::trialDays())->toBe(config('saas.trial_days'));
});

it('shows the plan prices and how to pay to a blocked shop', function () {
    app(CurrentTenant::class)->run(null, function () {
        Setting::put(SaasSettings::SUPPORT_CONTACT, 'WA 0812-0000-1111');
        Setting::put(SaasSettings::PAYMENT_INSTRUCTIONS, 'Transfer ke BCA 123');
    });
    SaasPlans::save(['trial' => SaasPlans::find('trial'), 'basic' => ['label' => 'Basic', 'price' => 99000, 'max_users' => 3, 'max_products' => 1000]]);
    $this->tenant->update(['plan' => Tenant::PLAN_TRIAL, 'trial_ends_at' => now()->subDay()]);
    actingAsAdmin();

    $this->get(route('subscription.inactive'))
        ->assertOk()
        ->assertSee('WA 0812-0000-1111')
        ->assertSee('Transfer ke BCA 123')
        ->assertSee('Rp99.000/bulan');
});

it('lists recorded payments of every shop within the period', function () {
    $shop = Tenant::factory()->create(['name' => 'Kopi Pagi']);
    TenantSubscriptionLog::factory()->for($shop)->create(['amount' => 150000]);
    TenantSubscriptionLog::factory()->create(['amount' => 99000]);
    TenantSubscriptionLog::factory()->create(['amount' => null]);
    TenantSubscriptionLog::factory()->create(['amount' => 500000, 'created_at' => now()->subYear()]);
    actingAsPlatformAdmin();

    Livewire::test(Payments::class)
        ->assertViewHas('total', 249000)
        ->assertViewHas('count', 2)
        ->assertSee('Kopi Pagi')
        ->set('search', 'Kopi')
        ->assertViewHas('total', 150000);
});

it('keeps the payments page away from shop accounts', function () {
    actingAsSuperAdmin();

    $this->get(route('platform.payments'))->assertForbidden();
    $this->get(route('platform.activity-log'))->assertForbidden();
    $this->get(route('platform.tenants.export', $this->tenant))->assertForbidden();
});

it('filters shops by plan and by expiry', function () {
    $ending = Tenant::factory()->trial(daysLeft: 3)->create(['name' => 'Toko Hampir Habis']);
    $expired = Tenant::factory()->trial(daysLeft: -2)->create(['name' => 'Toko Sudah Habis']);
    $basic = Tenant::factory()->create(['name' => 'Toko Free', 'plan' => 'free', 'subscription_ends_at' => now()->addYear()]);
    actingAsPlatformAdmin();

    $names = fn ($page) => $page->viewData('tenants')->pluck('name')->all();

    $page = Livewire::test(Tenants::class)->set('statusFilter', 'ending');
    expect($names($page))->toBe([$ending->name]);

    $page->set('statusFilter', 'expired');
    expect($names($page))->toBe([$expired->name]);

    $page->set('statusFilter', '')->set('planFilter', 'free');
    expect($names($page))->toBe([$basic->name]);
});

it('shows platform actions in the platform activity log without other shop activity', function () {
    $tenant = Tenant::factory()->trial(daysLeft: 2)->create(['name' => 'Kopi Pagi']);
    app(CurrentTenant::class)->run($tenant, fn () => activity('settings')->log('Aktivitas internal toko'));
    actingAsPlatformAdmin();

    Livewire::test(TenantShow::class, ['tenant' => $tenant])->set('extendDays', '30')->call('extend');

    Livewire::test(ActivityLog::class)
        ->assertSee('Langganan Kopi Pagi: Perpanjangan.')
        ->assertDontSee('Aktivitas internal toko');
});

it('exports the data of one shop for the platform admin', function () {
    $tenant = provisionedShop('Kopi Pagi');
    app(CurrentTenant::class)->run($tenant, fn () => Product::factory()->create(['name' => 'Kopi Tubruk']));
    Product::factory()->create(['name' => 'Produk Toko Lain']);
    actingAsPlatformAdmin();

    $response = $this->get(route('platform.tenants.export', $tenant))->assertOk()->assertDownload();
    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    $products = (string) $zip->getFromName('produk.csv');
    $zip->close();
    @unlink($response->getFile()->getPathname());

    expect($products)->toContain('Kopi Tubruk')->not->toContain('Produk Toko Lain');
});

it('signs every user of a shop out of all devices', function () {
    config(['session.driver' => 'database']);
    $tenant = provisionedShop();
    $owner = $tenant->owner();
    $owner->createToken('android');
    $rememberToken = $owner->remember_token;
    DB::table('sessions')->insert(['id' => 'sesi-pemilik', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    actingAsPlatformAdmin();

    Livewire::test(TenantShow::class, ['tenant' => $tenant])->call('revokeSessions');

    $owner = User::withoutGlobalScopes()->find($owner->id);

    expect($owner->tokens()->count())->toBe(0)
        ->and($owner->remember_token)->not->toBe($rememberToken)
        ->and(DB::table('sessions')->where('user_id', $owner->id)->exists())->toBeFalse();
});

it('shows and manages lifetime plan in platform service settings and tenant show', function () {
    actingAsPlatformAdmin();

    // Pastikan tabel pengaturan memuat paket Lifetime
    Livewire::test(ServiceSettings::class)
        ->assertSee('Lifetime (Permanen)')
        ->assertSee('Sekali Bayar')
        ->assertSee('Permanen')
        ->call('openEditPlan', 'lifetime')
        ->assertSet('planKey', 'lifetime')
        ->set('planPrice', '550000')
        ->set('planMonthlyDiscountPercent', '10')
        ->call('savePlan')
        ->assertHasNoErrors();

    $lifetimePlan = SaasPlans::find('lifetime');
    expect($lifetimePlan['price'])->toBe(550000)
        ->and($lifetimePlan['monthly_discount'])->toBe(55000)
        ->and(SaasPlans::effectiveMonthlyPrice('lifetime'))->toBe(495000);

    // Platform admin bisa mengubah toko ke paket Lifetime
    $tenant = provisionedShop('Toko Calon Lifetime');
    Livewire::test(TenantShow::class, ['tenant' => $tenant])
        ->call('openChangePlan')
        ->set('plan', 'lifetime')
        ->set('access_ends_at', '')
        ->call('changePlan')
        ->assertHasNoErrors();

    $tenant->refresh();
    expect($tenant->plan)->toBe('lifetime')
        ->and($tenant->isLifetime())->toBeTrue()
        ->and($tenant->isPro())->toBeTrue()
        ->and($tenant->accessEndsAt())->toBeNull();
});
