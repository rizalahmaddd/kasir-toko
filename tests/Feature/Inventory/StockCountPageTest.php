<?php

use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Http\Middleware\IdentifyOutlet;
use App\Livewire\Dashboard;
use App\Livewire\Inventory\StockCounts;
use App\Livewire\Inventory\StockCountShow;
use App\Livewire\Inventory\StockIndex;
use App\Models\HeldOrder;
use App\Models\Product;
use App\Models\StockCount;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockCountSpreadsheet;
use App\Services\Pos\StockCountVariance;
use App\Support\CurrentOutlet;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->main = primaryOutlet();
    $this->product = Product::factory()->create(['name' => 'Indomie Goreng', 'sku' => 'IDM-1', 'barcode' => '8990001', 'cost_price' => 3000]);
    setOutletStock($this->product, $this->main->id, 10);
});

test('the list page starts a count and opens it', function () {
    Livewire::test(StockCounts::class)
        ->assertSee('Mulai Opname')
        ->call('openStart')
        ->set('scope', StockCountScope::Products->value)
        ->assertSet('holdAdjustments', false)
        ->set('productSearch', 'IDM-1')
        ->call('pickFirstMatch')
        ->assertSet('pickedProducts.0.id', $this->product->id)
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirect(route('inventory.opname.show', StockCount::query()->sole()));

    expect(StockCount::query()->sole()->items()->pluck('product_id')->all())->toBe([$this->product->id]);
});

test('scanning, reviewing, and posting from the document page', function () {
    $count = app(StockCountService::class)->start($this->admin, $this->main->id, StockCountScope::All);

    $page = Livewire::test(StockCountShow::class, ['stockCount' => $count])
        ->assertSee($count->number)
        ->assertSee('Indomie Goreng')
        ->set('code', '8990001')->call('scan')
        ->set('code', '8990001')->call('scan')
        ->assertSee('Total 2 pcs');

    expect((float) $count->items()->value('counted_qty'))->toBe(2.0);

    $page->set('code', 'TIDAK-ADA')->call('scan')
        ->assertDispatched('open-modal', 'count-unknown')
        ->call('saveUnknown');

    expect($count->unknownItems()->value('barcode'))->toBe('TIDAK-ADA');

    $item = $count->items()->sole();
    $page->call('openEntry', $item->id)->set('entryLines.base', '6')->call('saveEntry')->assertHasNoErrors();

    $page->call('submit')
        ->assertSet('view', 'review')
        ->assertSee('Selesaikan')
        ->call('setReason', $item->id, 'lost')
        ->call('confirmPost')
        ->assertDispatched('open-modal', 'count-post')
        ->call('post')
        ->assertSee('Buat opname koreksi');

    expect($count->fresh()->status)->toBe(StockCountStatus::Posted)
        ->and(outletStockQty($this->product, $this->main->id))->toBe(8.0);

    $this->get(route('inventory.opname.print', $count))->assertOk()->assertSee('Berita Acara Stok Opname')->assertSee('Hilang/dicuri');
    $this->get(route('inventory.opname.sheet', $count))->assertOk()->assertSee('Indomie Goreng');
});

test('a blind counter does not see system stock', function () {
    $count = app(StockCountService::class)->start($this->admin, $this->main->id, StockCountScope::All);
    actingAsRole('staff');

    Livewire::test(StockCountShow::class, ['stockCount' => $count])
        ->assertDontSee('Stok sistem')
        ->assertSee('Selesai menghitung');

    $this->get(route('inventory.opname.sheet', $count))->assertOk()->assertDontSee('Stok sistem');
});

test('a counter cannot open the review step or start a count', function () {
    $count = app(StockCountService::class)->start($this->admin, $this->main->id, StockCountScope::All);
    actingAsRole('staff');

    Livewire::test(StockCountShow::class, ['stockCount' => $count])->call('showView', 'review')->assertSet('view', 'count');
    Livewire::test(StockCounts::class)->assertDontSee('Mulai Opname')->call('openStart')->assertForbidden();
});

test('counts can be imported from the excel template without doubling on re-import', function () {
    $count = app(StockCountService::class)->start($this->admin, $this->main->id, StockCountScope::All);
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([StockCountSpreadsheet::HEADERS, ['IDM-1', '', 'Indomie', 'pcs', '', '', '7'], ['NOPE', '', 'Asing', '', '', '', '3']]);
    $path = tempnam(sys_get_temp_dir(), 'opn').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $page = Livewire::test(StockCountShow::class, ['stockCount' => $count])
        ->call('openImport')
        ->set('importFile', UploadedFile::fake()->createWithContent('hitung.xlsx', file_get_contents($path)))
        ->assertSet('importRows.0.status', 'ok')
        ->assertSet('importRows.1.message', 'Barang tidak ditemukan.')
        ->call('importNow');

    $page->call('openImport')
        ->set('importFile', UploadedFile::fake()->createWithContent('hitung.xlsx', file_get_contents($path)))
        ->call('importNow');

    expect((float) $count->items()->value('counted_qty'))->toBe(7.0);
});

test('the stock page shows which products are being counted and links opname movements', function () {
    $count = app(StockCountService::class)->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);

    Livewire::test(StockIndex::class)
        ->assertSeeText("Opname {$count->number} sedang berjalan")
        ->assertSee('Sedang dihitung · '.$count->number);
});

test('the dashboard lists running counts with their progress', function () {
    $count = app(StockCountService::class)->start($this->admin, $this->main->id, StockCountScope::All);

    Livewire::test(Dashboard::class)->assertSee('Stok opname berjalan')->assertSee($count->number)->assertSee('0%');
});

test('a count ten times the system stock needs a second press and held bills flag the row', function () {
    $count = app(StockCountService::class)->start($this->admin, $this->main->id, StockCountScope::All);
    $item = $count->items()->sole();

    Livewire::test(StockCountShow::class, ['stockCount' => $count])
        ->call('openEntry', $item->id)
        ->set('entryLines.base', '500')
        ->call('saveEntry')
        ->assertHasErrors('entryLines')
        ->call('saveEntry')
        ->assertHasNoErrors();

    expect((float) $item->fresh()->counted_qty)->toBe(500.0);

    HeldOrder::query()->create(['outlet_id' => $this->main->id, 'user_id' => $this->admin->id, 'label' => 'Meja 1', 'cart' => ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]], 'item_count' => 1, 'total' => 1000]);
    app(StockCountService::class)->preview($count);

    expect($item->fresh()->hasFlag(StockCountVariance::FLAG_PENDING_HELD_ORDER))->toBeTrue();
});

test('a count belongs to its outlet: switching the active outlet makes it read-only and hides it from the list', function () {
    $branch = makeOutlet(['code' => 'CB1', 'name' => 'Cabang B']);
    $count = app(StockCountService::class)->start($this->admin, $this->main->id, StockCountScope::All);
    $item = $count->items()->sole();

    session([IdentifyOutlet::SESSION_KEY => $branch->id]);
    app(CurrentOutlet::class)->set($branch->id);

    Livewire::test(StockCounts::class)->assertDontSee($count->number);

    Livewire::test(StockCountShow::class, ['stockCount' => $count])
        ->assertSee('hanya bisa dilihat')
        ->assertSee('Pindah ke '.$this->main->name)
        ->assertDontSee('Lanjut ke Periksa')
        ->set('code', '8990001')->call('scan')
        ->call('openEntry', $item->id)
        ->assertNotDispatched('open-modal')
        ->call('submit')
        ->call('switchToDocumentOutlet')
        ->assertRedirect(route('inventory.opname.show', $count));

    expect($count->fresh()->entries()->count())->toBe(0)
        ->and($count->fresh()->status)->toBe(StockCountStatus::Counting)
        ->and(session(IdentifyOutlet::SESSION_KEY))->toBe($this->main->id);
});
