<?php

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Events\SubscriptionBonusGranted;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Notifications\CashShiftClosedNotification;
use App\Notifications\CustomerReceivableNotification;
use App\Notifications\ProductStockAlertNotification;
use App\Notifications\SaleVoidedNotification;
use App\Notifications\SubscriptionBonusNotification;
use App\Notifications\SubscriptionExpiringNotification;
use App\Notifications\SubscriptionPaidNotification;
use App\Notifications\WelcomeTenantNotification;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Services\TenantProvisioner;
use App\Services\TenantSubscriptionManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->useDefaultTenant();
});

test('welcome notification is sent to owner upon tenant provisioning', function () {
    Notification::fake();

    $provisioner = app(TenantProvisioner::class);
    $result = $provisioner->provision('Toko Notifikasi Baru', [
        'name' => 'Owner Notif',
        'username' => 'ownernotif',
        'email' => 'owner@notif.test',
        'password' => 'password123',
    ]);

    Notification::assertSentTo(
        $result['owner'],
        WelcomeTenantNotification::class,
        fn (WelcomeTenantNotification $n) => $n->tenant->id === $result['tenant']->id
    );
});

test('subscription paid notification is sent when sumopod payment completed', function () {
    Notification::fake();

    $tenant = $this->tenant;
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole(seededRole('superadmin'));

    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $tenant->id,
        'invoice_number' => 'INV-NOTIF-001',
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 149000,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    $payload = [
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay-uuid-test',
            'order_id' => 'INV-NOTIF-001',
            'amount' => 149000,
            'status' => 'completed',
            'payment_method' => 'qris',
            'paid_at' => now()->toIso8601String(),
        ],
    ];

    $response = $this->postJson(route('webhooks.sumopod'), $payload);
    $response->assertOk();

    Notification::assertSentTo(
        $owner,
        SubscriptionPaidNotification::class,
        fn (SubscriptionPaidNotification $n) => $n->invoice->id === $invoice->id
    );
});

test('subscription bonus notification is sent when platform admin extends tenant', function () {
    Notification::fake();

    $tenant = $this->tenant;
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole(seededRole('superadmin'));

    $manager = app(TenantSubscriptionManager::class);
    $manager->extend($tenant, 15, null, 'Bonus Loyalitas');

    Notification::assertSentTo(
        $owner,
        SubscriptionBonusNotification::class,
        fn (SubscriptionBonusNotification $n) => $n->addedDays === 15 && $n->note === 'Bonus Loyalitas'
    );
});

test('updating tenant name only does not dispatch subscription bonus', function () {
    Event::fake([SubscriptionBonusGranted::class]);

    $tenant = $this->tenant;
    $manager = app(TenantSubscriptionManager::class);

    $manager->update($tenant, [
        'name' => 'Nama Toko Diubah Saja',
        'plan' => $tenant->plan,
        'status' => $tenant->status,
        'access_ends_at' => $tenant->accessEndsAt(),
    ]);

    Event::assertNotDispatched(SubscriptionBonusGranted::class);
});

test('subscription expiring notification formats array with message icon color and url', function () {
    $tenant = $this->tenant;
    $notification = new SubscriptionExpiringNotification($tenant, 'd3');
    $data = $notification->toArray(User::factory()->make());

    expect($data)->toHaveKeys(['icon', 'color', 'message', 'url', 'tenant_id', 'stage'])
        ->and($data['icon'])->toBe('clock')
        ->and($data['color'])->toBe('amber')
        ->and($data['message'])->toContain('3 hari lagi')
        ->and($data['url'])->toBe(route('settings.subscription'));
});

test('product stock alert notification is sent when stock drops to or below min_stock and throttles duplicate alerts', function () {
    Notification::fake();

    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole(seededRole('admin'));

    $product = Product::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Kopi Robusta',
        'track_stock' => true,
        'stock' => 10,
        'min_stock' => 5,
    ]);

    $stockService = app(StockService::class);
    // 1st deduct: 10 -> 4 (below min_stock 5, notification sent)
    $stockService->move($product, StockMovementType::StockOut, -6, $admin, null, 'Uji stok keluar');

    Notification::assertSentToTimes(
        $admin,
        ProductStockAlertNotification::class,
        1
    );

    // 2nd deduct immediately: 4 -> 3 (still low stock, but already alerted, so it should be throttled)
    $stockService->move($product, StockMovementType::StockOut, -1, $admin, null, 'Uji stok kedua');

    // Count should still be 1 (throttled!)
    Notification::assertSentToTimes(
        $admin,
        ProductStockAlertNotification::class,
        1
    );

    // 3rd deduct to 0: 3 -> 0 (transition to out of stock! Should alert again for OOS)
    $stockService->move($product, StockMovementType::StockOut, -3, $admin, null, 'Uji habis');

    Notification::assertSentToTimes(
        $admin,
        ProductStockAlertNotification::class,
        2
    );
});

test('cash shift closed notification is sent to managers with cash discrepancy info', function () {
    Notification::fake();

    $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $manager->assignRole(seededRole('admin'));

    $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $cashier->assignRole(seededRole('kasir'));

    $shift = CashShift::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $cashier->id,
        'number' => 'SHIFT-001',
        'opened_at' => now()->subHours(8),
        'opening_cash' => 100000,
    ]);

    $shiftService = app(ShiftService::class);
    // Expected cash is 100,000, but counted is 90,000 (diff -10,000)
    $shiftService->close($shift, 90000, $cashier, 'Selisih uang kembalian');

    Notification::assertSentTo(
        $manager,
        CashShiftClosedNotification::class,
        fn (CashShiftClosedNotification $n) => $n->shift->id === $shift->id && (int) $n->shift->cash_difference === -10000
    );
});

test('sale voided notification is sent to managers when a sale is cancelled', function () {
    Notification::fake();

    $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $manager->assignRole(seededRole('admin'));

    $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $cashier->assignRole(seededRole('kasir'));

    $sale = Sale::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $cashier->id,
        'number' => 'TRX-VOID-01',
        'total' => 50000,
        'status' => SaleStatus::Completed,
    ]);

    $saleService = app(SaleService::class);
    $saleService->void($sale, $cashier, 'Salah input pesanan');

    Notification::assertSentTo(
        $manager,
        SaleVoidedNotification::class,
        fn (SaleVoidedNotification $n) => $n->sale->id === $sale->id && $n->reason === 'Salah input pesanan'
    );
});

test('customer receivable notification is sent when a sale has due amount or is collected', function () {
    Notification::fake();

    $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $manager->assignRole(seededRole('admin'));

    $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $cashier->assignRole(seededRole('kasir'));

    $sale = Sale::factory()->create([
        'tenant_id' => $this->tenant->id,
        'number' => 'TRX-BON-01',
        'total' => 100000,
        'paid_amount' => 0,
        'due_amount' => 100000,
        'status' => SaleStatus::Completed,
    ]);

    $saleService = app(SaleService::class);
    $saleService->payReceivable($sale, $cashier, 50000, PaymentMethod::Transfer);

    Notification::assertSentTo(
        $manager,
        CustomerReceivableNotification::class,
        fn (CustomerReceivableNotification $n) => $n->sale->id === $sale->id && $n->amount === 50000 && $n->type === 'collected'
    );
});
