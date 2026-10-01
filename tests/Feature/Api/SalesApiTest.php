<?php

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function apiSale(User $cashier, Product $product, array $payments, array $extra = []): Sale
{
    app(ShiftService::class)->open($cashier, 0);

    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => $product->price]],
        'payments' => $payments,
        ...$extra,
    ]);
}

beforeEach(function () {
    $this->product = Product::factory()->create(['price' => 20000, 'stock' => 5]);
});

it('shows cashiers only their own sales', function () {
    $other = apiActingAs('kasir');
    $othersSale = apiSale($other, $this->product, [['method' => 'cash', 'amount' => 20000]]);
    $me = apiActingAs('kasir');
    $mine = apiSale($me, $this->product, [['method' => 'cash', 'amount' => 20000]]);

    $this->getJson('/api/v1/sales')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('meta.summary.total', 20000);

    $this->getJson("/api/v1/sales/{$othersSale->id}")->assertForbidden();
    $this->getJson("/api/v1/sales/{$mine->id}")->assertOk()->assertJsonPath('data.abilities.void', false);
});

it('voids a sale and returns its stock', function () {
    $admin = apiActingAs('admin');
    $sale = apiSale($admin, $this->product, [['method' => 'cash', 'amount' => 20000]]);

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Salah input'])
        ->assertOk()
        ->assertJsonPath('data.status', SaleStatus::Voided->value);

    expect($this->product->fresh()->stock)->toBe('5.000');

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Lagi'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Transaksi ini sudah dibatalkan.');
});

it('keeps cashiers without the void permission from voiding', function () {
    $cashier = apiActingAs('kasir');
    $sale = apiSale($cashier, $this->product, [['method' => 'cash', 'amount' => 20000]]);

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'x'])->assertForbidden();
});

it('records a partial receivable payment', function () {
    $cashier = apiActingAs('kasir');
    $customer = Customer::factory()->create();
    $sale = apiSale($cashier, $this->product, [['method' => 'cash', 'amount' => 5000]], ['customer_id' => $customer->id]);

    $this->getJson('/api/v1/receivables')->assertJsonPath('meta.total_due', 15000);

    $this->postJson("/api/v1/receivables/{$sale->id}/payments", ['amount' => 10000, 'method' => 'cash'])
        ->assertOk()
        ->assertJsonPath('data.due_amount', 5000);

    $this->postJson("/api/v1/receivables/{$sale->id}/payments", ['amount' => 9000, 'method' => 'cash'])
        ->assertUnprocessable();
});

it('asks for a note when the counted cash does not match', function () {
    $cashier = apiActingAs('kasir');
    $shift = app(ShiftService::class)->open($cashier, 50000);

    $this->postJson("/api/v1/shifts/{$shift->id}/close", ['counted_cash' => 40000])
        ->assertJsonValidationErrors(['closing_note' => 'Ada selisih uang. Tulis penjelasannya supaya bisa dicek pemilik.']);

    $this->postJson("/api/v1/shifts/{$shift->id}/close", ['counted_cash' => 40000, 'closing_note' => 'Kembalian salah'])
        ->assertOk()
        ->assertJsonPath('data.cash_difference', -10000)
        ->assertJsonPath('data.abilities.close', false);
});

it('keeps cashiers out of shifts that belong to someone else', function () {
    $owner = apiActingAs('kasir');
    $shift = app(ShiftService::class)->open($owner, 0);
    Sanctum::actingAs(apiActingAs('kasir'));

    $this->getJson("/api/v1/shifts/{$shift->id}")->assertForbidden();
    $this->postJson("/api/v1/shifts/{$shift->id}/close", ['counted_cash' => 0])->assertForbidden();
});

it('returns the receipt as text and as printable HTML', function () {
    $cashier = apiActingAs('kasir');
    $sale = apiSale($cashier, $this->product, [['method' => 'cash', 'amount' => 20000]]);

    expect($this->getJson("/api/v1/sales/{$sale->id}/receipt")->assertOk()->json('data.text'))->toContain($sale->number);

    $this->get("/api/v1/print/receipt/{$sale->id}")->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertSee($sale->number);
});
