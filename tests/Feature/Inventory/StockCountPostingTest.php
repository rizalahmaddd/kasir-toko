<?php

use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Events\StockThresholdReached;
use App\Jobs\PostStockCount;
use App\Livewire\Settings\PosSettingsPage;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\StockCountOpenReminderNotification;
use App\Notifications\StockCountPostedNotification;
use App\Notifications\StockCountSubmittedNotification;
use App\Services\Pos\StockCountPoster;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockCountVariance;
use App\Services\Pos\StockService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->main = primaryOutlet();
    $this->counts = app(StockCountService::class);
});

function postingEntry(StockCount $count, Product $product, float $quantity): void
{
    app(StockCountService::class)->recordEntry($count, test()->admin, ['client_uuid' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => $quantity]);
}

test('a big count is posted by a queued job', function () {
    Queue::fake();
    $products = Product::factory()->count(StockCountPoster::CHUNK + 1)->create(['stock' => 0]);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    postingEntry($count, $products->first(), 4);
    $this->counts->submit($count, $this->admin);

    $result = $this->counts->post($count->fresh(), $this->admin);

    expect($result->status)->toBe(StockCountStatus::Posting);
    Queue::assertPushed(PostStockCount::class, fn (PostStockCount $job) => $job->stockCountId === $count->id);

    (new PostStockCount($count->id, $this->admin->id))->handle(app(StockCountPoster::class));

    expect($count->fresh()->status)->toBe(StockCountStatus::Posted)
        ->and(outletStockQty($products->first(), $this->main->id))->toBe(4.0);
});

test('resuming an interrupted posting skips rows that were already processed', function () {
    $first = Product::factory()->create(['stock' => 0]);
    $second = Product::factory()->create(['stock' => 0]);
    setOutletStock($first, $this->main->id, 5);
    setOutletStock($second, $this->main->id, 5);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    postingEntry($count, $first, 3);
    postingEntry($count, $second, 2);
    $this->counts->submit($count, $this->admin);
    $this->counts->preview($count->fresh());

    $count->update(['status' => StockCountStatus::Posting, 'posted_by' => $this->admin->id]);
    $item = $count->items()->where('product_id', $first->id)->sole();
    $done = app(StockService::class)->move($first, StockMovementType::Opname, -2, $this->admin, $count, $count->number, null, $this->main->id);
    $item->update(['stock_movement_id' => $done->id]);

    $this->counts->resume($count->fresh(), $this->admin);

    expect(outletStockQty($first, $this->main->id))->toBe(3.0)
        ->and(outletStockQty($second, $this->main->id))->toBe(2.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Opname)->count())->toBe(2)
        ->and($count->fresh()->status)->toBe(StockCountStatus::Posted)
        ->and(posRejection(fn () => $this->counts->resume($count->fresh(), $this->admin))->reason)->toBe('stock_count_closed');
});

test('posting sends one summary instead of a low stock alert per product', function () {
    Notification::fake();
    Event::fake([StockThresholdReached::class]);
    $owner = User::factory()->create();
    $owner->assignRole(seededRole('admin'));
    $products = Product::factory()->count(3)->create(['stock' => 0, 'min_stock' => 5, 'cost_price' => 1000]);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);

    foreach ($products as $product) {
        setOutletStock($product, $this->main->id, 10);
    }

    foreach ($products as $product) {
        postingEntry($count, $product, 1);
    }

    $this->counts->submit($count, $this->admin);
    $posted = $this->counts->post($count->fresh(), $this->admin);

    Event::assertNotDispatched(StockThresholdReached::class);
    Notification::assertSentTo($owner, StockCountPostedNotification::class, fn ($notification) => str_contains($notification->toArray($owner)['message'], '3 barang kini menipis'));
    Notification::assertNotSentTo($this->admin, StockCountPostedNotification::class);

    expect($posted->summary['low_stock'])->toBe(3);
});

test('a counter finishing tells the managers without moving the document', function () {
    Notification::fake();
    Product::factory()->create();
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    $counter = actingAsRole('staff');

    $result = $this->counts->submit($count, $counter);

    expect($result->status)->toBe(StockCountStatus::Counting);
    Notification::assertSentTo($this->admin, StockCountSubmittedNotification::class);
});

test('managers are reminded about counts left open for more than three days', function () {
    Notification::fake();
    Product::factory()->create();
    $owner = User::factory()->create();
    $owner->assignRole(seededRole('admin'));
    $old = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [Product::query()->value('id')]);
    $old->update(['started_at' => now()->subDays(4)]);
    $fresh = Product::factory()->create();
    $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$fresh->id]);

    $this->artisan('stock:remind-open-counts')->assertSuccessful();

    Notification::assertSentTo($owner, StockCountOpenReminderNotification::class, fn ($notification) => $notification->count->is($old));
    Notification::assertSentToTimes($owner, StockCountOpenReminderNotification::class, 1);
});

test('the owner settings drive the reason, recount, and large shortage alert thresholds', function () {
    Notification::fake();
    Livewire::test(PosSettingsPage::class)
        ->set('opnameReasonAbove', '0')
        ->set('opnameRecountPercent', '50')
        ->set('opnameAlertAbove', '5000')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(StockCountService::ALERT_ABOVE_KEY))->toBe('5000')
        ->and(Setting::get(StockCountVariance::RECOUNT_PERCENT_KEY))->toBe('50');

    $product = Product::factory()->create(['cost_price' => 1000]);
    setOutletStock($product, $this->main->id, 10);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$product->id]);
    postingEntry($count, $product, 2);
    $this->counts->submit($count, $this->admin);
    $this->counts->post($count->fresh(), $this->admin);

    Notification::assertSentTo($this->admin, StockCountPostedNotification::class, fn ($notification) => $notification->alert && $notification->toArray($this->admin)['color'] === 'rose');
});
