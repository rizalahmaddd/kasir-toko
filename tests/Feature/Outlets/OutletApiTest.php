<?php

use App\Models\CashShift;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\OutletSettings;
use App\Support\Receipt;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function apiStock(Product $product, Outlet $outlet, float $stock): void
{
    ProductStock::query()->updateOrCreate(['product_id' => $product->id, 'outlet_id' => $outlet->id], ['stock' => $stock]);
    $product->forceFill(['stock' => ProductStock::query()->where('product_id', $product->id)->sum('stock')])->saveQuietly();
}

/**
 * @return array<string, mixed>
 */
function apiCart(Product $product, float $quantity = 1, array $extra = []): array
{
    return [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'price' => $product->price]],
        'payments' => [['method' => 'cash', 'amount' => 500000]],
        ...$extra,
    ];
}

beforeEach(function () {
    $this->main = primaryOutlet();
    $this->branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    $this->product = Product::factory()->create(['price' => 15000, 'stock' => 0]);
    apiStock($this->product, $this->main, 10);
    apiStock($this->product, $this->branch, 4);
});

it('describes the account outlets and the shop limit in auth/me', function () {
    $owner = apiActingAs('superadmin');

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.all_outlets', true)
        ->assertJsonPath('data.current_outlet_id', $this->main->id)
        ->assertJsonCount(2, 'data.outlets')
        ->assertJsonPath('data.outlets.0.is_primary', true)
        ->assertJsonPath('data.outlets.1.code', 'DGO')
        ->assertJsonPath('data.tenant.is_multi_outlet', true)
        ->assertJsonPath('data.tenant.limits.outlets', ['used' => 2, 'max' => 5])
        ->assertJsonPath('data.app.update_required', true);

    $this->withHeader('X-App-Version', '1.1.0')->getJson('/api/v1/auth/me')->assertJsonPath('data.app.update_required', false);
});

it('serves only the outlets a limited account may use', function () {
    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->assignRole(seededRole('kasir'));
    $clerk->outlets()->attach($this->branch->id, ['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($clerk);

    $this->getJson('/api/v1/outlets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->branch->id);
    $this->getJson('/api/v1/outlets?scope=all')->assertForbidden();
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.current_outlet_id', $this->branch->id);
});

it('rejects an outlet header the account may not use and one that belongs to another shop', function () {
    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->assignRole(seededRole('kasir'));
    $clerk->outlets()->attach($this->branch->id, ['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($clerk);

    $foreign = app(CurrentTenant::class)->run(Tenant::factory()->create(), fn () => Outlet::query()->firstOrFail());

    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson('/api/v1/pos/config')
        ->assertForbidden()->assertJsonPath('reason', 'outlet_forbidden');
    $this->withHeader('X-Outlet-Id', (string) $foreign->id)->getJson('/api/v1/pos/config')
        ->assertForbidden()->assertJsonPath('reason', 'outlet_forbidden');
    $this->withHeader('X-Outlet-Id', 'abc')->getJson('/api/v1/pos/config')->assertForbidden();
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/pos/config')->assertOk()->assertJsonPath('data.outlet_id', $this->branch->id);
});

it('lets auth/me and the outlet list recover from a stale outlet header', function () {
    apiActingAs('kasir')->forceFill(['all_outlets' => false])->save();

    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/auth/me')->assertOk();
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/outlets')->assertOk()->assertJsonCount(0, 'data');
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/pos/config')->assertForbidden();
});

it('tells an account without any outlet that it has no outlet access', function () {
    apiActingAs('kasir')->forceFill(['all_outlets' => false])->save();

    $this->getJson('/api/v1/pos/config')->assertForbidden()->assertJsonPath('reason', 'no_outlet_access');
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonCount(0, 'data.outlets');
});

it('falls back to the outlet of the open shift, then the saved outlet, for clients that send no header', function () {
    $clerk = apiActingAs('kasir');
    $this->putJson('/api/v1/auth/current-outlet', ['outlet_id' => $this->branch->id])->assertOk()->assertJsonPath('data.id', $this->branch->id);

    $this->getJson('/api/v1/pos/config')->assertOk()->assertJsonPath('data.outlet_id', $this->branch->id);

    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->postJson('/api/v1/pos/shift', ['opening_cash' => 0])->assertCreated()->assertJsonPath('data.outlet.id', $this->main->id);

    $this->getJson('/api/v1/pos/config')->assertOk()->assertJsonPath('data.outlet_id', $this->main->id)->assertJsonPath('data.shift_outlet_id', $this->main->id);
    expect($clerk->fresh()->default_outlet_id)->toBe($this->branch->id);
});

it('does not save a locked or forbidden outlet as the default', function () {
    apiActingAs('kasir');
    $this->tenant->update(['plan' => 'free']);

    $this->putJson('/api/v1/auth/current-outlet', ['outlet_id' => $this->branch->id])->assertUnprocessable()->assertJsonValidationErrors('outlet_id');
});

it('sells at the stock, price and tax of the header outlet and replays by client uuid', function () {
    apiActingAs('kasir');
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id);
    $this->product->outletPrices()->create(['outlet_id' => $this->branch->id, 'price' => 18000]);

    $this->getJson("/api/v1/pos/products?ids={$this->product->id}")->assertOk()
        ->assertJsonPath('data.0.price', 18000)
        ->assertJsonPath('data.0.base_price', 15000)
        ->assertJsonPath('data.0.has_outlet_price', true)
        ->assertJsonPath('data.0.stock', '4.000');

    $this->postJson('/api/v1/pos/shift', ['opening_cash' => 0])->assertCreated();
    $payload = apiCart($this->product, 2);
    $payload['items'][0]['price'] = 18000;

    $sale = $this->postJson('/api/v1/pos/checkout', $payload)->assertCreated()->assertJsonPath('data.total', 36000)->assertJsonPath('data.outlet.code', 'DGO')->json('data');
    $this->postJson('/api/v1/pos/checkout', $payload)->assertOk()->assertJsonPath('data.id', $sale['id']);

    $this->postJson('/api/v1/pos/checkout', apiCart($this->product, 3, ['items' => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 18000]]]))
        ->assertUnprocessable()->assertJsonPath('reason', 'insufficient_stock');
    expect(ProductStock::query()->where('product_id', $this->product->id)->where('outlet_id', $this->branch->id)->value('stock'))->toBe('2.000');
});

it('refuses checkout for a shift of another outlet with the outlet_mismatch reason', function () {
    apiActingAs('kasir');
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->postJson('/api/v1/pos/shift', ['opening_cash' => 0])->assertCreated();

    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->postJson('/api/v1/pos/checkout', apiCart($this->product))
        ->assertUnprocessable()->assertJsonPath('reason', 'outlet_mismatch');
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->postJson('/api/v1/pos/checkout', apiCart($this->product, 1, ['outlet_id' => $this->branch->id]))
        ->assertUnprocessable()->assertJsonPath('reason', 'outlet_mismatch');
});

it('answers 423 outlet_locked for a plan-locked outlet but accepts queued offline sales', function () {
    apiActingAs('kasir');
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->postJson('/api/v1/pos/shift', ['opening_cash' => 0])->assertCreated();
    $this->tenant->update(['plan' => 'free']);

    $this->postJson('/api/v1/pos/checkout', apiCart($this->product))->assertStatus(423)->assertJsonPath('reason', 'outlet_locked');
    $this->postJson('/api/v1/pos/checkout', apiCart($this->product, 1, ['offline' => true]))->assertCreated();
    $this->getJson('/api/v1/outlets')->assertJsonPath('data.1.is_operational', false);
});

it('keeps held orders per outlet', function () {
    apiActingAs('kasir');
    $cart = ['items' => [['product_id' => $this->product->id, 'name' => 'X', 'quantity' => 1, 'price' => 15000]], 'total' => 15000];

    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->postJson('/api/v1/pos/held-orders', ['cart' => $cart])->assertCreated();

    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/pos/held-orders')->assertOk()->assertJsonCount(0, 'data');
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson('/api/v1/pos/held-orders')->assertOk()->assertJsonCount(1, 'data');
});

it('filters lists to the active outlet and only lets permitted accounts look at another or all outlets', function () {
    $clerk = apiActingAs('kasir');
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->postJson('/api/v1/pos/shift', ['opening_cash' => 0])->assertCreated();
    $this->postJson('/api/v1/pos/checkout', apiCart($this->product))->assertCreated();

    $admin = actingAsAdminForApi();
    Sale::factory()->create(['outlet_id' => $this->branch->id, 'cash_shift_id' => CashShift::factory()->create(['outlet_id' => $this->branch->id])->id]);

    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson('/api/v1/sales')->assertOk()->assertJsonCount(1, 'data');
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson('/api/v1/sales?outlet_id=all')->assertOk()->assertJsonCount(2, 'data');
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson("/api/v1/sales?outlet_id={$this->branch->id}")->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($clerk);
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson('/api/v1/sales?outlet_id=all')->assertForbidden()->assertJsonPath('reason', 'outlet_forbidden');
});

function actingAsAdminForApi(): User
{
    return apiActingAs('admin');
}

it('hides sales of other outlets from a limited cashier even by direct id', function () {
    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->assignRole(seededRole('kasir'));
    $clerk->outlets()->attach($this->main->id, ['tenant_id' => $this->tenant->id]);
    $otherSale = Sale::factory()->create(['outlet_id' => $this->branch->id, 'cash_shift_id' => CashShift::factory()->create(['outlet_id' => $this->branch->id])->id]);
    Sanctum::actingAs($clerk);

    $this->getJson("/api/v1/sales/{$otherSale->id}")->assertNotFound();
});

it('manages outlets for the owner and blocks the limit with a validation error on name', function () {
    apiActingAs('superadmin');

    $created = $this->postJson('/api/v1/outlets', ['name' => 'Cabang Lembang', 'code' => 'lmb', 'address' => 'Jl. Raya 9', 'copy_from_outlet_id' => $this->branch->id])
        ->assertCreated()->assertJsonPath('data.code', 'LMB')->json('data');

    $this->putJson("/api/v1/outlets/{$created['id']}", ['name' => 'Cabang Lembang 2', 'code' => 'LMB'])->assertOk()->assertJsonPath('data.name', 'Cabang Lembang 2');
    $this->postJson('/api/v1/outlets', ['name' => 'Cabang Lembang 2', 'code' => 'ZZZ'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/outlets', ['name' => 'Lain', 'code' => 'bad code'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->tenant->update(['plan' => 'free']);
    $this->postJson('/api/v1/outlets', ['name' => 'Cabang Lain', 'code' => 'CLN'])->assertUnprocessable()->assertJsonValidationErrors('name');

    $this->tenant->update(['plan' => 'pro']);
    $this->postJson("/api/v1/outlets/{$created['id']}/primary")->assertOk()->assertJsonPath('data.is_primary', true);
    $this->putJson("/api/v1/outlets/{$created['id']}/active", ['is_active' => false])->assertUnprocessable();
    $this->putJson("/api/v1/outlets/{$this->branch->id}/active", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->deleteJson("/api/v1/outlets/{$this->branch->id}")->assertNoContent();
    expect(Outlet::query()->whereKey($this->branch->id)->exists())->toBeFalse();
});

it('keeps outlet management for accounts with the outlets.manage permission', function () {
    apiActingAs('admin');

    $this->getJson('/api/v1/outlets?scope=all')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson('/api/v1/outlets', ['name' => 'Cabang', 'code' => 'CB1'])->assertForbidden();
    $this->putJson("/api/v1/outlets/{$this->branch->id}", ['name' => 'X', 'code' => 'DGO'])->assertForbidden();
    $this->deleteJson("/api/v1/outlets/{$this->branch->id}")->assertForbidden();
    $this->postJson("/api/v1/outlets/{$this->branch->id}/primary")->assertForbidden();
});

it('assigns users to an outlet and edits tax, payment and receipt settings with inheritance', function () {
    $owner = apiActingAs('superadmin');
    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->outlets()->attach($this->main->id, ['tenant_id' => $this->tenant->id]);

    $this->getJson("/api/v1/outlets/{$this->main->id}/users")->assertOk()
        ->assertJsonFragment(['id' => $clerk->id, 'has_all_outlets' => false, 'assigned' => true])
        ->assertJsonFragment(['id' => $owner->id, 'has_all_outlets' => true]);
    $this->putJson("/api/v1/outlets/{$this->main->id}/users", ['user_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
    $this->putJson("/api/v1/outlets/{$this->branch->id}/users", ['user_ids' => [$clerk->id]])->assertOk()->assertJsonPath('data.user_ids', [$clerk->id]);

    $this->getJson("/api/v1/outlets/{$this->branch->id}/settings")->assertOk()->assertJsonPath('data.tax.inherit', true)->assertJsonPath('data.payments.inherit', true);

    $this->putJson("/api/v1/outlets/{$this->branch->id}/settings", [
        'tax' => ['inherit' => false, 'enabled' => true, 'rate' => 10, 'label' => 'PB1'],
        'payments' => ['inherit' => false, 'methods' => ['qris']],
        'receipt' => ['inherit' => false, 'width' => '80', 'header' => 'Dago', 'footer' => 'Terima kasih', 'auto_print' => true],
    ])->assertOk()
        ->assertJsonPath('data.tax', ['inherit' => false, 'enabled' => true, 'rate' => '10', 'label' => 'PB1'])
        ->assertJsonPath('data.payments.methods', ['cash', 'qris'])
        ->assertJsonPath('data.receipt.width', '80');

    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/pos/config')->assertOk()
        ->assertJsonPath('data.tax_rate', 10)
        ->assertJsonPath('data.tax_label', 'PB1')
        ->assertJsonPath('data.receipt_width', '80')
        ->assertJsonCount(2, 'data.payment_methods');
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson('/api/v1/pos/config')->assertOk()->assertJsonPath('data.tax_rate', 0);

    $this->putJson("/api/v1/outlets/{$this->branch->id}/settings", ['tax' => ['inherit' => true]])->assertOk()->assertJsonPath('data.tax.inherit', true);
    $this->putJson("/api/v1/outlets/{$this->branch->id}/settings", ['qris' => ['inherit' => false, 'payload' => 'bukan-qris']])->assertUnprocessable()->assertJsonValidationErrors('qris.payload');
});

it('copies settings and prices from another outlet through the API', function () {
    apiActingAs('superadmin');
    $this->product->outletPrices()->create(['outlet_id' => $this->branch->id, 'price' => 19000]);
    OutletSettings::put($this->branch->id, 'pos.receipt_footer', 'Dago');

    $this->postJson("/api/v1/outlets/{$this->main->id}/copy", ['source_outlet_id' => $this->branch->id])->assertOk();
    $this->postJson("/api/v1/outlets/{$this->main->id}/copy", ['source_outlet_id' => $this->main->id])->assertUnprocessable();

    expect($this->product->priceAt($this->main->id))->toBe(19000)
        ->and(OutletSettings::overrides($this->main->id))->toBe(['pos.receipt_footer' => 'Dago']);
});

it('saves per-outlet prices from the product endpoints and lists stock of the header outlet', function () {
    apiActingAs('admin');

    $created = $this->postJson('/api/v1/master-data/products', [
        'name' => 'Teh Botol', 'unit' => 'btl', 'price' => 5000, 'stock' => 6,
        'outlet_prices' => [['outlet_id' => $this->branch->id, 'price' => 6000]],
    ])->assertCreated()->assertJsonPath('data.price', 5000)->json('data');

    $this->getJson("/api/v1/master-data/products/{$created['id']}")->assertOk()->assertJsonPath('data.outlet_prices', [['outlet_id' => $this->branch->id, 'price' => 6000]]);
    $this->getJson('/api/v1/master-data/products')->assertOk()->assertJsonMissingPath('data.0.outlet_prices');
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson("/api/v1/master-data/products/{$created['id']}")
        ->assertOk()->assertJsonPath('data.price', 6000)->assertJsonPath('data.base_price', 5000)->assertJsonPath('data.stock', '0.000')->assertJsonPath('data.stock_total', '6.000');
    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson("/api/v1/master-data/products/{$created['id']}")
        ->assertOk()->assertJsonPath('data.price', 5000)->assertJsonPath('data.stock', '6.000');

    $this->putJson("/api/v1/master-data/products/{$created['id']}", ['name' => 'Teh Botol', 'unit' => 'btl', 'price' => 5500, 'outlet_prices' => [['outlet_id' => $this->branch->id, 'price' => null]]])->assertOk();
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson("/api/v1/master-data/products/{$created['id']}")->assertJsonPath('data.price', 5500)->assertJsonPath('data.has_outlet_price', false);

    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/inventory/stock?search=Teh')->assertOk()->assertJsonPath('data.0.stock', '0.000');
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/inventory/stock?level=out')->assertOk()->assertJsonFragment(['id' => $created['id']]);
});

it('adjusts stock and moves stock between outlets through the API', function () {
    apiActingAs('admin');

    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->postJson('/api/v1/inventory/adjustments', ['product_id' => $this->product->id, 'type' => 'opname', 'quantity' => 9])
        ->assertCreated()->assertJsonPath('data.outlet_id', $this->branch->id)->assertJsonPath('data.stock_after', '9.000');

    $transfer = $this->postJson('/api/v1/inventory/transfers', [
        'from_outlet_id' => $this->branch->id, 'to_outlet_id' => $this->main->id, 'note' => 'balik',
        'items' => [['product_id' => $this->product->id, 'quantity' => 4]],
    ])->assertCreated()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.items.0.quantity', '4.000')->json('data');

    expect(ProductStock::query()->where('product_id', $this->product->id)->where('outlet_id', $this->branch->id)->value('stock'))->toBe('5.000')
        ->and(ProductStock::query()->where('product_id', $this->product->id)->where('outlet_id', $this->main->id)->value('stock'))->toBe('14.000');

    $this->getJson('/api/v1/inventory/transfers')->assertOk()->assertJsonPath('data.0.number', $transfer['number']);
    $this->postJson('/api/v1/inventory/transfers', ['from_outlet_id' => $this->main->id, 'to_outlet_id' => $this->main->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertUnprocessable();
    $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/cancel")->assertUnprocessable();

    apiActingAs('kasir');
    $this->getJson('/api/v1/inventory/transfers')->assertForbidden();
});

it('prints the identity of the outlet in use on receipts and keeps the shop name', function () {
    apiActingAs('admin');
    $this->main->update(['address' => 'Jl. Pusat 1', 'phone' => '021111']);
    $this->branch->update(['address' => 'Jl. Dago 9']);
    OutletSettings::put($this->branch->id, 'pos.receipt_footer', 'Cabang Dago buka 24 jam');

    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->getJson('/api/v1/meta')->assertOk()
        ->assertJsonPath('data.receipt.outlet_name', 'Cabang Dago')
        ->assertJsonPath('data.receipt.address', 'Jl. Dago 9')
        ->assertJsonPath('data.receipt.phone', '')
        ->assertJsonPath('data.receipt.footer', 'Cabang Dago buka 24 jam');

    $this->withHeader('X-Outlet-Id', (string) $this->main->id)->getJson('/api/v1/meta')->assertOk()
        ->assertJsonPath('data.receipt.outlet_name', $this->main->name)
        ->assertJsonPath('data.receipt.address', 'Jl. Pusat 1')
        ->assertJsonPath('data.receipt.phone', '021111');
});

it('shows the sale outlet on the receipt text and keeps single-outlet receipts unchanged', function () {
    apiActingAs('kasir');
    $this->withHeader('X-Outlet-Id', (string) $this->branch->id)->postJson('/api/v1/pos/shift', ['opening_cash' => 0])->assertCreated();
    $sale = $this->postJson('/api/v1/pos/checkout', apiCart($this->product))->assertCreated()->json('data');

    $this->getJson("/api/v1/sales/{$sale['id']}/receipt")->assertOk()->assertJsonPath('data.text', fn ($text) => str_contains($text, "Cabang Dago\n{$sale['number']}"));

    $single = Tenant::factory()->create();
    $text = app(CurrentTenant::class)->run($single, function () {
        $outlet = Outlet::query()->firstOrFail();
        $outlet->update(['name' => 'Gerai Tunggal']);
        $shift = CashShift::factory()->create(['outlet_id' => $outlet->id]);

        return Receipt::text(Sale::factory()->create(['outlet_id' => $outlet->id, 'cash_shift_id' => $shift->id]));
    });

    expect($text)->not->toContain('Gerai Tunggal');
});
