<?php

use App\Enums\StockCountReason;
use App\Enums\StockCountScope;
use App\Enums\StockMovementType;
use App\Livewire\Reports\StockVarianceReport;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\User;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockService;
use App\Support\CurrentOutlet;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->main = primaryOutlet();
    $this->drinks = Category::factory()->create(['name' => 'Minuman']);
    $this->tea = Product::factory()->create(['name' => 'Teh Botol', 'category_id' => $this->drinks->id, 'cost_price' => 3000]);
    $this->soap = Product::factory()->create(['name' => 'Sabun Mandi', 'cost_price' => 5000]);
    setOutletStock($this->tea, $this->main->id, 10);
    setOutletStock($this->soap, $this->main->id, 10);
});

function postedCount(array $counts, array $reasons = []): StockCount
{
    $service = app(StockCountService::class);
    $count = $service->start(test()->admin, primaryOutlet()->id, StockCountScope::All);

    foreach ($counts as $productId => $quantity) {
        $service->recordEntry($count, test()->admin, ['client_uuid' => (string) Str::uuid(), 'product_id' => $productId, 'quantity' => $quantity]);
    }

    $service->submit($count, test()->admin);
    $service->preview($count->fresh());

    foreach ($reasons as $productId => $reason) {
        $service->setReason($count->items()->where('product_id', $productId)->sole(), test()->admin, $reason);
    }

    return $service->post($count->fresh(), test()->admin);
}

test('totals per period, reason, and category include quick opname', function () {
    postedCount([$this->tea->id => 8, $this->soap->id => 11], [$this->tea->id => StockCountReason::Lost]);
    app(StockService::class)->adjust($this->soap, StockMovementType::Opname, 9, $this->admin);

    Livewire::test(StockVarianceReport::class)
        ->assertSee('Teh Botol')
        ->assertSee('Opname cepat')
        ->assertViewHas('totals', fn (array $totals) => $totals['rows'] === 3 && $totals['shortage_value'] === 16000 && $totals['surplus_value'] === 5000 && $totals['net_value'] === -11000)
        ->set('reason', StockCountReason::Lost->value)
        ->assertViewHas('totals', fn (array $totals) => $totals['rows'] === 1 && $totals['shortage_value'] === 6000)
        ->set('reason', '')
        ->set('categoryId', (string) $this->drinks->id)
        ->assertViewHas('totals', fn (array $totals) => $totals['rows'] === 1)
        ->set('categoryId', '')
        ->set('from', now()->addDay()->toDateString())
        ->set('to', now()->addDays(2)->toDateString())
        ->assertViewHas('totals', fn (array $totals) => $totals['rows'] === 0);
});

test('a late correction lowers the reported variance', function () {
    $count = postedCount([$this->tea->id => 8]);
    $item = $count->items()->where('product_id', $this->tea->id)->sole();
    $item->update(['variance_qty' => -1]);

    Livewire::test(StockVarianceReport::class)
        ->assertViewHas('totals', fn (array $totals) => $totals['shortage_qty'] === 1.0 && $totals['shortage_value'] === 3000);
});

test('the free plan sees the paywall and sales report access alone is not enough', function () {
    $this->tenant->update(['plan' => 'free']);

    Livewire::test(StockVarianceReport::class)->assertSee('Khusus Pro')->assertViewHas('totals', null);

    $cashier = User::factory()->create();
    $cashier->givePermissionTo(Permission::findOrCreate('reports.sales.view', 'web'));
    $this->actingAs($cashier);

    Livewire::test(StockVarianceReport::class)->assertForbidden();
});

test('branch users only see their outlet', function () {
    $branch = makeOutlet(['code' => 'CB1']);
    setOutletStock($this->tea, $branch->id, 5);
    $service = app(StockCountService::class);
    $count = $service->start($this->admin, $branch->id, StockCountScope::All);
    $service->recordEntry($count, $this->admin, ['client_uuid' => (string) Str::uuid(), 'product_id' => $this->tea->id, 'quantity' => 1]);
    $service->submit($count, $this->admin);
    $service->post($count->fresh(), $this->admin);
    postedCount([$this->tea->id => 9]);

    $branchUser = actingAsRole('staff');
    $branchUser->givePermissionTo('reports.stock.view');
    $branchUser->forceFill(['all_outlets' => false])->save();
    $branchUser->outlets()->attach($branch->id, ['tenant_id' => $this->tenant->id]);
    $this->actingAs($branchUser->fresh());
    app(CurrentOutlet::class)->flush();

    Livewire::test(StockVarianceReport::class)
        ->set('outletFilter', 'all')
        ->assertViewHas('totals', fn (array $totals) => $totals['rows'] === 1 && $totals['shortage_qty'] === 4.0);
});
