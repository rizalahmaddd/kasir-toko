<?php

use App\Enums\StockCountReason;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Livewire\Inventory\StockCounts;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Features;
use App\Support\Navigation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

test('a stock count document belongs to the active tenant and primary outlet and casts its columns', function () {
    actingAsAdmin();

    $count = StockCount::factory()->create(['scope' => StockCountScope::Categories, 'scope_category_ids' => [3, 5]]);

    expect($count->tenant_id)->toBe($this->tenant->id)
        ->and($count->outlet_id)->toBe(primaryOutlet()->id)
        ->and($count->status)->toBe(StockCountStatus::Counting)
        ->and($count->scope)->toBe(StockCountScope::Categories)
        ->and($count->scope_category_ids)->toBe([3, 5])
        ->and($count->blind_count)->toBeTrue()
        ->and($count->isOpen())->toBeTrue();
});

test('the open scope only returns documents that are still running', function () {
    actingAsAdmin();

    $counting = StockCount::factory()->create();
    $review = StockCount::factory()->review()->create();
    StockCount::factory()->posted()->create();
    StockCount::factory()->cancelled()->create();

    expect(StockCount::query()->open()->pluck('id')->sort()->values()->all())->toBe([$counting->id, $review->id]);
});

test('active entries skip voided ones and entries are reachable from the document', function () {
    actingAsAdmin();

    $item = StockCountItem::factory()->create(['reason' => StockCountReason::Damaged, 'flags' => ['split_entries']]);
    StockCountEntry::factory()->for($item, 'item')->create(['quantity_base' => 5]);
    StockCountEntry::factory()->for($item, 'item')->create(['quantity_base' => 12]);
    StockCountEntry::factory()->for($item, 'item')->voided()->create(['quantity_base' => 100]);

    expect((float) $item->activeEntries()->sum('quantity_base'))->toBe(17.0)
        ->and($item->stockCount->entries()->count())->toBe(3)
        ->and($item->reason)->toBe(StockCountReason::Damaged)
        ->and($item->hasFlag('split_entries'))->toBeTrue()
        ->and($item->isCounted())->toBeFalse();
});

test('an entry client uuid can only be stored once', function () {
    actingAsAdmin();

    $entry = StockCountEntry::factory()->create();

    expect(fn () => StockCountEntry::factory()->create(['client_uuid' => $entry->client_uuid]))->toThrow(QueryException::class);
});

test('a product on a stock count document cannot be hard deleted', function () {
    actingAsAdmin();

    $item = StockCountItem::factory()->create();

    expect(fn () => $item->product->forceDelete())->toThrow(QueryException::class);
});

test('stock movements keep the time the movement really happened', function () {
    actingAsAdmin();

    $item = StockCountItem::factory()->create();
    $movement = StockMovement::query()->create([
        'product_id' => $item->product_id,
        'type' => 'opname',
        'quantity' => -1,
        'stock_before' => 10,
        'stock_after' => 9,
        'occurred_at' => '2026-10-08 09:15:00',
        'reference_type' => StockCount::class,
        'reference_id' => $item->stock_count_id,
    ]);

    expect($movement->fresh()->occurred_at->format('H:i'))->toBe('09:15')
        ->and($movement->reference->is($item->stockCount))->toBeTrue();
});

test('an outlet with a stock count document has history', function () {
    actingAsAdmin();
    $branch = makeOutlet(['code' => 'CB1']);

    expect($branch->hasHistory())->toBeFalse();

    StockCount::factory()->create(['outlet_id' => $branch->id]);

    expect($branch->fresh()->hasHistory())->toBeTrue();
});

test('a branch user only sees stock counts of their own outlet', function () {
    actingAsAdmin();
    $branch = makeOutlet(['code' => 'CB1']);
    $mine = StockCount::factory()->create();
    StockCount::factory()->create(['outlet_id' => $branch->id]);

    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->assignRole(seededRole('staff'));
    $clerk->outlets()->attach(primaryOutlet()->id, ['tenant_id' => $this->tenant->id]);
    $this->actingAs($clerk)->get(route('inventory.opname'))->assertOk();

    expect(StockCount::query()->pluck('id')->all())->toBe([$mine->id]);
});

test('default roles get the stock count permissions', function () {
    expect(seededRole('admin')->hasPermissionTo('inventory.opname.manage'))->toBeTrue()
        ->and(seededRole('admin')->hasPermissionTo('reports.stock.view'))->toBeTrue()
        ->and(seededRole('staff')->hasPermissionTo('inventory.opname.count'))->toBeTrue()
        ->and(seededRole('staff')->hasPermissionTo('inventory.opname.manage'))->toBeFalse()
        ->and(seededRole('kasir')->getPermissionNames()->all())->not->toContain('inventory.opname.count');
});

test('the sync command adds the stock count permissions to existing default roles', function () {
    $tenant = provisionedShop();
    setPermissionsTeamId($tenant->id);
    $staff = Role::query()->where('tenant_id', $tenant->id)->where('name', 'staff')->sole();
    $staff->revokePermissionTo('inventory.opname.count');

    Artisan::call('saas:sync-role-permissions', ['permission' => ['inventory.opname.count']]);

    expect($staff->fresh()->hasPermissionTo('inventory.opname.count'))->toBeTrue();
    setPermissionsTeamId(null);
});

test('the stock count page needs the count permission and the feature', function () {
    actingAsRole('kasir');
    $this->get(route('inventory.opname'))->assertForbidden();

    $staff = actingAsRole('staff');
    StockCount::factory()->create(['number' => 'OPN-2026-0001']);

    $this->get(route('inventory.opname'))->assertOk()->assertSee('OPN-2026-0001');
    expect(array_column(Navigation::linksForUser($staff), 'label'))->toContain('Stok Opname');

    Features::setDisabled(['inventory.opname']);

    $this->get(route('inventory.opname'))->assertForbidden();
    expect(array_column(Navigation::linksForUser($staff), 'label'))->not->toContain('Stok Opname');
});

test('the stock count list filters by tab', function () {
    actingAsAdmin();
    StockCount::factory()->create(['number' => 'OPN-RUN']);
    StockCount::factory()->posted()->create(['number' => 'OPN-DONE']);

    Livewire::test(StockCounts::class)
        ->assertSee('OPN-RUN')->assertDontSee('OPN-DONE')
        ->set('tab', 'posted')
        ->assertSee('OPN-DONE')->assertDontSee('OPN-RUN');
});
