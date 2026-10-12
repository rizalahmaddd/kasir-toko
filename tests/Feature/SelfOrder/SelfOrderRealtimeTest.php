<?php

use App\Events\KitchenTicketCreated;
use App\Events\SelfOrderChanged;
use App\Events\SelfOrderReady;
use App\Events\SelfOrderStatusUpdated;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Services\Pos\KitchenTicketService;
use App\Services\Pos\SelfOrderService;
use App\Services\Pos\ShiftService;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\OutletSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    Features::setEnabled(['business.order-type', 'business.self-order']);
    $this->cashier = actingAsRole('admin');
    $this->outlet = primaryOutlet();
    OutletSettings::put($this->outlet->id, 'pos.self_order_enabled', '1');
    app(ShiftService::class)->open($this->cashier, 0);

    $latte = Product::factory()->untracked()->create(['price' => 28000]);
    $this->order = app(SelfOrderService::class)->submit($this->outlet, DiningTable::factory()->create(['name' => '05']), null, [
        'client_uuid' => (string) Str::uuid(),
        'customer_name' => 'Rina',
        'items' => [['product_id' => $latte->id, 'quantity' => 1]],
    ]);
});

test('the customer hears about status changes on a public channel named after their session token', function () {
    $event = new SelfOrderStatusUpdated($this->order);

    expect($event->broadcastOn()[0]->name)->toBe('self-order.'.$this->order->session_token)
        ->and($event->broadcastAs())->toBe('self-order.updated')
        ->and($event->broadcastWith())->toBe(['status' => 'pending', 'payment_status' => 'unpaid']);
});

test('the cashier inbox and the ready alert go to the private outlet channel only', function () {
    $channel = 'private-'.CurrentOutlet::channelFor($this->order->tenant_id, $this->outlet->id);

    expect(collect((new SelfOrderChanged($this->order, true))->broadcastOn())->pluck('name')->all())->toBe([$channel])
        ->and((new SelfOrderChanged($this->order, true))->broadcastWith()['new'])->toBeTrue()
        ->and(collect((new SelfOrderReady($this->order))->broadcastOn())->pluck('name')->all())->toBe([$channel])
        ->and((new SelfOrderReady($this->order))->broadcastWith())->toBe(['id' => $this->order->id, 'label' => 'Meja 05', 'customer' => 'Rina']);
});

test('accepting, finishing, and cancelling each notify the customer and the outlet', function () {
    Event::fake([SelfOrderStatusUpdated::class, SelfOrderChanged::class, SelfOrderReady::class, KitchenTicketCreated::class]);
    $selfOrders = app(SelfOrderService::class);

    $selfOrders->accept($this->order, $this->cashier);
    Event::assertDispatched(SelfOrderStatusUpdated::class, fn ($event) => $event->order->status === SelfOrder::STATUS_IN_KITCHEN);
    Event::assertDispatched(KitchenTicketCreated::class, 1);
    Event::assertNotDispatched(SelfOrderReady::class);

    app(KitchenTicketService::class)->markDone(KitchenTicket::query()->sole());
    Event::assertDispatched(SelfOrderReady::class, fn ($event) => $event->order->is($this->order));

    app(KitchenTicketService::class)->reopen(KitchenTicket::query()->sole());
    $selfOrders->cancel($this->order, 'Pelanggan pulang');

    Event::assertDispatched(SelfOrderStatusUpdated::class, fn ($event) => $event->order->status === SelfOrder::STATUS_CANCELLED);
    // Tiket yang ditarik disiarkan ulang supaya Layar Dapur menghapusnya.
    Event::assertDispatched(KitchenTicketCreated::class, 2);
});

test('stale orders are expired by the scheduler every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'expire-stale-self-orders');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');

    $this->travel(20)->minutes();
    $event->run(app());

    expect($this->order->fresh()->status)->toBe(SelfOrder::STATUS_EXPIRED)
        ->and($this->order->fresh()->cancel_reason)->toBe('Tidak ditanggapi kasir sampai batas waktu');
});
