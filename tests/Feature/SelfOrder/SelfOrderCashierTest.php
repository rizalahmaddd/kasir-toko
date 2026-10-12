<?php

use App\Enums\StockMovementType;
use App\Livewire\Kitchen\KitchenBoard;
use App\Livewire\Pos\Cashier;
use App\Models\DiningTable;
use App\Models\HeldOrder;
use App\Models\KitchenTicket;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SelfOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pos\HeldOrderService;
use App\Services\Pos\SaleService;
use App\Services\Pos\SelfOrderService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Support\Features;
use App\Support\OutletSettings;
use App\Support\Qris;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/**
 * @param  list<array<string, mixed>>  $items
 */
function qrOrder(?DiningTable $table, array $items, string $method = 'cashier', ?string $device = null): SelfOrder
{
    return app(SelfOrderService::class)->submit(primaryOutlet(), $table, $device, [
        'client_uuid' => (string) Str::uuid(),
        'customer_name' => 'Rina',
        'payment_method' => $method,
        'items' => $items,
    ]);
}

/**
 * Baris checkout dari keranjang kasir (open bill atau Proses Bayar), seperti yang dikirim pos.js.
 *
 * @param  list<array<string, mixed>>  $cartItems
 * @return list<array<string, mixed>>
 */
function checkoutLines(array $cartItems): array
{
    return array_map(fn (array $line) => [
        'product_id' => $line['product_id'],
        'quantity' => $line['quantity'],
        'price' => $line['price'],
        'auto_discount' => $line['auto_discount'] ?? 0,
        'note' => $line['note'] ?? null,
        'modifiers' => $line['modifiers'] ?? [],
        'self_order_item_id' => $line['self_order_item_id'] ?? null,
    ], $cartItems);
}

/**
 * @param  array<string, mixed>  $payload
 */
function payAtCashier(User $cashier, array $payload): Sale
{
    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'payments' => [['method' => 'cash', 'amount' => 1_000_000]],
        ...$payload,
    ]);
}

function enableQris(): void
{
    $body = '00020101021126570011ID.DANA.WWW011893600915302259148102090225914810303UMI'
        .'51440014ID.CO.QRIS.WWW0215ID10200176114730303UMI5204581253033605802ID5914TOKO MAJU JAYA6006MALANG6105651456304';

    Setting::put('pos.qris_payload', $body.Qris::crc16($body));
}

beforeEach(function () {
    Features::setEnabled(['business.modifiers', 'business.order-type', 'business.self-order', 'business.components']);
    $this->cashier = actingAsRole('admin');
    $this->outlet = primaryOutlet();
    OutletSettings::put($this->outlet->id, 'pos.self_order_enabled', '1');
    app(ShiftService::class)->open($this->cashier, 0);
    enableQris();

    $this->table = DiningTable::factory()->create(['name' => '05']);
    $this->latte = Product::factory()->untracked()->create(['name' => 'Latte', 'price' => 28000]);
    $this->cake = Product::factory()->create(['name' => 'Cheesecake', 'price' => 20000, 'stock' => 0]);
    app(StockService::class)->adjust($this->cake, StockMovementType::StockIn, 3, $this->cashier);
    $this->selfOrders = app(SelfOrderService::class);
});

test('confirm flow, pay at cashier, dine-in: accepted into the table open bill and paid later', function () {
    $order = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 2]]);

    expect($this->selfOrders->nextAction($order))->toBe('accept')
        ->and(KitchenTicket::query()->count())->toBe(0);

    $this->selfOrders->accept($order, $this->cashier);
    $order->refresh();
    $held = HeldOrder::query()->sole();
    $ticket = KitchenTicket::query()->sole();

    expect($order->status)->toBe(SelfOrder::STATUS_IN_KITCHEN)
        ->and($order->held_order_id)->toBe($held->id)
        ->and($held->table_label)->toBe('05')
        ->and($ticket->self_order_id)->toBe($order->id)
        ->and($ticket->label)->toBe('Meja 05 · QR');

    $cart = app(HeldOrderService::class)->resume($this->cashier, $held->id);
    $sale = payAtCashier($this->cashier, ['items' => checkoutLines($cart['items']), 'order_type' => 'dine_in', 'table_label' => '05', 'kitchen_sent' => $cart['kitchenSent']]);
    $order->refresh();

    expect($sale->total)->toBe(56000)
        ->and($order->sale_id)->toBe($sale->id)
        ->and($order->payment_status)->toBe(SelfOrder::PAYMENT_PAID)
        ->and(KitchenTicket::query()->count())->toBe(1)
        ->and($ticket->fresh()->sale_id)->toBe($sale->id);
});

test('confirm flow, pay at cashier, pay-first orders: loaded to the cart and the ticket is issued at checkout', function (Closure $place) {
    $order = $place($this);

    expect($this->selfOrders->nextAction($order))->toBe('pay');

    $cart = $this->selfOrders->cartFor($order->load('items'));
    $sale = payAtCashier($this->cashier, ['items' => checkoutLines($cart['items']), 'self_order_ids' => [$order->id], 'order_type' => $cart['orderType'], 'table_label' => $cart['table'] ?: null]);
    $order->refresh();

    expect($order->sale_id)->toBe($sale->id)
        ->and($order->status)->toBe(SelfOrder::STATUS_IN_KITCHEN)
        ->and($order->payment_status)->toBe(SelfOrder::PAYMENT_PAID)
        ->and($sale->queue_number)->toBe($order->queue_number)
        ->and(HeldOrder::query()->count())->toBe(0)
        ->and(KitchenTicket::query()->sole()->self_order_id)->toBe($order->id);
})->with([
    'take away' => [fn ($test) => qrOrder(null, [['product_id' => $test->latte->id, 'quantity' => 1]])],
    'dine-in paid before' => [function ($test) {
        OutletSettings::put($test->outlet->id, 'pos.self_order_dine_in_payment', 'before');

        return qrOrder($test->table, [['product_id' => $test->latte->id, 'quantity' => 1]]);
    }],
]);

test('confirm flow, QRIS: verification creates the paid sale once', function () {
    $order = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 2]], 'qris');

    expect($order->payment_status)->toBe(SelfOrder::PAYMENT_PENDING_VERIFICATION)
        ->and($this->selfOrders->nextAction($order))->toBe('verify')
        ->and(KitchenTicket::query()->count())->toBe(0);

    $sale = $this->selfOrders->verifyPayment($order, $this->cashier);
    $again = $this->selfOrders->verifyPayment($order, $this->cashier);
    $payment = $sale->payments()->sole();

    expect($again->id)->toBe($sale->id)
        ->and(Sale::query()->count())->toBe(1)
        ->and($sale->client_uuid)->toBe($order->client_uuid)
        ->and($payment->method->value)->toBe('qris')
        ->and($payment->amount)->toBe(56000)
        ->and($sale->due_amount)->toBe(0)
        ->and($order->fresh()->payment_status)->toBe(SelfOrder::PAYMENT_PAID)
        ->and(KitchenTicket::query()->sole()->sale_id)->toBe($sale->id);
});

test('auto flow, pay at cashier: goes straight to the kitchen under the shift owner and is paid from the open bill', function (Closure $table, ?string $tableLabel) {
    OutletSettings::put($this->outlet->id, 'pos.self_order_kitchen_flow', 'auto');
    auth()->logout();

    $order = qrOrder($table($this), [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $held = HeldOrder::query()->sole();

    expect($order->status)->toBe(SelfOrder::STATUS_IN_KITCHEN)
        ->and($held->user_id)->toBe($this->cashier->id)
        ->and($held->self_order_id)->toBe($order->id)
        ->and($held->table_label)->toBe($tableLabel)
        ->and(KitchenTicket::query()->sole()->self_order_id)->toBe($order->id);

    // Kasir lain di outlet itu tetap melihat open bill-nya walau bukan pembuatnya dan tanpa meja.
    $colleague = actingAsRole('kasir');
    app(ShiftService::class)->open($colleague, 0);
    $cart = app(HeldOrderService::class)->resume($colleague, $held->id);

    $sale = payAtCashier($colleague, ['items' => checkoutLines($cart['items']), 'order_type' => $cart['orderType'], 'table_label' => $tableLabel, 'kitchen_sent' => $cart['kitchenSent']]);

    expect($order->fresh()->sale_id)->toBe($sale->id)
        ->and(KitchenTicket::query()->count())->toBe(1);
})->with([
    'dine-in' => [fn ($test) => $test->table, '05'],
    'take away' => [fn () => null, null],
]);

test('auto flow, QRIS: the ticket is issued at submit and verification does not issue another', function () {
    OutletSettings::put($this->outlet->id, 'pos.self_order_kitchen_flow', 'auto');

    $order = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1], ['product_id' => $this->cake->id, 'quantity' => 1]], 'qris');

    expect($order->status)->toBe(SelfOrder::STATUS_IN_KITCHEN)
        ->and($order->kitchen_sent)->not->toBeEmpty()
        ->and(HeldOrder::query()->count())->toBe(0)
        ->and(KitchenTicket::query()->count())->toBe(1);

    $sale = $this->selfOrders->verifyPayment($order, $this->cashier);

    expect(KitchenTicket::query()->count())->toBe(1)
        ->and(KitchenTicket::query()->sole()->sale_id)->toBe($sale->id)
        ->and($sale->total)->toBe(48000)
        ->and(outletStockQty($this->cake, $this->outlet->id))->toBe(2.0);
});

test('a rejected QRIS payment billed at the cashier joins the open bill without a second ticket', function () {
    OutletSettings::put($this->outlet->id, 'pos.self_order_kitchen_flow', 'auto');
    $order = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]], 'qris');

    $this->selfOrders->rejectPayment($order);
    expect($this->selfOrders->nextAction($order->refresh()))->toBe('decide');

    $this->selfOrders->billAtCashier($order, $this->cashier);
    $order->refresh();

    expect($order->payment_method)->toBe(SelfOrder::METHOD_CASHIER)
        ->and($order->payment_status)->toBe(SelfOrder::PAYMENT_UNPAID)
        ->and(HeldOrder::query()->sole()->id)->toBe($order->held_order_id)
        ->and(KitchenTicket::query()->count())->toBe(1);
});

test('the price is locked at submit: the sale keeps the snapshot and is flagged, plain lines are still rejected', function () {
    $order = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $this->latte->update(['price' => 35000]);

    $cart = $this->selfOrders->cartFor($order->load('items'));
    $plain = ['product_id' => $this->latte->id, 'quantity' => 1, 'price' => 28000];

    $stale = posRejection(fn () => payAtCashier($this->cashier, ['items' => [...checkoutLines($cart['items']), $plain], 'order_type' => 'take_away']));
    expect($stale->reason)->toBe('price_changed');

    $sale = payAtCashier($this->cashier, ['items' => [...checkoutLines($cart['items']), [...$plain, 'price' => 35000]], 'order_type' => 'take_away']);

    expect($sale->total)->toBe(63000)
        ->and($sale->items->pluck('price')->all())->toBe([28000, 35000])
        ->and($sale->flags)->toContain('self_order_price_snapshot');
});

test('a self-order line cannot be paid twice, inflated, or reused after cancellation', function () {
    $order = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $lines = checkoutLines($this->selfOrders->cartFor($order->load('items'))['items']);

    $inflated = posRejection(fn () => payAtCashier($this->cashier, ['items' => [[...$lines[0], 'quantity' => 2]], 'order_type' => 'take_away']));
    expect($inflated->reason)->toBe('self_order_invalid');

    payAtCashier($this->cashier, ['items' => $lines, 'order_type' => 'take_away']);
    expect(posRejection(fn () => payAtCashier($this->cashier, ['items' => $lines, 'order_type' => 'take_away']))->reason)->toBe('self_order_invalid');

    $cancelled = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $cancelledLines = checkoutLines($this->selfOrders->cartFor($cancelled->load('items'))['items']);
    $this->selfOrders->cancel($cancelled, 'Salah pesan');

    expect(posRejection(fn () => payAtCashier($this->cashier, ['items' => $cancelledLines, 'order_type' => 'take_away']))->reason)->toBe('self_order_invalid');
});

test('accepting is refused when the menu ran out after the customer ordered', function () {
    $order = qrOrder($this->table, [['product_id' => $this->cake->id, 'quantity' => 3]]);
    app(StockService::class)->adjust($this->cake, StockMovementType::StockOut, 2, $this->cashier);

    $rejection = posRejection(fn () => $this->selfOrders->accept($order, $this->cashier));

    expect($rejection->reason)->toBe('insufficient_stock')
        ->and($rejection->getMessage())->toBe('Maaf, Cheesecake baru saja habis.')
        ->and($order->fresh()->status)->toBe(SelfOrder::STATUS_PENDING)
        ->and(KitchenTicket::query()->count())->toBe(0);
});

test('two QR orders for one table share the open bill, and their lines never merge with cashier lines', function () {
    $first = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]], device: str_repeat('a', 40));
    $second = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 2]], device: str_repeat('b', 40));
    $this->selfOrders->accept($first, $this->cashier);
    $this->selfOrders->accept($second, $this->cashier);

    app(HeldOrderService::class)->hold($this->cashier, [
        'items' => [['product_id' => $this->latte->id, 'name' => 'Latte', 'unit' => 'pcs', 'price' => 28000, 'quantity' => 1, 'discount' => 0, 'modifiers' => []]],
        'orderType' => 'dine_in', 'table' => '05', 'total' => 28000,
    ]);

    $held = HeldOrder::query()->sole();
    $quantities = collect($held->cart['items'])->map(fn (array $line) => [$line['self_order_item_id'] ?? null, (float) $line['quantity']])->all();

    expect($quantities)->toBe([[$first->items->sole()->id, 1.0], [$second->items->sole()->id, 2.0], [null, 1.0]])
        ->and($held->total)->toBe(112000)
        // Tiap penambahan hanya mengirim selisihnya ke dapur.
        ->and(KitchenTicket::query()->orderBy('id')->get()->map(fn (KitchenTicket $ticket) => $ticket->items[0]['quantity'])->all())->toEqual([1, 2, 1]);
});

test('finishing the ticket from the web board or the API marks the order ready, reopening sends it back', function () {
    $order = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $this->selfOrders->accept($order, $this->cashier);
    $ticket = KitchenTicket::query()->sole();

    Livewire::test(KitchenBoard::class)->call('markDone', $ticket->id);
    expect($order->fresh()->status)->toBe(SelfOrder::STATUS_READY)
        ->and($order->fresh()->ready_at)->not->toBeNull();

    Sanctum::actingAs($this->cashier);
    $this->postJson("/api/v1/pos/kitchen-tickets/{$ticket->id}/reopen")->assertOk();
    expect($order->fresh()->status)->toBe(SelfOrder::STATUS_IN_KITCHEN);

    $this->postJson("/api/v1/pos/kitchen-tickets/{$ticket->id}/done")->assertOk()->assertJsonPath('data.from_self_order', true);
    expect($order->fresh()->status)->toBe(SelfOrder::STATUS_READY);

    $this->selfOrders->markServed($order);
    expect($order->fresh()->status)->toBe(SelfOrder::STATUS_SERVED);
});

test('cancelling an accepted order pulls its pending ticket and its lines out of the open bill', function () {
    $keep = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]], device: str_repeat('a', 40));
    $drop = qrOrder($this->table, [['product_id' => $this->cake->id, 'quantity' => 1]], device: str_repeat('b', 40));
    $this->selfOrders->accept($keep, $this->cashier);
    $this->selfOrders->accept($drop, $this->cashier);

    $this->selfOrders->cancel($drop, 'Pelanggan pindah meja');
    $held = HeldOrder::query()->sole();

    expect($drop->fresh()->status)->toBe(SelfOrder::STATUS_CANCELLED)
        ->and(KitchenTicket::query()->where('self_order_id', $drop->id)->sole()->status)->toBe(KitchenTicket::STATUS_CANCELLED)
        ->and(KitchenTicket::query()->where('self_order_id', $keep->id)->sole()->status)->toBe(KitchenTicket::STATUS_PENDING)
        ->and(collect($held->cart['items'])->pluck('product_id')->all())->toBe([$this->latte->id])
        ->and($held->total)->toBe(28000);

    Livewire::test(KitchenBoard::class)->assertDontSee('Cheesecake');
});

test('QR orders and cashier sales share one daily queue', function () {
    $first = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $walkIn = payAtCashier($this->cashier, ['items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'price' => 28000]], 'order_type' => 'take_away']);
    $second = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]], device: str_repeat('c', 40));

    expect([$first->queue_number, $walkIn->queue_number, $second->queue_number])->toBe([1, 2, 3]);
});

test('closing the shift serves paid orders that are ready and warns about the rest', function () {
    $paid = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]], 'qris');
    $this->selfOrders->verifyPayment($paid, $this->cashier);
    Livewire::test(KitchenBoard::class)->call('markDone', KitchenTicket::query()->sole()->id);
    $waiting = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]]);

    $shift = $this->cashier->openShift();
    expect($this->selfOrders->openWhenLastShiftCloses($shift)->pluck('id')->all())->toBe([$paid->id, $waiting->id]);

    $this->get(route('shifts.show', $shift))->assertOk()->assertSee('pesanan QR yang belum selesai');

    app(ShiftService::class)->close($shift, 0, $this->cashier);

    expect($paid->fresh()->status)->toBe(SelfOrder::STATUS_SERVED)
        ->and($waiting->fresh()->status)->toBe(SelfOrder::STATUS_PENDING);
});

test('the cashier inbox lists open orders with their next action and runs them', function () {
    $dineIn = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $qris = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]], 'qris');

    $component = Livewire::test(Cashier::class);

    expect(collect($component->instance()->selfOrders(app(SelfOrderService::class)))->pluck('action', 'id')->all())
        ->toBe([$dineIn->id => 'accept', $qris->id => 'verify'])
        ->and($component->instance()->config()['selfOrder'])->toBe(['enabled' => true, 'count' => 2]);

    $accepted = $component->instance()->selfOrderAction($dineIn->id, 'accept');
    $verified = $component->instance()->selfOrderAction($qris->id, 'verify');
    $missing = $component->instance()->selfOrderAction(999999, 'accept');

    // Kartu pesanan yang sudah diterima menunjuk ke open bill tempat ia dibayar.
    $listed = collect($component->instance()->selfOrders(app(SelfOrderService::class)))->keyBy('id');

    expect($listed[$dineIn->id]['held_order_id'])->toBe(HeldOrder::query()->sole()->id)
        ->and($listed->has($qris->id))->toBeTrue()
        ->and($listed[$qris->id]['held_order_id'])->toBeNull()
        ->and($accepted['ok'])->toBeTrue()
        ->and($accepted['ticket_urls'])->toHaveCount(1)
        ->and($verified['ok'])->toBeTrue()
        ->and($verified['receipt_url'])->toContain('/kasir/struk/')
        ->and($missing['ok'])->toBeFalse()
        ->and($dineIn->fresh()->status)->toBe(SelfOrder::STATUS_IN_KITCHEN)
        ->and($qris->fresh()->payment_status)->toBe(SelfOrder::PAYMENT_PAID);
});

test('the inbox is closed to shops without the feature', function () {
    $order = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    Features::setEnabled(['business.order-type']);

    $component = Livewire::test(Cashier::class);

    expect($component->instance()->selfOrders(app(SelfOrderService::class)))->toBe([])
        ->and($component->instance()->selfOrderAction($order->id, 'accept')['ok'])->toBeFalse();
});

test('the mobile API lists the inbox and runs every cashier action', function () {
    $dineIn = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $takeAway = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $qris = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]], 'qris');
    $unpaidQris = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]], 'qris', str_repeat('d', 40));
    Sanctum::actingAs($this->cashier);

    $this->getJson('/api/v1/pos/config')->assertOk()->assertJsonPath('data.self_order_enabled', true);

    $this->getJson('/api/v1/pos/self-orders')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.next_action', 'accept')
        ->assertJsonPath('data.1.next_action', 'pay')
        ->assertJsonPath('data.2.next_action', 'verify')
        ->assertJsonMissingPath('data.0.session_token');

    $this->postJson("/api/v1/pos/self-orders/{$dineIn->id}/accept")->assertOk()->assertJsonPath('data.status', 'in_kitchen');
    $this->postJson("/api/v1/pos/self-orders/{$dineIn->id}/accept")->assertStatus(422)->assertJsonPath('reason', 'self_order_state');

    $cart = $this->getJson("/api/v1/pos/self-orders/{$takeAway->id}/cart")->assertOk()->json('data');
    $this->postJson('/api/v1/pos/checkout', [
        'client_uuid' => (string) Str::uuid(),
        'items' => checkoutLines($cart['items']),
        'self_order_ids' => [$takeAway->id],
        'order_type' => 'take_away',
        'payments' => [['method' => 'cash', 'amount' => 28000]],
    ])->assertCreated();
    expect($takeAway->fresh()->payment_status)->toBe(SelfOrder::PAYMENT_PAID);

    $this->postJson("/api/v1/pos/self-orders/{$qris->id}/verify-payment")->assertCreated()->assertJsonPath('data.total', 28000);
    $this->postJson("/api/v1/pos/self-orders/{$qris->id}/verify-payment")->assertOk();
    expect(Sale::query()->count())->toBe(2);

    $this->postJson("/api/v1/pos/self-orders/{$unpaidQris->id}/reject", ['target' => 'payment'])->assertOk()->assertJsonPath('data.next_action', 'decide');
    $this->postJson("/api/v1/pos/self-orders/{$unpaidQris->id}/bill-at-cashier")->assertOk()->assertJsonPath('data.next_action', 'pay');
    $this->postJson("/api/v1/pos/self-orders/{$unpaidQris->id}/reject", ['reason' => 'Pelanggan pergi'])->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->postJson("/api/v1/pos/self-orders/{$dineIn->id}/served")->assertOk()->assertJsonPath('data.status', 'served');
});

test('the mobile API is closed to other shops, free plans, and users who cannot sell', function () {
    $order = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]]);

    apiActingAs('staff');
    $this->getJson('/api/v1/pos/self-orders')->assertForbidden();

    $outsider = User::factory()->create(['tenant_id' => provisionedShop()->id]);
    Sanctum::actingAs($outsider);
    $this->postJson("/api/v1/pos/self-orders/{$order->id}/accept")->assertStatus(403);

    $this->cashier->tenant->update(['plan' => 'free']);
    Sanctum::actingAs($this->cashier);
    $this->getJson('/api/v1/pos/self-orders')->assertForbidden()->assertJsonPath('upgrade_required', true);

    expect($order->fresh()->status)->toBe(SelfOrder::STATUS_PENDING);
});

test('a dine-in order already delivered to the table is still paid from its open bill', function () {
    $order = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $this->selfOrders->accept($order, $this->cashier);
    Livewire::test(KitchenBoard::class)->call('markDone', KitchenTicket::query()->sole()->id);
    $this->selfOrders->markServed($order);

    $cart = app(HeldOrderService::class)->resume($this->cashier, HeldOrder::query()->sole()->id);
    $sale = payAtCashier($this->cashier, ['items' => checkoutLines($cart['items']), 'order_type' => 'dine_in', 'table_label' => '05', 'kitchen_sent' => $cart['kitchenSent']]);
    $order->refresh();

    expect($order->status)->toBe(SelfOrder::STATUS_SERVED)
        ->and($order->payment_status)->toBe(SelfOrder::PAYMENT_PAID)
        ->and($order->sale_id)->toBe($sale->id)
        ->and(KitchenTicket::query()->count())->toBe(1);
});

test('an open bill resumed and held again stays linked, so cancelling still removes the order lines', function () {
    $order = qrOrder($this->table, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $this->selfOrders->accept($order, $this->cashier);

    $cart = app(HeldOrderService::class)->resume($this->cashier, HeldOrder::query()->sole()->id);
    $cart['items'][] = ['product_id' => $this->cake->id, 'name' => 'Cheesecake', 'unit' => 'pcs', 'price' => 20000, 'quantity' => 1, 'discount' => 0, 'modifiers' => []];
    $held = app(HeldOrderService::class)->hold($this->cashier, $cart)['order'];

    expect($order->fresh()->held_order_id)->toBe($held->id);

    $this->selfOrders->cancel($order, 'Salah meja');

    expect(collect($held->fresh()->cart['items'])->pluck('product_id')->all())->toBe([$this->cake->id]);
});

test('a pay-at-cashier order uses the current tax rate, a claimed QRIS payment keeps the rate it was paid at', function () {
    $cashierPaid = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]]);
    $qrisPaid = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]], 'qris', str_repeat('e', 40));
    Setting::put('pos.tax_enabled', '1');
    Setting::put('pos.tax_rate', '10');

    $cart = $this->selfOrders->cartFor($cashierPaid->load('items'));
    $sale = payAtCashier($this->cashier, ['items' => checkoutLines($cart['items']), 'self_order_ids' => [$cashierPaid->id], 'order_type' => 'take_away', 'expected_total' => 30800]);
    $verified = $this->selfOrders->verifyPayment($qrisPaid, $this->cashier);

    expect($sale->total)->toBe(30800)
        ->and($verified->total)->toBe(28000)
        ->and($verified->due_amount)->toBe(0);
});

test('long customer notes never break checkout or the open bill', function () {
    $notes = str_repeat('Tolong jangan pedas ya. ', 8);
    $submit = fn (?DiningTable $table, string $device) => app(SelfOrderService::class)->submit(primaryOutlet(), $table, $device, [
        'client_uuid' => (string) Str::uuid(), 'customer_name' => str_repeat('Rina ', 19), 'notes' => $notes,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ]);

    $cart = $this->selfOrders->cartFor($submit(null, str_repeat('f', 40))->load('items'));
    expect(mb_strlen($cart['note']))->toBeLessThanOrEqual(255);
    payAtCashier($this->cashier, ['items' => checkoutLines($cart['items']), 'order_type' => 'take_away', 'note' => $cart['note']]);

    foreach (['g', 'h', 'i'] as $device) {
        $this->selfOrders->accept($submit($this->table, str_repeat($device, 40)), $this->cashier);
    }

    expect(mb_strlen(HeldOrder::query()->sole()->cart['note']))->toBeLessThanOrEqual(255);
});

test('an order moved to pay-at-cashier after a rejected QRIS payment gets a fresh time limit', function () {
    $order = qrOrder(null, [['product_id' => $this->latte->id, 'quantity' => 1]], 'qris');

    $this->travel(40)->minutes();
    $this->selfOrders->rejectPayment($order);
    $this->selfOrders->billAtCashier($order, $this->cashier);

    $this->travel(5)->minutes();
    expect($this->selfOrders->expireStale())->toBe(0)
        ->and($order->fresh()->status)->toBe(SelfOrder::STATUS_PENDING);

    $this->travel(15)->minutes();
    expect($this->selfOrders->expireStale())->toBe(1);
});
