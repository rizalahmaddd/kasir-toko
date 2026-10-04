<?php

use App\Enums\SaleStatus;
use App\Livewire\Reports\SalesReport;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    actingAsAdmin();
});

test('the daily chart sums completed sales per day', function () {
    Sale::factory()->create(['total' => 10000, 'sold_at' => '2026-10-14 23:30:00']);
    Sale::factory()->create(['total' => 25000, 'sold_at' => '2026-10-15 08:00:00']);
    Sale::factory()->create(['total' => 5000, 'sold_at' => '2026-10-15 09:00:00']);
    Sale::factory()->create(['total' => 99000, 'sold_at' => '2026-10-15 09:30:00', 'status' => SaleStatus::Voided]);

    Livewire::withQueryParams(['from' => '2026-10-13', 'to' => '2026-10-15'])
        ->test(SalesReport::class)
        ->assertViewHas('chart', fn (array $chart) => array_column($chart, 'value') === [0, 10000, 30000])
        ->call('export')
        ->assertFileDownloaded();
});

test('ranges longer than a month are charted per month', function () {
    Sale::factory()->create(['total' => 10000, 'sold_at' => '2026-08-03 12:00:00']);
    Sale::factory()->create(['total' => 20000, 'sold_at' => '2026-08-31 12:00:00']);
    Sale::factory()->create(['total' => 7000, 'sold_at' => '2026-10-01 12:00:00']);

    Livewire::withQueryParams(['from' => '2026-08-01', 'to' => '2026-10-15'])
        ->test(SalesReport::class)
        ->assertViewHas('chart', fn (array $chart) => array_column($chart, 'value') === [30000, 0, 7000]);
});

test('can switch tabs and view transactions and modal detail', function () {
    $customer = Customer::factory()->create(['name' => 'Pak Budi']);
    $sale = Sale::factory()->create([
        'total' => 50000,
        'sold_at' => '2026-10-15 11:00:00',
        'customer_id' => $customer->id,
    ]);
    SaleItem::create([
        'sale_id' => $sale->id,
        'product_name' => 'Beras Organik',
        'quantity' => 2,
        'unit' => 'kg',
        'price' => 25000,
        'cost_price' => 20000,
        'discount_amount' => 0,
        'total' => 50000,
    ]);

    Livewire::withQueryParams(['from' => '2026-10-15', 'to' => '2026-10-15'])
        ->test(SalesReport::class)
        ->set('activeTab', 'transactions')
        ->assertSee('Pak Budi')
        ->assertSee($sale->number)
        ->call('openSaleModal', $sale->id)
        ->assertDispatched('open-modal', 'sale-detail-modal')
        ->assertSee('Beras Organik')
        ->call('closeSaleModal')
        ->assertDispatched('close-modal', 'sale-detail-modal');
});

test('identifies peak hours accurately', function () {
    Sale::factory()->count(3)->create(['total' => 15000, 'sold_at' => '2026-10-15 13:15:00']);
    Sale::factory()->create(['total' => 10000, 'sold_at' => '2026-10-15 09:00:00']);

    Livewire::withQueryParams(['from' => '2026-10-15', 'to' => '2026-10-15'])
        ->test(SalesReport::class)
        ->assertViewHas('peakHour', fn (?array $peak) => $peak !== null && $peak['hour'] === '13' && $peak['count'] === 3);
});

test('can export all three report types', function () {
    $sale = Sale::factory()->create(['total' => 20000, 'sold_at' => '2026-10-15 10:00:00']);
    SaleItem::create([
        'sale_id' => $sale->id,
        'product_name' => 'Minyak Goreng',
        'quantity' => 1,
        'unit' => 'liter',
        'price' => 20000,
        'cost_price' => 16000,
        'discount_amount' => 0,
        'total' => 20000,
    ]);

    Livewire::withQueryParams(['from' => '2026-10-15', 'to' => '2026-10-15'])
        ->test(SalesReport::class)
        ->call('exportDaily')
        ->assertFileDownloaded()
        ->call('exportTransactions')
        ->assertFileDownloaded()
        ->call('exportProducts')
        ->assertFileDownloaded();
});
