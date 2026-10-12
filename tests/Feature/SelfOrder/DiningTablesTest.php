<?php

use App\Livewire\Settings\DiningTables;
use App\Models\DiningTable;
use App\Models\Tenant;
use App\Support\Features;
use App\Support\Navigation;
use App\Support\OutletSettings;
use App\Support\PosSettings;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Features::setEnabled(['business.order-type', 'business.self-order']);
});

test('only users who manage QR ordering on a Pro shop open the tables page', function () {
    actingAsRole('kasir');
    $this->get(route('settings.tables'))->assertForbidden();

    $admin = actingAsRole('admin');
    $this->get(route('settings.tables'))->assertOk()->assertSee('Pemesanan QR di');
    expect(collect(Navigation::linksForUser($admin))->pluck('route'))->toContain('settings.tables');

    $admin->tenant->update(['plan' => Tenant::PLAN_FREE]);
    $this->get(route('settings.tables'))->assertRedirect(route('settings.subscription'));
});

test('the menu link is hidden until the outlet also has order types', function () {
    $admin = actingAsRole('admin');
    Features::setEnabled(['business.self-order']);

    expect(collect(Navigation::linksForUser($admin))->pluck('route'))->not->toContain('settings.tables');
});

test('tables are created with a token, unique per outlet, and scoped to the active outlet', function () {
    actingAsRole('admin');
    $branch = makeOutlet();
    DiningTable::factory()->create(['name' => '01', 'outlet_id' => $branch->id]);

    Livewire::test(DiningTables::class)
        ->assertDontSee('Meja 01')
        ->set('name', '01')->set('area', 'Teras')->set('capacity', '4')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Meja 01')
        ->set('name', '01')
        ->call('save')
        ->assertHasErrors(['name']);

    $table = DiningTable::query()->forOutlet(primaryOutlet()->id)->sole();

    expect(strlen($table->token))->toBe(40)
        ->and($table->area)->toBe('Teras');
});

test('regenerating replaces the table token and the standee token', function () {
    actingAsRole('admin');
    $table = DiningTable::factory()->create(['name' => '07']);
    $oldToken = $table->token;
    $oldStandee = primaryOutlet()->selfOrderToken();

    Livewire::test(DiningTables::class)
        ->call('confirmRotate', $table->id)->call('rotateToken')
        ->call('confirmRotate', 0)->call('rotateToken');

    expect($table->fresh()->token)->not->toBe($oldToken)
        ->and(primaryOutlet()->self_order_token)->not->toBe($oldStandee);
});

test('ordering is switched and configured per outlet', function () {
    actingAsRole('admin');
    $branch = makeOutlet();

    Livewire::test(DiningTables::class)
        ->assertSee('Buka Pemesanan')
        ->call('toggleOrdering')
        ->assertSee('Tutup Pemesanan')
        ->set('kitchenFlow', 'auto')->set('dineInPayment', 'before')->set('expireMinutes', '30')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->set('expireMinutes', '0')
        ->call('saveSettings')
        ->assertHasErrors(['expireMinutes']);

    expect(PosSettings::selfOrderEnabled())->toBeTrue()
        ->and(PosSettings::selfOrderKitchenFlow())->toBe('auto')
        ->and(PosSettings::selfOrderDineInPayment())->toBe('before')
        ->and(PosSettings::selfOrderExpireMinutes())->toBe(30)
        ->and(OutletSettings::get('pos.self_order_enabled', $branch->id))->toBeNull();
});

test('a user without the permission cannot change tables or settings', function () {
    $kasir = actingAsRole('kasir');
    $kasir->givePermissionTo(Permission::findOrCreate('pos.sell', 'web'));
    $table = DiningTable::factory()->create();

    Livewire::test(DiningTables::class)->call('toggleOrdering')->assertForbidden();
    Livewire::test(DiningTables::class)->call('confirmRotate', $table->id)->assertForbidden();
    Livewire::test(DiningTables::class)->set('name', '09')->call('save')->assertForbidden();
});

test('QR print pages render the ordering links', function () {
    actingAsRole('admin');
    $table = DiningTable::factory()->create(['name' => '12', 'area' => 'Teras']);
    DiningTable::factory()->inactive()->create(['name' => '13']);

    $this->get(route('print.table-tent', $table))->assertOk()->assertSee('Pindai untuk memesan')->assertSee('12');
    $this->get(route('print.table-grid'))->assertOk()->assertSee('1 meja aktif')->assertSee('Teras');
    $this->get(route('print.self-order-standee'))->assertOk()->assertSee('Pesan bawa pulang');
});
