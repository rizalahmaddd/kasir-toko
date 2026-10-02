<?php

use App\Events\ProductChanged;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function inOtherTenant(Closure $callback, ?Tenant $tenant = null): mixed
{
    return app(CurrentTenant::class)->run($tenant ?? Tenant::factory()->create(), $callback);
}

it('hides another shop\'s products from the API', function () {
    $foreign = inOtherTenant(fn () => Product::factory()->create(['name' => 'Milik Toko Lain']));
    $own = Product::factory()->create(['name' => 'Milik Sendiri']);
    apiActingAs('admin');

    $this->getJson('/api/v1/master-data/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);

    $this->getJson("/api/v1/master-data/products/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/v1/master-data/products/{$foreign->id}", ['name' => 'Diambil', 'unit' => 'pcs', 'price' => 1])->assertNotFound();
    $this->deleteJson("/api/v1/master-data/products/{$foreign->id}")->assertNotFound();

    expect($foreign->fresh()->name)->toBe('Milik Toko Lain');
});

it('returns 404 for another shop\'s sale page', function () {
    $foreign = inOtherTenant(fn () => Sale::factory()->create());
    actingAsAdmin();

    $this->get(route('sales.show', $foreign))->assertNotFound();
});

it('lets two shops use the same SKU but not twice in one shop', function () {
    inOtherTenant(fn () => Product::factory()->create(['sku' => 'KOPI-01']));
    apiActingAs('admin');
    $payload = ['sku' => 'KOPI-01', 'name' => 'Kopi', 'unit' => 'gelas', 'price' => 5000];

    $this->postJson('/api/v1/master-data/products', $payload)->assertCreated();
    $this->postJson('/api/v1/master-data/products', $payload)->assertJsonValidationErrors('sku');
});

it('rejects a category that belongs to another shop', function () {
    $foreignCategory = inOtherTenant(fn () => Category::factory()->create());
    apiActingAs('admin');

    $this->postJson('/api/v1/master-data/products', ['name' => 'Teh', 'unit' => 'gelas', 'price' => 4000, 'category_id' => $foreignCategory->id])
        ->assertJsonValidationErrors('category_id');
});

it('stores new records under the tenant of the token owner', function () {
    $other = Tenant::factory()->create();
    $user = inOtherTenant(fn () => User::factory()->create()->assignRole(seededRole('admin')), $other);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/master-data/products', ['sku' => 'TEH-01', 'name' => 'Teh', 'unit' => 'gelas', 'price' => 4000])->assertCreated();

    expect(Product::withoutGlobalScopes()->where('sku', 'TEH-01')->value('tenant_id'))->toBe($other->id);
});

it('numbers documents separately for each shop', function () {
    $year = now()->year;
    $numbers = app(DocumentNumberGenerator::class);

    expect($numbers->next('TRX'))->toBe("TRX-{$year}-0001")
        ->and($numbers->next('TRX'))->toBe("TRX-{$year}-0002")
        ->and(inOtherTenant(fn () => $numbers->next('TRX')))->toBe("TRX-{$year}-0001");
});

it('keeps settings per shop on top of the platform defaults', function () {
    app(CurrentTenant::class)->run(null, fn () => Setting::put('app_name', 'Kasir Platform'));
    Setting::put('company_name', 'Toko Sendiri');

    expect(Setting::get('app_name'))->toBe('Kasir Platform')
        ->and(Setting::get('company_name'))->toBe('Toko Sendiri')
        ->and(inOtherTenant(fn () => [Setting::get('app_name'), Setting::get('company_name')]))->toBe(['Kasir Platform', null]);
});

it('keeps the activity log per shop', function () {
    Product::factory()->create();

    expect(Activity::query()->count())->toBeGreaterThan(0)
        ->and(inOtherTenant(fn () => Activity::query()->count()))->toBe(0);
});

it('opens the customer display of the shop that owns the key', function () {
    $other = Tenant::factory()->create();
    $key = inOtherTenant(function () {
        Setting::putMany(['company_name' => 'Toko Pemilik Layar', 'display.enabled' => '1']);

        return User::factory()->create()->displayKey();
    }, $other);
    app(CurrentTenant::class)->set(null);

    $this->get(route('display.show', $key))->assertOk()->assertSee('Toko Pemilik Layar');
});

it('keeps role permissions per shop', function () {
    $other = Tenant::factory()->create();
    $otherCashier = inOtherTenant(fn () => User::factory()->create()->assignRole(seededRole('kasir')), $other);
    $ownRole = seededRole('kasir');

    $ownRole->givePermissionTo(Permission::findOrCreate('pos.void', 'web'));

    expect(inOtherTenant(fn () => $otherCashier->fresh()->can('pos.void'), $other))->toBeFalse()
        ->and(Role::query()->where('name', 'kasir')->count())->toBe(2);
});

it('lists only the shop\'s own roles on the roles page', function () {
    inOtherTenant(fn () => Role::findOrCreate('peran-toko-lain', 'web'));
    actingAsSuperAdmin();

    $this->get(route('settings.roles-and-permissions'))->assertOk()->assertDontSee('peran-toko-lain');
});

it('broadcasts realtime events on the shop\'s own channel', function () {
    $event = new ProductChanged(Product::factory()->create(), 'created');

    expect($event->broadcastOn()[0]->name)->toBe("private-tenant.{$this->tenant->id}.dashboard");
});

it('stores uploads in the shop\'s own folder', function () {
    Storage::fake('public');
    $product = Product::factory()->create();
    apiActingAs('admin');

    $this->post("/api/v1/master-data/products/{$product->id}/image", ['image' => UploadedFile::fake()->image('kopi.jpg')], ['Accept' => 'application/json'])
        ->assertSuccessful();

    expect($product->fresh()->image_path)->toStartWith("tenants/{$this->tenant->id}/products/");
});
