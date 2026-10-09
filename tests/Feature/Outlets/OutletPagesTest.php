<?php

use App\Livewire\Dashboard;
use App\Livewire\Inventory\StockIndex;
use App\Livewire\Inventory\StockTransfers;
use App\Livewire\Layout\OutletSwitcher;
use App\Livewire\MasterData\Products;
use App\Livewire\Platform\ServiceSettings;
use App\Livewire\Platform\TenantShow;
use App\Livewire\Pos\Cashier;
use App\Livewire\Sales\SaleIndex;
use App\Livewire\Settings\Outlets;
use App\Livewire\Settings\RolesAndPermissions;
use App\Models\CashShift;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductOutletPrice;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\OutletSettings;
use App\Support\SaasPlans;
use Livewire\Livewire;

beforeEach(function () {
    $this->main = primaryOutlet();
});

function pageStock(Product $product, Outlet $outlet, float $stock): void
{
    ProductStock::query()->updateOrCreate(['product_id' => $product->id, 'outlet_id' => $outlet->id], ['stock' => $stock]);
    $product->forceFill(['stock' => ProductStock::query()->where('product_id', $product->id)->sum('stock')])->saveQuietly();
}

test('the outlet page needs the outlet view permission and shows the quota', function () {
    actingAsRole('staff');
    $this->get(route('settings.outlets'))->assertForbidden();

    actingAsSuperAdmin();
    $this->get(route('settings.outlets'))->assertOk()->assertSee($this->main->name)->assertSee('Outlet aktif');
});

test('the owner adds an outlet and the add button is blocked once the plan limit is reached', function () {
    actingAsSuperAdmin();
    $this->tenant->update(['plan' => 'free']);

    Livewire::test(Outlets::class)
        ->assertSee('hanya mendukung satu outlet')
        ->call('openCreate')
        ->set('name', 'Cabang Dago')->set('code', 'dgo')
        ->call('save')
        ->assertHasErrors(['name']);

    $this->tenant->update(['plan' => 'pro']);

    Livewire::test(Outlets::class)
        ->call('openCreate')
        ->set('name', 'Cabang Dago')->set('code', 'dgo')->set('address', 'Jl. Dago 1')
        ->call('save')
        ->assertHasNoErrors();

    expect(Outlet::query()->where('code', 'DGO')->sole()->address)->toBe('Jl. Dago 1');
});

test('creating an outlet can copy settings and prices from an existing outlet', function () {
    actingAsSuperAdmin();
    $product = Product::factory()->create(['price' => 10000]);
    ProductOutletPrice::create(['product_id' => $product->id, 'outlet_id' => $this->main->id, 'price' => 11000]);
    OutletSettings::put($this->main->id, 'pos.receipt_footer', 'Pusat');

    Livewire::test(Outlets::class)
        ->call('openCreate')
        ->set('name', 'Cabang Baru')->set('code', 'CBB')->set('copyFromId', (string) $this->main->id)
        ->call('save')
        ->assertHasNoErrors();

    $copy = Outlet::query()->where('code', 'CBB')->sole();
    expect($product->priceAt($copy->id))->toBe(11000)
        ->and(OutletSettings::overrides($copy->id))->toBe(['pos.receipt_footer' => 'Pusat']);
});

test('the owner edits tax, payments and receipt per outlet and can return to the shop defaults', function () {
    actingAsSuperAdmin();
    $branch = makeOutlet(['name' => 'Cabang', 'code' => 'CB1']);

    Livewire::test(Outlets::class)
        ->call('openConfig', $branch->id)
        ->assertSet('inheritTax', true)
        ->set('inheritTax', false)->set('taxEnabled', true)->set('taxRate', '10')->set('taxLabel', 'PB1')
        ->set('inheritPayments', false)->set('paymentMethods', ['qris'])
        ->call('saveConfig')
        ->assertHasNoErrors();

    expect(OutletSettings::overrides($branch->id))->toMatchArray(['pos.tax_enabled' => '1', 'pos.tax_rate' => '10', 'pos.tax_label' => 'PB1'])
        ->and(json_decode(OutletSettings::overrides($branch->id)['pos.payment_methods'], true))->toBe(['cash', 'qris']);

    Livewire::test(Outlets::class)
        ->call('openConfig', $branch->id)
        ->assertSet('inheritTax', false)->assertSet('taxLabel', 'PB1')
        ->set('inheritTax', true)->set('inheritPayments', true)
        ->call('saveConfig');

    expect(OutletSettings::overrides($branch->id))->toBe([]);
});

test('an invalid outlet QRIS text is rejected', function () {
    actingAsSuperAdmin();
    $branch = makeOutlet(['code' => 'CB1']);

    Livewire::test(Outlets::class)
        ->call('openConfig', $branch->id)
        ->set('inheritQris', false)->set('qrisPayload', 'bukan qris')
        ->call('saveConfig')
        ->assertHasErrors(['qrisPayload']);
});

test('outlet users are assigned from the access dialog but never lose their last outlet', function () {
    actingAsSuperAdmin();
    $branch = makeOutlet(['name' => 'Cabang', 'code' => 'CB1']);
    $clerk = User::factory()->limitedToOutlets()->create(['name' => 'Kasir Dago']);
    $clerk->outlets()->attach($this->main->id, ['tenant_id' => $this->tenant->id]);

    Livewire::test(Outlets::class)
        ->call('openAccess', $this->main->id)
        ->set('accessUserIds', [])
        ->call('saveAccess')
        ->assertHasErrors(['accessUserIds']);

    Livewire::test(Outlets::class)
        ->call('openAccess', $branch->id)
        ->set('accessUserIds', [$clerk->id])
        ->call('saveAccess')
        ->assertHasNoErrors();

    expect($clerk->outlets()->count())->toBe(2);
});

test('only the permitted role can manage outlets while an admin can still read the list', function () {
    actingAsAdmin();

    Livewire::test(Outlets::class)->assertOk()->call('openCreate')->assertForbidden();
});

test('the outlet switcher appears only for several outlets and saves the choice', function () {
    $owner = actingAsSuperAdmin();

    Livewire::test(OutletSwitcher::class)->assertDontSee('Ganti outlet');

    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    Livewire::test(OutletSwitcher::class)->assertSee('Cabang Dago')->call('switchTo', $branch->id)->assertRedirect();

    expect(session('current_outlet_id'))->toBe($branch->id)
        ->and($owner->fresh()->default_outlet_id)->toBe($branch->id);
});

test('a limited cashier cannot switch to an outlet outside their access', function () {
    $branch = makeOutlet(['code' => 'CB1']);
    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->assignRole(seededRole('kasir'));
    $clerk->outlets()->attach($this->main->id, ['tenant_id' => $this->tenant->id]);
    test()->actingAs($clerk);

    Livewire::test(OutletSwitcher::class)->call('switchTo', $branch->id)->assertForbidden();
});

test('web requests follow the outlet saved in the session and fall back when it is no longer allowed', function () {
    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->assignRole(seededRole('kasir'));
    $clerk->outlets()->attach($this->main->id, ['tenant_id' => $this->tenant->id]);
    $this->actingAs($clerk);

    $this->withSession(['current_outlet_id' => $branch->id])->get(route('shifts.index'))->assertOk();
    expect(app(CurrentOutlet::class)->id())->toBe($this->main->id);

    $clerk->outlets()->attach($branch->id, ['tenant_id' => $this->tenant->id]);
    $this->withSession(['current_outlet_id' => $branch->id])->get(route('shifts.index'))->assertOk();
    expect(app(CurrentOutlet::class)->id())->toBe($branch->id);
});

test('a limited user without any outlet is told there is no outlet access', function () {
    makeOutlet(['code' => 'CB1']);
    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->assignRole(seededRole('kasir'));
    $this->actingAs($clerk);

    $this->get(route('shifts.index'))->assertForbidden();
    $this->get(route('profile'))->assertOk();
});

test('the product form saves outlet prices and outlet minimum stock and shows the outlet price in the list', function () {
    actingAsAdmin();
    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);

    Livewire::test(Products::class)
        ->call('openCreateModal')
        ->set('name', 'Teh Botol')->set('price', '5000')->set('stock', '12')->set('min_stock', '2')
        ->set('outletPrices', [(string) $branch->id => '6500'])
        ->set('outletMinStocks', [(string) $branch->id => '4'])
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::where('name', 'Teh Botol')->sole();
    expect($product->priceAt($branch->id))->toBe(6500)
        ->and($product->priceAt($this->main->id))->toBe(5000)
        ->and((float) ProductStock::where('product_id', $product->id)->where('outlet_id', $this->main->id)->value('stock'))->toBe(12.0)
        ->and((float) ProductStock::where('product_id', $product->id)->where('outlet_id', $branch->id)->value('min_stock'))->toBe(4.0);

    Livewire::test(Products::class)
        ->call('openEditModal', $product->id)
        ->assertSet('outletPrices', [$branch->id => '6500'])
        ->set('outletPrices', [(string) $branch->id => ''])
        ->call('save');

    expect($product->fresh()->priceAt($branch->id))->toBe(5000)
        ->and(ProductOutletPrice::count())->toBe(0);

    app(CurrentOutlet::class)->set($branch);
    Livewire::test(Products::class)->assertSee('Teh Botol');
});

test('the cashier catalog shows outlet price and stock and warns about a shift in another outlet', function () {
    $cashier = actingAsRole('kasir');
    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    $product = Product::factory()->create(['name' => 'Kopi Susu', 'price' => 20000, 'stock' => 0]);
    pageStock($product, $this->main, 9);
    pageStock($product, $branch, 3);
    ProductOutletPrice::create(['product_id' => $product->id, 'outlet_id' => $branch->id, 'price' => 22000]);

    app(CurrentOutlet::class)->set($branch);
    $payload = Cashier::productPayload(Product::withOutletData()->findOrFail($product->id));

    expect($payload['price'])->toBe(22000)->and($payload['stock'])->toBe(3.0);

    app(CurrentOutlet::class)->set($this->main);
    CashShift::factory()->create(['user_id' => $cashier->id, 'outlet_id' => $branch->id]);

    Livewire::test(Cashier::class)->assertSee('Shift Anda masih terbuka di outlet Cabang Dago');
});

test('the cashier shows a locked state for a plan-locked outlet', function () {
    actingAsRole('kasir');
    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    $this->tenant->update(['plan' => 'free']);
    app(CurrentOutlet::class)->set($branch);

    Livewire::test(Cashier::class)->assertSee('terkunci oleh batas paket');
});

test('the stock page shows only the stock of the active outlet', function () {
    actingAsAdmin();
    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    $product = Product::factory()->create(['name' => 'Gula Pasir', 'stock' => 0, 'min_stock' => 5]);
    pageStock($product, $this->main, 20);
    pageStock($product, $branch, 2);

    app(CurrentOutlet::class)->set($this->main);
    $main = Livewire::test(StockIndex::class)->assertSee('Gula Pasir')->assertDontSee('MENIPIS');
    app(CurrentOutlet::class)->set($branch);
    Livewire::test(StockIndex::class)->assertSee('MENIPIS')->set('level', 'low')->assertSee('Gula Pasir');
});

test('the transfer page moves stock and cancels it again', function () {
    actingAsAdmin();
    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    $product = Product::factory()->create(['name' => 'Gula Pasir', 'stock' => 0]);
    pageStock($product, $this->main, 20);
    app(CurrentOutlet::class)->set($this->main);

    Livewire::test(StockTransfers::class)
        ->call('openCreate')
        ->set('toOutletId', (string) $branch->id)
        ->set('productSearch', 'Gula')
        ->call('addProduct', $product->id)
        ->set('items.0.quantity', '8')
        ->call('save')
        ->assertHasNoErrors();

    $transfer = StockTransfer::sole();
    expect((float) ProductStock::where('product_id', $product->id)->where('outlet_id', $branch->id)->value('stock'))->toBe(8.0);

    Livewire::test(StockTransfers::class)->assertSee($transfer->number)->call('confirmCancel', $transfer->id)->call('cancelTransfer');

    expect($transfer->fresh()->isCancelled())->toBeTrue()
        ->and((float) ProductStock::where('product_id', $product->id)->where('outlet_id', $this->main->id)->value('stock'))->toBe(20.0);
});

test('the transfer page rejects an empty cart and the same outlet', function () {
    actingAsAdmin();
    makeOutlet(['code' => 'CB1']);
    app(CurrentOutlet::class)->set($this->main);

    Livewire::test(StockTransfers::class)
        ->call('openCreate')
        ->set('toOutletId', (string) $this->main->id)
        ->call('save')
        ->assertHasErrors(['toOutletId', 'items']);
});

test('the transfer page is hidden from roles without the transfer permission', function () {
    actingAsRole('kasir');

    $this->get(route('inventory.transfers'))->assertForbidden();
});

test('the transfer menu shows only for shops with several outlets', function () {
    actingAsAdmin();

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Transfer Stok');

    makeOutlet(['code' => 'CB1']);
    $this->get(route('dashboard'))->assertOk()->assertSee('Transfer Stok');
});

test('the sales history defaults to the active outlet and admins can widen it to all outlets', function () {
    actingAsAdmin();
    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    $mainShift = CashShift::factory()->create(['outlet_id' => $this->main->id]);
    $branchShift = CashShift::factory()->create(['outlet_id' => $branch->id]);
    $mainSale = Sale::factory()->create(['cash_shift_id' => $mainShift->id, 'outlet_id' => $this->main->id]);
    $branchSale = Sale::factory()->create(['cash_shift_id' => $branchShift->id, 'outlet_id' => $branch->id]);
    app(CurrentOutlet::class)->set($this->main);

    Livewire::test(SaleIndex::class)->assertSee($mainSale->number)->assertDontSee($branchSale->number)
        ->set('outletFilter', 'all')->assertSee($mainSale->number)->assertSee($branchSale->number)
        ->set('outletFilter', (string) $branch->id)->assertDontSee($mainSale->number)->assertSee($branchSale->number);
});

test('a cashier cannot widen the sales history beyond the active outlet', function () {
    $branch = makeOutlet(['code' => 'CB1']);
    $cashier = actingAsRole('kasir');
    $cashier->forceFill(['all_outlets' => false])->save();
    $cashier->outlets()->attach($this->main->id, ['tenant_id' => $this->tenant->id]);
    $mainShift = CashShift::factory()->create(['user_id' => $cashier->id, 'outlet_id' => $this->main->id]);
    $branchShift = CashShift::factory()->create(['user_id' => $cashier->id, 'outlet_id' => $branch->id]);
    $mine = Sale::factory()->create(['user_id' => $cashier->id, 'cash_shift_id' => $mainShift->id, 'outlet_id' => $this->main->id]);
    $foreign = Sale::factory()->create(['user_id' => $cashier->id, 'cash_shift_id' => $branchShift->id, 'outlet_id' => $branch->id]);
    test()->actingAs($cashier->fresh());

    Livewire::test(SaleIndex::class)->set('outletFilter', 'all')->assertSee($mine->number)->assertDontSee($foreign->number);
});

test('the dashboard offers an outlet filter only to accounts that may look at all outlets', function () {
    actingAsAdmin();
    makeOutlet(['code' => 'CB1']);

    Livewire::test(Dashboard::class)->assertSee('Semua outlet')->set('outletFilter', 'all')->assertOk()->set('outletFilter', '')->assertOk();

    actingAsRole('kasir');
    Livewire::test(Dashboard::class)->assertDontSee('Semua outlet');
});

test('the user form assigns outlets to a new user in a multi outlet shop', function () {
    actingAsSuperAdmin();
    seededRole('kasir');
    $branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);

    Livewire::test(RolesAndPermissions::class)
        ->call('openCreateUserModal')
        ->set('newUserName', 'Kasir Dago')->set('newUserUsername', 'kasirdago')->set('newUserEmail', 'dago@toko.test')
        ->set('newUserPassword', 'rahasia-panjang-1')->set('newUserRoles', ['kasir'])
        ->set('newUserOutlets', [])
        ->call('createUser')
        ->assertHasErrors(['newUserOutlets']);

    Livewire::test(RolesAndPermissions::class)
        ->call('openCreateUserModal')
        ->set('newUserName', 'Kasir Dago')->set('newUserUsername', 'kasirdago')->set('newUserEmail', 'dago@toko.test')
        ->set('newUserPassword', 'rahasia-panjang-1')->set('newUserRoles', ['kasir'])
        ->set('newUserOutlets', [(string) $branch->id])
        ->call('createUser')
        ->assertHasNoErrors();

    $user = User::where('username', 'kasirdago')->sole();
    expect($user->all_outlets)->toBeFalse()
        ->and($user->outlets()->pluck('outlets.id')->all())->toBe([$branch->id]);
});

test('the platform admin sets the outlet limit per plan and a per-shop override', function () {
    actingAsPlatformAdmin();

    Livewire::test(ServiceSettings::class)
        ->call('openEditPlan', 'trial')
        ->assertSet('planMaxOutlets', '1')
        ->set('planMaxOutlets', '2')
        ->call('savePlan')
        ->assertHasNoErrors();

    expect(SaasPlans::find('trial')['max_outlets'])->toBe(2);

    Livewire::test(ServiceSettings::class)->call('openEditPlan', 'pro')->set('planMaxOutlets', '0')->call('savePlan')->assertHasErrors(['planMaxOutlets']);

    Livewire::test(TenantShow::class, ['tenant' => $this->tenant])
        ->call('openChangePlan')
        ->set('maxOutletsOverride', '9')
        ->call('changePlan')
        ->assertHasNoErrors();

    expect($this->tenant->fresh()->maxOutlets())->toBe(9);
});

test('the multi-step wizard guides through identity, custom configuration, staff access, and creates the outlet', function () {
    actingAsSuperAdmin();
    $this->tenant->update(['plan' => 'pro']);

    $staff = User::factory()->limitedToOutlets()->create(['name' => 'Staf Cabang']);
    $staff->outlets()->attach($this->main->id, ['tenant_id' => $this->tenant->id]);

    Livewire::test(Outlets::class)
        ->call('openCreate')
        ->assertSet('wizardStep', 1)
        // Step 1 validation
        ->call('nextStep')
        ->assertHasErrors(['name', 'code'])
        ->set('name', 'Cabang Sukajadi')
        ->set('code', 'SKJ')
        ->set('address', 'Jl. Sukajadi No. 12')
        ->set('phone', '08123456789')
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('wizardStep', 2)
        // Step 2: Custom configuration
        ->set('configMode', 'custom')
        ->set('inheritTax', false)
        ->set('taxEnabled', true)
        ->set('taxRate', '10')
        ->set('taxLabel', 'PB1')
        ->set('inheritService', false)
        ->set('serviceChargeRate', '5')
        ->set('inheritReceipt', false)
        ->set('receiptWidth', '80')
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('wizardStep', 3)
        // Step 3: Staff access
        ->set('newOutletUserIds', [$staff->id])
        ->call('nextStep')
        ->assertSet('wizardStep', 4)
        // Step 4: Summary & Save
        ->call('save')
        ->assertHasNoErrors();

    $newOutlet = Outlet::query()->where('code', 'SKJ')->sole();
    expect($newOutlet->name)->toBe('Cabang Sukajadi')
        ->and($newOutlet->address)->toBe('Jl. Sukajadi No. 12')
        ->and(OutletSettings::overrides($newOutlet->id))->toMatchArray([
            'pos.tax_enabled' => '1',
            'pos.tax_rate' => '10',
            'pos.tax_label' => 'PB1',
            'pos.service_charge_rate' => '5',
            'pos.receipt_width' => '80',
        ])
        ->and($staff->outlets()->where('outlets.id', $newOutlet->id)->exists())->toBeTrue();
});
