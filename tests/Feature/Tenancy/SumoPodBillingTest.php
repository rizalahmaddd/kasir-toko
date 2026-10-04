<?php

use App\Livewire\Settings\SubscriptionPage;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\SaasPlans;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->useDefaultTenant();
});

test('store owner can view the subscription page', function () {
    actingAsSuperAdmin();

    $this->get(route('settings.subscription'))
        ->assertOk()
        ->assertSee('Paket & Langganan')
        ->assertSee('Riwayat Tagihan Langganan');
});

test('cashier cannot view the subscription page', function () {
    actingAsRole('kasir');

    $this->get(route('settings.subscription'))
        ->assertForbidden();
});

test('checkout creates a subscription invoice and redirects to sumopod payment url', function () {
    actingAsSuperAdmin();

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay-uuid-12345',
            'order_id' => 'dummy',
            'amount' => 149000,
            'fee' => 750,
            'net_amount' => 148250,
            'payment_link_url' => 'https://pay.sumopod.com/pay/pay-uuid-12345',
            'status' => 'pending',
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ], 200),
    ]);

    Livewire::test(SubscriptionPage::class)
        ->call('checkout', 'pro')
        ->assertRedirect('https://pay.sumopod.com/pay/pay-uuid-12345');

    $invoice = SubscriptionInvoice::latest('id')->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->plan)->toBe('pro')
        ->and($invoice->status)->toBe(SubscriptionInvoice::STATUS_PENDING)
        ->and($invoice->payment_gateway_ref)->toBe('pay-uuid-12345')
        ->and($invoice->payment_url)->toBe('https://pay.sumopod.com/pay/pay-uuid-12345');
});

test('sumopod webhook processes payment.completed and extends subscription', function () {
    $tenant = $this->tenant;
    $initialEndsAt = now()->addDays(5);
    $tenant->update([
        'plan' => 'basic',
        'subscription_ends_at' => $initialEndsAt,
    ]);

    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $tenant->id,
        'invoice_number' => 'INV-TEST-001',
        'plan' => 'basic',
        'period_months' => 1,
        'amount' => 79000,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    $webhookPayload = [
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay-uuid-999',
            'order_id' => 'INV-TEST-001',
            'amount' => 79000,
            'fee' => 750,
            'net_amount' => 78250,
            'status' => 'completed',
            'payment_method' => 'qris',
            'paid_at' => now()->toIso8601String(),
            'settled_at' => now()->addDays(2)->toIso8601String(),
            'completed_at' => now()->addDays(2)->toIso8601String(),
        ],
    ];

    $response = $this->postJson(route('webhooks.sumopod'), $webhookPayload);
    $response->assertOk()
        ->assertJson(['message' => 'Payment processed and subscription extended']);

    $invoice->refresh();
    expect($invoice->status)->toBe(SubscriptionInvoice::STATUS_PAID)
        ->and($invoice->paid_at)->not->toBeNull();

    $tenant->refresh();
    // 5 hari + 30 hari = 35 hari
    expect(now()->diffInDays($tenant->accessEndsAt()))->toBeGreaterThanOrEqual(34);
});

test('sumopod webhook is idempotent and does not extend subscription twice', function () {
    $tenant = $this->tenant;
    $initialEndsAt = now()->addDays(10)->endOfDay();
    $tenant->update(['plan' => 'basic', 'subscription_ends_at' => $initialEndsAt]);

    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $tenant->id,
        'invoice_number' => 'INV-TEST-002',
        'plan' => 'basic',
        'period_months' => 1,
        'amount' => 79000,
        'status' => SubscriptionInvoice::STATUS_PAID,
        'payment_method' => 'qris',
        'paid_at' => now(),
    ]);

    $webhookPayload = [
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay-uuid-999',
            'order_id' => 'INV-TEST-002',
            'amount' => 79000,
        ],
    ];

    $response = $this->postJson(route('webhooks.sumopod'), $webhookPayload);
    $response->assertOk()
        ->assertJson(['message' => 'Invoice already paid']);

    $tenant->refresh();
    expect($tenant->accessEndsAt()->toDateString())->toBe($initialEndsAt->toDateString());
});

test('sumopod webhook handles test event smoothly', function () {
    $response = $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.test',
        'data' => [],
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Webhook test event acknowledged successfully']);
});

test('successive subscription orders accumulate active period continuously', function () {
    $tenant = $this->tenant;
    $tenant->update([
        'plan' => 'free',
        'trial_ends_at' => null,
        'subscription_ends_at' => null,
    ]);

    // Order 1: 1 Tahun (12 bulan)
    $inv1 = SubscriptionInvoice::create([
        'tenant_id' => $tenant->id,
        'invoice_number' => 'INV-ACC-YEAR-1',
        'plan' => 'pro',
        'period_months' => 12,
        'amount' => 199000,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => ['order_id' => 'INV-ACC-YEAR-1', 'amount' => 199000],
    ])->assertOk();

    $tenant->refresh();
    $firstEndsAt = $tenant->subscription_ends_at->copy();
    expect($tenant->plan)->toBe('pro')
        ->and($firstEndsAt->isSameDay(now()->addMonths(12)))->toBeTrue();

    // Order 2: Order lagi 1 Tahun (12 bulan) -> harus bertambah 1 tahun lagi dari masa aktif sebelumnya (total 24 bulan)
    $inv2 = SubscriptionInvoice::create([
        'tenant_id' => $tenant->id,
        'invoice_number' => 'INV-ACC-YEAR-2',
        'plan' => 'pro',
        'period_months' => 12,
        'amount' => 199000,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => ['order_id' => 'INV-ACC-YEAR-2', 'amount' => 199000],
    ])->assertOk();

    $tenant->refresh();
    $secondEndsAt = $tenant->subscription_ends_at->copy();
    expect($secondEndsAt->isSameDay($firstEndsAt->copy()->addMonths(12)))->toBeTrue()
        ->and($secondEndsAt->isSameDay(now()->addMonths(24)))->toBeTrue();

    // Order 3: Order 1 Bulan (1 bulan) -> akumulasi bertambah 1 bulan lagi (total 25 bulan)
    $inv3 = SubscriptionInvoice::create([
        'tenant_id' => $tenant->id,
        'invoice_number' => 'INV-ACC-MONTH-1',
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 20000,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => ['order_id' => 'INV-ACC-MONTH-1', 'amount' => 20000],
    ])->assertOk();

    $tenant->refresh();
    $thirdEndsAt = $tenant->subscription_ends_at->copy();
    expect($thirdEndsAt->isSameDay($secondEndsAt->copy()->addMonths(1)))->toBeTrue()
        ->and($thirdEndsAt->isSameDay(now()->addMonths(25)))->toBeTrue();
});

test('subscription page displays discounts and applies them to checkout invoices', function () {
    actingAsSuperAdmin();

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay-uuid-discount-test',
            'order_id' => 'dummy',
            'amount' => 15000,
            'fee' => 750,
            'net_amount' => 14250,
            'payment_link_url' => 'https://pay.sumopod.com/pay/pay-uuid-discount-test',
            'status' => 'pending',
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ], 200),
    ]);

    SaasPlans::save([
        'trial' => ['label' => 'Uji Coba', 'price' => null, 'max_users' => null, 'max_products' => null],
        'free' => ['label' => 'Gratis', 'price' => 0, 'max_users' => null, 'max_products' => null],
        'pro' => [
            'label' => 'Pro',
            'price' => 25000,
            'monthly_discount' => 10000, // jadi 15.000 / bln
            'yearly_price' => 250000,
            'yearly_discount' => 70000,  // jadi 180.000 / thn
            'max_users' => null,
            'max_products' => null,
        ],
    ]);

    // Test tampilan halaman langganan
    Livewire::test(SubscriptionPage::class)
        ->assertSee('Rp25.000') // harga coret
        ->assertSee('Rp15.000') // harga setelah diskon
        ->assertSee('Hemat Rp10.000')
        ->set('billingCycle', 'yearly')
        ->assertSee('Rp250.000') // harga coret
        ->assertSee('Rp180.000') // harga setelah diskon
        ->assertSee('Hemat Rp70.000');

    // Test checkout bulanan dengan diskon (25.000 - 10.000 = 15.000)
    Livewire::test(SubscriptionPage::class)
        ->set('billingCycle', 'monthly')
        ->call('checkout', 'pro')
        ->assertRedirect('https://pay.sumopod.com/pay/pay-uuid-discount-test');

    $monthlyInvoice = SubscriptionInvoice::latest('id')->first();
    expect($monthlyInvoice->amount)->toBe(15000)
        ->and($monthlyInvoice->period_months)->toBe(1);

    // Batalkan tagihan bulanan terlebih dahulu sebelum checkout tahunan
    $monthlyInvoice->update(['status' => SubscriptionInvoice::STATUS_CANCELLED]);

    // Test checkout tahunan dengan diskon (250.000 - 70.000 = 180.000)
    Livewire::test(SubscriptionPage::class)
        ->set('billingCycle', 'yearly')
        ->call('checkout', 'pro')
        ->assertRedirect('https://pay.sumopod.com/pay/pay-uuid-discount-test');

    $yearlyInvoice = SubscriptionInvoice::latest('id')->first();
    expect($yearlyInvoice->amount)->toBe(180000)
        ->and($yearlyInvoice->period_months)->toBe(12);
});

test('store owner can checkout lifetime plan and webhook grants permanent access', function () {
    actingAsSuperAdmin();

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay-uuid-lifetime-test',
            'order_id' => 'dummy',
            'amount' => 499000,
            'fee' => 750,
            'net_amount' => 498250,
            'payment_link_url' => 'https://pay.sumopod.com/pay/pay-uuid-lifetime-test',
            'status' => 'pending',
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ], 200),
    ]);

    // Checkout Lifetime
    Livewire::test(SubscriptionPage::class)
        ->assertSee('Lifetime (Permanen)')
        ->call('checkout', 'lifetime')
        ->assertRedirect('https://pay.sumopod.com/pay/pay-uuid-lifetime-test');

    $invoice = SubscriptionInvoice::latest('id')->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->plan)->toBe('lifetime')
        ->and($invoice->period_months)->toBe(0)
        ->and($invoice->amount)->toBe(499000);

    // Webhook Payment Completed
    $response = $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay-uuid-lifetime-test',
            'order_id' => $invoice->invoice_number,
            'amount' => 499000,
        ],
    ]);
    $response->assertOk();

    $this->tenant->refresh();
    expect($this->tenant->plan)->toBe('lifetime')
        ->and($this->tenant->subscription_ends_at)->toBeNull()
        ->and($this->tenant->isPro())->toBeTrue()
        ->and($this->tenant->isLifetime())->toBeTrue()
        ->and($this->tenant->accessEndsAt())->toBeNull()
        ->and($this->tenant->hasExpired())->toBeFalse()
        ->and($this->tenant->blockedReason())->toBeNull();
});

test('active pro store owner can see extend pro button and period accumulates', function () {
    actingAsSuperAdmin();

    // Tenant sedang aktif Pro selama 15 hari ke depan
    $currentExpiry = now()->addDays(15)->endOfDay();
    $this->tenant->update([
        'plan' => 'pro',
        'trial_ends_at' => null,
        'subscription_ends_at' => $currentExpiry,
    ]);

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay-uuid-pro-extend',
            'order_id' => 'dummy',
            'amount' => 20000,
            'fee' => 750,
            'net_amount' => 19250,
            'payment_link_url' => 'https://pay.sumopod.com/pay/pay-uuid-pro-extend',
            'status' => 'pending',
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ], 200),
    ]);

    // Buka halaman langganan: pemilik Pro harus melihat tombol perpanjang Pro dan upgrade lifetime
    Livewire::test(SubscriptionPage::class)
        ->assertSee('Perpanjang Pro (QRIS)')
        ->assertSee('Upgrade ke Lifetime (QRIS)')
        ->assertDontSee('Paket Aktif Permanen')
        ->call('checkout', 'pro')
        ->assertRedirect('https://pay.sumopod.com/pay/pay-uuid-pro-extend');

    $invoice = SubscriptionInvoice::latest('id')->first();
    expect($invoice->plan)->toBe('pro')
        ->and($invoice->period_months)->toBe(1)
        ->and($invoice->amount)->toBe(20000);

    // Webhook selesai pembayaran -> akumulasi dari 15 hari tersisa + 1 bulan
    $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay-uuid-pro-extend',
            'order_id' => $invoice->invoice_number,
            'amount' => 20000,
        ],
    ])->assertOk();

    $this->tenant->refresh();
    expect($this->tenant->plan)->toBe('pro')
        ->and($this->tenant->subscription_ends_at->isSameDay($currentExpiry->copy()->addMonths(1)))->toBeTrue();
});

test('lifetime tenant sees permanent active status and buttons are properly disabled', function () {
    actingAsSuperAdmin();

    $this->tenant->update([
        'plan' => 'lifetime',
        'trial_ends_at' => null,
        'subscription_ends_at' => null,
    ]);

    Livewire::test(SubscriptionPage::class)
        ->assertSee('Paket Aktif Permanen')
        ->assertSee('Termasuk di Paket Lifetime')
        ->assertDontSee('Perpanjang Pro (QRIS)');
});

test('trial store owner sees upgrade button instead of extend and trial days accumulate on webhook payment', function () {
    actingAsSuperAdmin();

    // Tenant sedang dalam trial, sisa 3 hari
    $trialExpiry = now()->addDays(3);
    $this->tenant->update([
        'plan' => 'trial',
        'trial_ends_at' => $trialExpiry,
        'subscription_ends_at' => null,
    ]);

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay-uuid-trial-upgrade',
            'order_id' => 'dummy',
            'amount' => 20000,
            'fee' => 750,
            'net_amount' => 19250,
            'payment_link_url' => 'https://pay.sumopod.com/pay/pay-uuid-trial-upgrade',
            'status' => 'pending',
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ], 200),
    ]);

    // Buka halaman langganan: pemilik trial harus melihat Upgrade ke Pro (bukan Perpanjang Pro),
    // dan melihat keterangan bahwa sisa trial (3 hari) otomatis ditambahkan
    Livewire::test(SubscriptionPage::class)
        ->assertSee('Upgrade ke Pro (QRIS)')
        ->assertDontSee('Perpanjang Pro (QRIS)')
        ->assertSee('Sisa trial (3 hari) otomatis ditambahkan')
        ->call('checkout', 'pro')
        ->assertRedirect('https://pay.sumopod.com/pay/pay-uuid-trial-upgrade');

    $invoice = SubscriptionInvoice::latest('id')->first();
    expect($invoice->plan)->toBe('pro')
        ->and($invoice->period_months)->toBe(1);

    // Webhook selesai pembayaran -> akumulasi dari 3 hari sisa trial + 1 bulan
    $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => [
            'payment_id' => 'pay-uuid-trial-upgrade',
            'order_id' => $invoice->invoice_number,
            'amount' => 20000,
        ],
    ])->assertOk();

    $this->tenant->refresh();
    expect($this->tenant->plan)->toBe('pro')
        ->and($this->tenant->subscription_ends_at->isSameDay($trialExpiry->copy()->addMonths(1)))->toBeTrue();
});

test('tenant can cancel a pending subscription invoice via modal confirmation', function () {
    actingAsSuperAdmin();

    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    Livewire::test(SubscriptionPage::class)
        ->assertSee($invoice->invoice_number)
        ->assertSee('Batalkan')
        ->call('confirmCancel', $invoice->id)
        ->assertDispatched('open-modal', 'confirm-cancel-invoice-modal')
        ->call('cancelConfirmedInvoice')
        ->assertDispatched('close-modal', 'confirm-cancel-invoice-modal')
        ->assertDispatched('notify');

    $invoice->refresh();
    expect($invoice->status)->toBe(SubscriptionInvoice::STATUS_CANCELLED)
        ->and($invoice->isCancelled())->toBeTrue();
});

test('user cannot checkout duplicate active pending invoice for the same plan until cancelled', function () {
    actingAsSuperAdmin();

    $pendingInvoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    // Saat checkout paket Pro yang sama, sistem menolak pembuatan invoice baru dan membuka modal peringatan
    Livewire::test(SubscriptionPage::class)
        ->call('checkout', 'pro')
        ->assertDispatched('open-modal', 'pending-invoice-exists-modal');

    // Jumlah invoice Pro tetap 1 (tidak diduplikasi)
    expect(SubscriptionInvoice::query()->where('tenant_id', $this->tenant->id)->where('plan', 'pro')->count())->toBe(1);

    // Batalkan invoice lama dari modal
    Livewire::test(SubscriptionPage::class)
        ->set('pendingInvoiceId', $pendingInvoice->id)
        ->call('cancelInvoiceFromPendingModal')
        ->assertDispatched('close-modal', 'pending-invoice-exists-modal')
        ->assertDispatched('notify');

    $pendingInvoice->refresh();
    expect($pendingInvoice->status)->toBe(SubscriptionInvoice::STATUS_CANCELLED);
});

test('pending subscription invoice older than 3x24 hours is automatically cancelled', function () {
    actingAsSuperAdmin();

    // Buat invoice pending 4 hari yang lalu (lebih dari 72 jam / 3x24 jam)
    $staleInvoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
        'created_at' => now()->subDays(4),
        'expires_at' => now()->subDay(),
    ]);

    // Tagihan baru dibuat 1 jam yang lalu (belum lewat 72 jam)
    $recentInvoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'lifetime',
        'period_months' => 0,
        'amount' => 399000,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
        'created_at' => now()->subHour(),
        'expires_at' => now()->addHours(71),
    ]);

    // Jalankan auto cancel stale
    $cancelledCount = SubscriptionInvoice::autoCancelStale(72);
    expect($cancelledCount)->toBe(1);

    $staleInvoice->refresh();
    $recentInvoice->refresh();

    expect($staleInvoice->status)->toBe(SubscriptionInvoice::STATUS_CANCELLED)
        ->and($staleInvoice->isCancelled())->toBeTrue()
        ->and($recentInvoice->status)->toBe(SubscriptionInvoice::STATUS_PENDING);

    // Saat membuka halaman langganan, invoice yang expired muncul dengan status 'Dibatalkan'
    Livewire::test(SubscriptionPage::class)
        ->assertSee($staleInvoice->invoice_number)
        ->assertSee('Dibatalkan');
});

test('tenant can pay existing pending invoice directly via payInvoice when link is still valid', function () {
    actingAsSuperAdmin();

    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
        'payment_url' => 'https://pay.sumopod.com/pay/valid-active-link',
        'expires_at' => now()->addHours(24),
    ]);

    Livewire::test(SubscriptionPage::class)
        ->call('payInvoice', $invoice->id)
        ->assertRedirect('https://pay.sumopod.com/pay/valid-active-link');
});

test('tenant can pay existing pending invoice and regenerate link via payInvoice when link is expired', function () {
    actingAsSuperAdmin();

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
        'payment_url' => 'https://pay.sumopod.com/pay/expired-link',
        'expires_at' => now()->subHour(), // Sudah kedaluwarsa
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay-uuid-regenerated',
            'order_id' => $invoice->invoice_number,
            'amount' => 14900,
            'fee' => 750,
            'net_amount' => 14150,
            'payment_link_url' => 'https://pay.sumopod.com/pay/pay-uuid-regenerated',
            'status' => 'pending',
            'expires_at' => now()->addHours(72)->toIso8601String(),
        ], 200),
    ]);

    Livewire::test(SubscriptionPage::class)
        ->call('payInvoice', $invoice->id)
        ->assertRedirect('https://pay.sumopod.com/pay/pay-uuid-regenerated');

    $invoice->refresh();
    expect($invoice->payment_url)->toBe('https://pay.sumopod.com/pay/pay-uuid-regenerated');
});

test('payInvoice ignores invoice if already paid', function () {
    actingAsSuperAdmin();

    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PAID,
        'payment_method' => 'qris',
        'payment_url' => 'https://pay.sumopod.com/pay/paid-link',
        'paid_at' => now(),
    ]);

    Livewire::test(SubscriptionPage::class)
        ->call('payInvoice', $invoice->id)
        ->assertNoRedirect();
});

test('payInvoice handles gateway exception and opens error modal', function () {
    actingAsSuperAdmin();

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
        'payment_url' => null, // Butuh generate baru
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'message' => 'Internal Gateway Error',
        ], 500),
    ]);

    Livewire::test(SubscriptionPage::class)
        ->call('payInvoice', $invoice->id)
        ->assertDispatched('open-modal', 'checkout-error-modal')
        ->assertSee('Gagal memperbarui tautan pembayaran');
});

test('tenant can cancel pending invoice and proceed with new plan checkout from modal', function () {
    actingAsSuperAdmin();

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    $oldInvoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'payment_id' => 'pay-uuid-proceed-new',
            'order_id' => 'dummy',
            'amount' => 14900,
            'fee' => 750,
            'net_amount' => 14150,
            'payment_link_url' => 'https://pay.sumopod.com/pay/pay-uuid-proceed-new',
            'status' => 'pending',
            'expires_at' => now()->addHours(72)->toIso8601String(),
        ], 200),
    ]);

    Livewire::test(SubscriptionPage::class)
        ->set('pendingInvoiceId', $oldInvoice->id)
        ->set('attemptedPlanKey', 'pro')
        ->call('cancelPendingInvoiceAndProceed')
        ->assertDispatched('close-modal', 'pending-invoice-exists-modal')
        ->assertRedirect('https://pay.sumopod.com/pay/pay-uuid-proceed-new');

    $oldInvoice->refresh();
    expect($oldInvoice->status)->toBe(SubscriptionInvoice::STATUS_CANCELLED);

    $newInvoice = SubscriptionInvoice::latest('id')->first();
    expect($newInvoice->id)->not->toBe($oldInvoice->id)
        ->and($newInvoice->status)->toBe(SubscriptionInvoice::STATUS_PENDING)
        ->and($newInvoice->payment_url)->toBe('https://pay.sumopod.com/pay/pay-uuid-proceed-new');
});

test('checkout displays error modal when payment gateway is not configured', function () {
    actingAsSuperAdmin();

    config([
        'services.sumopod.api_key' => null,
    ]);

    Livewire::test(SubscriptionPage::class)
        ->call('checkout', 'pro')
        ->assertDispatched('open-modal', 'checkout-error-modal')
        ->assertSee('sedang dalam pemeliharaan');

    expect(SubscriptionInvoice::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('checkout handles payment gateway exception and marks invoice as failed', function () {
    actingAsSuperAdmin();

    config([
        'services.sumopod.api_key' => 'test-api-key',
        'services.sumopod.api_url' => 'https://api-pay.sumopod.com/api/v1/payments',
    ]);

    Http::fake([
        'https://api-pay.sumopod.com/api/v1/payments' => Http::response([
            'message' => 'Service Unavailable',
        ], 503),
    ]);

    Livewire::test(SubscriptionPage::class)
        ->call('checkout', 'pro')
        ->assertDispatched('open-modal', 'checkout-error-modal')
        ->assertSee('Terjadi kendala saat menghubungkan ke gateway pembayaran');

    $invoice = SubscriptionInvoice::latest('id')->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe(SubscriptionInvoice::STATUS_FAILED);
});

test('sumopod webhook handles payment.expired and marks invoice as expired', function () {
    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => 'INV-EXPIRED-001',
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    $response = $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.expired',
        'data' => [
            'order_id' => 'INV-EXPIRED-001',
            'expired_at' => now()->toIso8601String(),
        ],
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Invoice marked as expired']);

    $invoice->refresh();
    expect($invoice->status)->toBe(SubscriptionInvoice::STATUS_EXPIRED);
});

test('sumopod webhook handles payment.failed and marks invoice as failed', function () {
    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => 'INV-FAILED-001',
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    $response = $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.failed',
        'data' => [
            'order_id' => 'INV-FAILED-001',
            'reason' => 'Payment session cancelled or timed out',
        ],
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Invoice marked as failed']);

    $invoice->refresh();
    expect($invoice->status)->toBe(SubscriptionInvoice::STATUS_FAILED);
});

test('sumopod webhook validates order_id presence and unknown order handling', function () {
    // Missing order_id -> 400
    $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => [],
    ])->assertStatus(400)
        ->assertJson(['message' => 'Missing order_id']);

    // Unknown order_id -> 200 with Invoice not found
    $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => [
            'order_id' => 'NON-EXISTENT-INV',
        ],
    ])->assertOk()
        ->assertJson(['message' => 'Invoice not found']);
});

test('sumopod webhook rejects invalid signature in production environment', function () {
    app()->detectEnvironment(fn () => 'production');

    config([
        'services.sumopod.webhook_secret' => 'whsec_dummy_secret_1234567890',
    ]);

    $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.test',
        'data' => [],
    ])->assertStatus(401)
        ->assertJson(['message' => 'Invalid webhook signature']);
});

test('sumopod webhook accepts valid webhook token header in production environment', function () {
    app()->detectEnvironment(fn () => 'production');

    $secret = 'whsec_my_secret_token_12345';
    config([
        'services.sumopod.webhook_secret' => $secret,
    ]);

    $this->withHeaders([
        'x-webhook-token' => $secret,
    ])->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.test',
        'data' => [],
    ])->assertOk()
        ->assertJson(['message' => 'Webhook test event acknowledged successfully']);
});

test('saas check-expirations command automatically cancels stale pending invoices', function () {
    $stale = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
        'created_at' => now()->subDays(5),
        'expires_at' => now()->subDays(2),
    ]);

    $this->artisan('saas:check-expirations')
        ->assertSuccessful();

    $stale->refresh();
    expect($stale->status)->toBe(SubscriptionInvoice::STATUS_CANCELLED);
});

test('payInvoice blocks cross-tenant invoice access to prevent IDOR', function () {
    actingAsSuperAdmin();

    $otherTenant = Tenant::factory()->create(['name' => 'Toko Sebelah']);
    $otherInvoice = SubscriptionInvoice::create([
        'tenant_id' => $otherTenant->id,
        'invoice_number' => SubscriptionInvoice::generateNumber(),
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
        'payment_url' => 'https://pay.sumopod.com/pay/other-tenant-link',
    ]);

    // Livewire payInvoice harus melempar 404 (ModelNotFoundException) jika mencoba mengakses invoice milik tenant lain
    expect(function () use ($otherInvoice) {
        Livewire::test(SubscriptionPage::class)
            ->call('payInvoice', $otherInvoice->id);
    })->toThrow(ModelNotFoundException::class);
});

test('sumopod webhook rejects underpayment attempts to prevent fraud', function () {
    $invoice = SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'invoice_number' => 'INV-UNDERPAY-001',
        'plan' => 'pro',
        'period_months' => 1,
        'amount' => 14900,
        'status' => SubscriptionInvoice::STATUS_PENDING,
        'payment_method' => 'qris',
    ]);

    // Percobaan manipulasi nominal bayar (misal bayar Rp 1 dari seharusnya Rp 14.900)
    $response = $this->postJson(route('webhooks.sumopod'), [
        'event_type' => 'payment.completed',
        'data' => [
            'order_id' => 'INV-UNDERPAY-001',
            'amount' => 1, // Kurang bayar!
            'paid_at' => now()->toIso8601String(),
        ],
    ]);

    $response->assertStatus(400)
        ->assertJson(['message' => 'Paid amount does not match invoice amount']);

    $invoice->refresh();
    expect($invoice->status)->toBe(SubscriptionInvoice::STATUS_PENDING)
        ->and($invoice->isPaid())->toBeFalse();
});
