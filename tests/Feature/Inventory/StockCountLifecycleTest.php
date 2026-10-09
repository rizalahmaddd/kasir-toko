<?php

use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Pos\StockCountService;
use App\Support\CurrentOutlet;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->main = primaryOutlet();
    $this->counts = app(StockCountService::class);
});

function countEntry(StockCount $count, User $user, Product $product, float $quantity, array $extra = []): StockCountEntry
{
    return app(StockCountService::class)->recordEntry($count, $user, ['client_uuid' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => $quantity, ...$extra]);
}

test('starting an all-products count takes every tracked product with the right rules', function () {
    $tracked = Product::factory()->create();
    setOutletStock($tracked, $this->main->id, 7);
    $untracked = Product::factory()->untracked()->create();
    $inactiveEmpty = Product::factory()->inactive()->create();
    setOutletStock($inactiveEmpty, $this->main->id, 0);
    $inactiveStocked = Product::factory()->inactive()->create();
    setOutletStock($inactiveStocked, $this->main->id, 3);
    $parent = Product::factory()->create(['variant_options' => [['name' => 'Ukuran', 'values' => ['S', 'M']]]]);
    $deletedStocked = Product::factory()->create();
    setOutletStock($deletedStocked, $this->main->id, 2);
    $deletedStocked->delete();
    $deletedEmpty = Product::factory()->create();
    setOutletStock($deletedEmpty, $this->main->id, 0);
    $deletedEmpty->delete();

    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);

    expect($count->number)->toBe('OPN-'.now()->year.'-0001')
        ->and($count->status)->toBe(StockCountStatus::Counting)
        ->and($count->hold_adjustments)->toBeTrue()
        ->and($count->blind_count)->toBeTrue()
        ->and($count->items()->pluck('product_id')->sort()->values()->all())->toBe([$tracked->id, $inactiveStocked->id, $deletedStocked->id])
        ->and((float) $count->items()->where('product_id', $tracked->id)->value('expected_qty'))->toBe(7.0);

    expect($count->items()->whereIn('product_id', [$untracked->id, $inactiveEmpty->id, $parent->id, $deletedEmpty->id])->exists())->toBeFalse();
});

test('a category or picked-products count only takes those products and does not hold adjustments by default', function () {
    $drinks = Category::factory()->create();
    $tea = Product::factory()->create(['category_id' => $drinks->id]);
    $snack = Product::factory()->create();

    $byCategory = $this->counts->start($this->admin, $this->main->id, StockCountScope::Categories, [$drinks->id]);
    $byProduct = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$snack->id]);

    expect($byCategory->items()->pluck('product_id')->all())->toBe([$tea->id])
        ->and($byCategory->scope_category_ids)->toBe([$drinks->id])
        ->and($byCategory->hold_adjustments)->toBeFalse()
        ->and($byProduct->items()->pluck('product_id')->all())->toBe([$snack->id]);
});

test('a primary outlet without a stock row counts the stock it inherits', function () {
    $product = Product::factory()->create(['stock' => 12]);

    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$product->id]);
    $entry = countEntry($count, $this->admin, $product, 10);

    expect((float) $count->items()->value('expected_qty'))->toBe(12.0)
        ->and((float) $entry->system_qty_at_count)->toBe(12.0);
});

test('overlapping counts in the same outlet are rejected but other outlets are fine', function () {
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    $branch = makeOutlet(['code' => 'CB1']);

    $first = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$product->id]);
    $overlap = posRejection(fn () => $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$product->id, $other->id]));
    $all = posRejection(fn () => $this->counts->start($this->admin, $this->main->id, StockCountScope::All));
    $separate = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$other->id]);
    $elsewhere = $this->counts->start($this->admin, $branch->id, StockCountScope::All);

    expect($overlap->reason)->toBe('stock_count_overlap')
        ->and($overlap->getMessage())->toContain($first->number)
        ->and($all->reason)->toBe('stock_count_overlap')
        ->and($separate->exists)->toBeTrue()
        ->and($elsewhere->outlet_id)->toBe($branch->id);
});

test('only stock count managers can start a count and a locked outlet refuses it', function () {
    Product::factory()->create();
    $staff = User::factory()->create();
    $staff->assignRole(seededRole('staff'));

    expect(posRejection(fn () => $this->counts->start($staff, $this->main->id, StockCountScope::All))->reason)->toBe('forbidden');

    $branch = makeOutlet(['code' => 'CB1']);
    $this->tenant->update(['plan' => 'free']);
    app(CurrentOutlet::class)->flush();

    expect(posRejection(fn () => $this->counts->start($this->admin, $branch->id, StockCountScope::All))->reason)->toBe('outlet_locked');
});

test('an entry with the same client uuid is only stored once', function () {
    $product = Product::factory()->create();
    setOutletStock($product, $this->main->id, 10);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    $uuid = (string) Str::uuid();

    $first = countEntry($count, $this->admin, $product, 4, ['client_uuid' => $uuid]);
    $again = countEntry($count, $this->admin, $product, 4, ['client_uuid' => $uuid]);

    expect($again->id)->toBe($first->id)
        ->and(StockCountEntry::query()->count())->toBe(1)
        ->and((float) $count->items()->value('counted_qty'))->toBe(4.0);
});

test('entries outside the scope are refused unless the count covers all products', function () {
    $listed = Product::factory()->create();
    $outside = Product::factory()->create();
    $untracked = Product::factory()->untracked()->create();
    $picked = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$listed->id]);

    expect(posRejection(fn () => countEntry($picked, $this->admin, $outside, 1))->reason)->toBe('stock_count_out_of_scope')
        ->and(posRejection(fn () => countEntry($picked, $this->admin, $untracked, 1))->reason)->toBe('stock_count_not_tracked');

    $this->counts->cancel($picked, $this->admin);
    $all = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    $newProduct = Product::factory()->create();
    countEntry($all, $this->admin, $newProduct, 2);

    expect($all->items()->where('product_id', $newProduct->id)->value('counted_qty'))->toEqual('2.000');
});

test('a voided entry stops counting and only its owner or a manager may void it', function () {
    $product = Product::factory()->create();
    setOutletStock($product, $this->main->id, 10);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    $staff = User::factory()->create();
    $staff->assignRole(seededRole('staff'));

    $mine = countEntry($count, $staff, $product, 5);
    $admins = countEntry($count, $this->admin, $product, 3);

    expect(posRejection(fn () => $this->counts->voidEntry($admins, $staff))->reason)->toBe('forbidden');

    $this->counts->voidEntry($mine, $staff);
    $item = $count->items()->first();

    expect($mine->fresh()->isVoided())->toBeTrue()
        ->and($mine->fresh()->voided_by)->toBe($staff->id)
        ->and((float) $item->counted_qty)->toBe(3.0)
        ->and((float) $item->variance_qty)->toBe(-7.0);
});

test('a counter finishing does not move the document but a manager sends it to review and back', function () {
    Product::factory()->create();
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    $staff = User::factory()->create();
    $staff->assignRole(seededRole('staff'));

    expect($this->counts->submit($count, $staff)->fresh()->status)->toBe(StockCountStatus::Counting);

    $reviewed = $this->counts->submit($count, $this->admin);
    expect($reviewed->status)->toBe(StockCountStatus::Review)
        ->and($reviewed->submitted_by)->toBe($this->admin->id);

    expect($this->counts->reopen($count, $this->admin)->status)->toBe(StockCountStatus::Counting);
});

test('cancelling needs a reason once counted and never touches stock', function () {
    $product = Product::factory()->create();
    setOutletStock($product, $this->main->id, 10);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    countEntry($count, $this->admin, $product, 4);

    expect(posRejection(fn () => $this->counts->cancel($count, $this->admin))->reason)->toBe('reason_required');

    $cancelled = $this->counts->cancel($count, $this->admin, 'Salah pilih outlet');

    expect($cancelled->status)->toBe(StockCountStatus::Cancelled)
        ->and($cancelled->cancel_reason)->toBe('Salah pilih outlet')
        ->and(outletStockQty($product, $this->main->id))->toBe(10.0)
        ->and(StockMovement::query()->where('type', 'opname')->exists())->toBeFalse()
        ->and(posRejection(fn () => countEntry($count, $this->admin, $product, 1))->reason)->toBe('stock_count_closed');
});

test('counts can be entered per unit and are stored in the base unit', function () {
    $product = Product::factory()->create(['unit' => 'pcs']);
    $pack = $product->units()->create(['name' => 'pak', 'factor' => 10, 'barcode' => 'PAK-1', 'sort_order' => 1]);
    $box = $product->units()->create(['name' => 'dus', 'factor' => 40, 'sort_order' => 2]);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$product->id]);

    $single = countEntry($count, $this->admin, $product, 1, ['unit_id' => $pack->id]);
    $mixed = countEntry($count, $this->admin, $product, 0, ['breakdown' => [['unit_id' => $box->id, 'quantity' => 2], ['unit_id' => $pack->id, 'quantity' => 5], ['unit_id' => null, 'quantity' => 3]]]);

    expect((float) $single->quantity_base)->toBe(10.0)
        ->and($single->product_unit_id)->toBe($pack->id)
        ->and((float) $mixed->quantity_base)->toBe(133.0)
        ->and($mixed->breakdown)->toHaveCount(3)
        ->and((float) $count->items()->value('counted_qty'))->toBe(143.0);

    $other = Product::factory()->create();
    $foreign = $other->units()->create(['name' => 'dus', 'factor' => 12, 'sort_order' => 1]);

    expect(posRejection(fn () => countEntry($count, $this->admin, $product, 1, ['unit_id' => $foreign->id]))->getMessage())->toContain('Satuan tidak cocok');

    $lookup = $this->counts->lookup($count, 'PAK-1');

    expect($lookup['kind'])->toBe('product')
        ->and($lookup['unit']->is($pack))->toBeTrue()
        ->and($lookup['item']->product_id)->toBe($product->id)
        ->and($this->counts->lookup($count, 'TIDAK-ADA')['kind'])->toBe('unknown');
});

test('unknown barcodes are kept for later without touching stock', function () {
    Product::factory()->create();
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);

    $this->counts->recordUnknown($count, $this->admin, '899000111', 2, 'Rak minuman');
    $row = $this->counts->recordUnknown($count, $this->admin, '899000111', 1);

    expect($count->unknownItems()->count())->toBe(1)
        ->and((float) $row->quantity)->toBe(3.0)
        ->and($row->note)->toBe('Rak minuman')
        ->and(StockMovement::query()->count())->toBe(0);
});
