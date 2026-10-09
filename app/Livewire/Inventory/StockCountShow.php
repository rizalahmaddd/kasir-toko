<?php

namespace App\Livewire\Inventory;

use App\Enums\StockCountReason;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Events\StockCountUpdated;
use App\Http\Middleware\IdentifyOutlet;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Category;
use App\Models\HeldOrder;
use App\Models\ProductBatch;
use App\Models\StockCount;
use App\Models\StockCountCorrection;
use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use App\Models\StockCountSerial;
use App\Services\Pos\PosException;
use App\Services\Pos\StockCountPoster;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockCountSpreadsheet;
use App\Services\Pos\StockCountVariance;
use App\Services\Pos\StockService;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use App\Support\NumberFormatter as Num;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app', ['heading' => 'Stok Opname'])]
class StockCountShow extends Component
{
    use WithDataTable, WithFileUploads, WithRealtimeRefresh;

    public const FILTERS = ['all' => 'Semua', 'uncounted' => 'Belum dihitung', 'counted' => 'Sudah', 'variance' => 'Selisih', 'recount' => 'Hitung ulang'];

    public StockCount $stockCount;

    #[Url]
    public string $view = '';

    #[Url]
    public string $filter = 'all';

    #[Url]
    public string $search = '';

    public string $categoryId = '';

    public string $code = '';

    public ?string $scanMessage = null;

    public string $scanTone = 'emerald';

    public ?int $lastItemId = null;

    public ?int $pendingAddProductId = null;

    public ?int $entryItemId = null;

    /** @var array<string, string> 'base' atau id satuan => jumlah */
    public array $entryLines = [];

    public string $entryBatchId = '';

    public string $newBatchNumber = '';

    public string $newBatchExpiresAt = '';

    public string $entryNote = '';

    public bool $confirmLargeEntry = false;

    public ?int $historyItemId = null;

    public ?int $serialItemId = null;

    public string $serialCode = '';

    public string $unknownBarcode = '';

    public string $unknownQuantity = '1';

    public string $unknownNote = '';

    public string $uncountedPolicy = StockCount::UNCOUNTED_KEEP;

    /** @var array<string, int|float>|null */
    public ?array $impact = null;

    public string $cancelReason = '';

    public $importFile = null;

    /** @var list<array<string, mixed>> */
    public array $importRows = [];

    public function mount(StockCount $stockCount): void
    {
        abort_unless(auth()->user()->can('inventory.opname.count'), 403);

        $this->stockCount = $stockCount;

        if (! in_array($this->view, ['count', 'review'], true)) {
            $this->view = $stockCount->status === StockCountStatus::Review && $this->canManage() ? 'review' : 'count';
        }

        $this->perPage = 25;
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['filter', 'search', 'categoryId', 'view'], true)) {
            $this->resetPage();
        }
    }

    public function canManage(): bool
    {
        return auth()->user()->can('inventory.opname.manage');
    }

    public function canSeeSystem(): bool
    {
        return $this->canManage() || ! $this->stockCount->blind_count;
    }

    public function isEditable(): bool
    {
        return $this->isDocumentOutlet() && in_array($this->stockCount->status, [StockCountStatus::Counting, StockCountStatus::Review], true);
    }

    /**
     * Opname terikat ke outlet dokumennya. Setelah outlet aktif di header diganti, dokumen outlet lain
     * hanya bisa dilihat supaya hitungan tidak masuk ke opname yang salah.
     */
    public function isDocumentOutlet(): bool
    {
        return app(CurrentOutlet::class)->idOrPrimary() === $this->stockCount->outlet_id;
    }

    public function switchToDocumentOutlet(): void
    {
        abort_unless(app(CurrentOutlet::class)->canAccess($this->stockCount->outlet_id), 403);

        session([IdentifyOutlet::SESSION_KEY => $this->stockCount->outlet_id]);
        auth()->user()->forceFill(['default_outlet_id' => $this->stockCount->outlet_id])->saveQuietly();

        $this->redirectRoute('inventory.opname.show', $this->stockCount, navigate: true);
    }

    private function onDocumentOutlet(): bool
    {
        if ($this->isDocumentOutlet()) {
            return true;
        }

        $this->dispatch('notify', message: "Opname {$this->stockCount->number} milik outlet {$this->stockCount->outlet?->name}. Pindah ke outlet itu dulu untuk mengubahnya.", type: 'error');

        return false;
    }

    public function scan(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $code = trim($this->code);
        $this->code = '';
        $this->pendingAddProductId = null;

        if ($code === '' || ! $this->isEditable()) {
            return;
        }

        $found = $counts->lookup($this->stockCount, $code);

        if ($found['kind'] === 'unknown') {
            $this->openUnknown($code);
            $this->feedback("Barcode {$code} tidak dikenal. Catat sebagai barang tak dikenal?", 'amber');

            return;
        }

        $product = $found['product'];

        try {
            if ($found['kind'] === 'serial' || $product->tracksSerials()) {
                if ($found['kind'] === 'serial') {
                    $row = $counts->recordSerial($this->stockCount, auth()->user(), ['serial' => $found['serial'], 'product_id' => $product->id]);
                    $this->lastItemId = $this->stockCount->items()->where('product_id', $product->id)->value('id');
                    $this->feedback("{$product->name}: nomor seri {$row->serial} tercatat.");
                } else {
                    $item = $found['item'] ?? throw new PosException("{$product->name} belum masuk opname ini. Tambahkan dulu.", 'stock_count_out_of_scope', ['product_id' => $product->id]);
                    $this->openSerials($item->id);
                }

                return;
            }

            $entry = $counts->recordEntry($this->stockCount, auth()->user(), [
                'client_uuid' => (string) Str::uuid(),
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_id' => $found['unit']?->id,
                'source' => StockCountEntry::SOURCE_SCAN,
            ]);
        } catch (PosException $exception) {
            $this->handleRejection($exception, $product->id);

            return;
        }

        $unitName = $found['unit']->name ?? $product->unit;
        $this->lastItemId = $entry->stock_count_item_id;
        $this->feedback("+1 {$unitName} {$product->name}. Total ".Num::quantity((float) $entry->item->fresh()->counted_qty)." {$product->unit}.");
        $this->broadcast();
    }

    public function addPending(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        if ($this->pendingAddProductId === null) {
            return;
        }

        try {
            $counts->addProducts($this->stockCount, auth()->user(), [$this->pendingAddProductId]);
        } catch (PosException $exception) {
            $this->feedback($exception->getMessage(), 'rose');

            return;
        }

        $item = $this->stockCount->items()->where('product_id', $this->pendingAddProductId)->first();
        $this->pendingAddProductId = null;

        if ($item) {
            $this->lastItemId = $item->id;
            $item->product->tracksSerials() ? $this->openSerials($item->id) : $this->openEntry($item->id);
        }

        $this->feedback('Barang ditambahkan ke opname ini.');
    }

    public function openEntry(int $itemId): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $item = $this->findItem($itemId);

        if ($item->product->tracksSerials()) {
            $this->openSerials($itemId);

            return;
        }

        $this->resetValidation();
        $this->entryItemId = $item->id;
        $this->entryLines = ['base' => ''];

        foreach ($item->product->units as $unit) {
            $this->entryLines[(string) $unit->id] = '';
        }

        $this->reset('entryBatchId', 'newBatchNumber', 'newBatchExpiresAt', 'entryNote', 'confirmLargeEntry');
        unset($this->entryItem);
        $this->dispatch('open-modal', 'count-entry');
    }

    public function updatedEntryLines(): void
    {
        $this->confirmLargeEntry = false;
    }

    #[Computed]
    public function entryItem(): ?StockCountItem
    {
        return $this->entryItemId ? StockCountItem::query()->with(['product.units', 'product.batches' => fn ($query) => $query->where('outlet_id', $this->stockCount->outlet_id)->fefo()])->find($this->entryItemId) : null;
    }

    public function saveEntry(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $item = $this->entryItem ?? abort(404);
        $this->resetValidation();
        $lines = [];

        foreach ($this->entryLines as $unitId => $quantity) {
            $quantity = str_replace(',', '.', trim((string) $quantity));

            if ($quantity === '') {
                continue;
            }

            if (! is_numeric($quantity) || (float) $quantity < 0) {
                $this->addError('entryLines', 'Jumlah harus angka 0 atau lebih.');

                return;
            }

            $lines[] = ['unit_id' => $unitId === 'base' ? null : (int) $unitId, 'quantity' => (float) $quantity];
        }

        if ($lines === []) {
            $this->addError('entryLines', 'Isi jumlah yang dihitung. Ketik 0 bila barangnya tidak ada.');

            return;
        }

        $data = [
            'client_uuid' => (string) Str::uuid(),
            'product_id' => $item->product_id,
            'note' => trim($this->entryNote) ?: null,
            'product_batch_id' => $this->entryBatchId !== '' && $this->entryBatchId !== 'new' ? (int) $this->entryBatchId : null,
            'new_batch' => $this->entryBatchId === 'new' ? ['number' => $this->newBatchNumber, 'expires_at' => $this->newBatchExpiresAt ?: null] : null,
        ];
        $data += count($lines) === 1 ? ['quantity' => $lines[0]['quantity'], 'unit_id' => $lines[0]['unit_id']] : ['breakdown' => $lines];

        if ($this->entryBatchId === 'new' && trim($this->newBatchNumber) === '') {
            $this->addError('newBatchNumber', 'Isi nomor batch baru.');

            return;
        }

        $base = collect($lines)->sum(fn (array $line) => $line['quantity'] * (float) ($line['unit_id'] ? $item->product->units->firstWhere('id', $line['unit_id'])?->factor ?? 1 : 1));
        $system = app(StockService::class)->outletStock($item->product, $this->stockCount->outlet_id);

        if ($this->canSeeSystem() && ! $this->confirmLargeEntry && $system > 0 && $base > $system * 10) {
            $this->confirmLargeEntry = true;
            $this->addError('entryLines', 'Hitungan ini lebih dari 10× stok sistem ('.Num::quantity($system).' '.$item->product->unit.'). Pastikan tidak salah ketik, lalu tekan Simpan sekali lagi.');

            return;
        }

        try {
            $entry = $counts->recordEntry($this->stockCount, auth()->user(), $data);
        } catch (PosException $exception) {
            $this->addError('entryLines', $exception->getMessage());

            return;
        }

        $this->lastItemId = $item->id;
        $this->entryItemId = null;
        $this->dispatch('close-modal', 'count-entry');
        $this->feedback("{$item->product->name}: ".Num::quantity((float) $entry->quantity_base)." {$item->product->unit} dicatat.");
        $this->broadcast();
    }

    public function openHistory(int $itemId): void
    {
        $this->historyItemId = $this->findItem($itemId)->id;
        unset($this->historyItem);
        $this->dispatch('open-modal', 'count-history');
    }

    #[Computed]
    public function historyItem(): ?StockCountItem
    {
        return $this->historyItemId ? StockCountItem::query()->with(['product', 'entries' => fn ($query) => $query->with(['user', 'voider', 'unit', 'batch'])->latest('counted_at'), 'corrections.sale'])->find($this->historyItemId) : null;
    }

    public function voidEntry(int $entryId, StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $entry = StockCountEntry::query()->whereHas('item', fn (Builder $query) => $query->where('stock_count_id', $this->stockCount->id))->findOrFail($entryId);

        try {
            $counts->voidEntry($entry, auth()->user());
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        unset($this->historyItem);
        $this->dispatch('notify', message: 'Hitungan dibatalkan.');
        $this->broadcast();
    }

    public function openSerials(int $itemId): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $this->serialItemId = $this->findItem($itemId)->id;
        $this->serialCode = '';
        unset($this->serialItem);
        $this->dispatch('open-modal', 'count-serials');
    }

    #[Computed]
    public function serialItem(): ?StockCountItem
    {
        return $this->serialItemId ? StockCountItem::query()->with('product')->find($this->serialItemId) : null;
    }

    /**
     * @return Collection<int, StockCountSerial>
     */
    #[Computed]
    public function serialRows(): Collection
    {
        $item = $this->serialItem;

        return $item ? $this->stockCount->serials()->with('user')->where('product_id', $item->product_id)->latest('scanned_at')->latest('id')->get() : new Collection;
    }

    public function scanSerial(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $item = $this->serialItem ?? abort(404);
        $serial = trim($this->serialCode);
        $this->serialCode = '';

        if ($serial === '') {
            return;
        }

        try {
            $row = $counts->recordSerial($this->stockCount, auth()->user(), ['serial' => $serial, 'product_id' => $item->product_id]);
        } catch (PosException $exception) {
            $this->addError('serialCode', $exception->getMessage());

            return;
        }

        $this->resetValidation('serialCode');
        unset($this->serialRows);
        $this->lastItemId = $item->id;

        if ($row->result !== StockCountSerial::RESULT_MATCHED) {
            $this->dispatch('notify', message: "Nomor seri {$row->serial}: ".self::serialResultLabel($row->result).'. Tentukan tindakannya di daftar.', type: 'warning');
        }

        $this->broadcast();
    }

    public function removeSerial(int $rowId, StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $row = $this->stockCount->serials()->findOrFail($rowId);

        try {
            $counts->removeSerial($row, auth()->user());
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        unset($this->serialRows);
    }

    public function setSerialAction(int $rowId, string $action, StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $row = $this->stockCount->serials()->findOrFail($rowId);

        try {
            $counts->setSerialAction($row, auth()->user(), $action === '' ? null : $action);
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        unset($this->serialRows);
    }

    public static function serialResultLabel(string $result): string
    {
        return match ($result) {
            StockCountSerial::RESULT_MATCHED => 'Cocok',
            StockCountSerial::RESULT_UNKNOWN => 'Belum tercatat',
            StockCountSerial::RESULT_OTHER_OUTLET => 'Tercatat di outlet lain',
            StockCountSerial::RESULT_SOLD => 'Tercatat sudah terjual',
            StockCountSerial::RESULT_REMOVED => 'Tercatat sudah keluar',
            default => $result,
        };
    }

    public function openUnknown(string $barcode = ''): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $this->resetValidation();
        $this->unknownBarcode = $barcode;
        $this->unknownQuantity = '1';
        $this->unknownNote = '';
        $this->dispatch('open-modal', 'count-unknown');
    }

    public function saveUnknown(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $this->unknownQuantity = str_replace(',', '.', trim($this->unknownQuantity));
        $this->validate([
            'unknownBarcode' => ['required', 'string', 'max:64'],
            'unknownQuantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'unknownNote' => ['nullable', 'string', 'max:255'],
        ], ['unknownQuantity.gt' => 'Jumlah harus lebih dari 0.']);

        try {
            $counts->recordUnknown($this->stockCount, auth()->user(), $this->unknownBarcode, (float) $this->unknownQuantity, trim($this->unknownNote) ?: null);
        } catch (PosException $exception) {
            $this->addError('unknownBarcode', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'count-unknown');
        $this->feedback("Barcode {$this->unknownBarcode} dicatat sebagai barang tak dikenal.", 'amber');
    }

    public function submit(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        try {
            $count = $counts->submit($this->stockCount, auth()->user());
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        $this->stockCount = $count->fresh();
        $this->broadcast();

        if ($this->canManage()) {
            $this->view = 'review';
            $this->impact = null;
            $this->resetPage();

            return;
        }

        $this->dispatch('notify', message: 'Terima kasih. Pemeriksa sudah diberi tahu bahwa Anda selesai menghitung.');
    }

    public function reopen(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        try {
            $this->stockCount = $counts->reopen($this->stockCount, auth()->user())->fresh();
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        $this->view = 'count';
        $this->broadcast();
    }

    public function showView(string $view): void
    {
        if ($view === 'review' && ! $this->canManage()) {
            return;
        }

        $this->view = $view === 'review' ? 'review' : 'count';
        $this->impact = null;
    }

    public function refreshImpact(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        abort_unless($this->canManage(), 403);

        try {
            $this->impact = $counts->preview($this->stockCount);
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');
        }
    }

    public function toggleRecount(int $itemId, StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $item = $this->findItem($itemId);

        try {
            $counts->markRecount($item, auth()->user(), ! $item->needs_recount);
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');
        }
    }

    public function setReason(int $itemId, string $reason, StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        try {
            $counts->setReason($this->findItem($itemId), auth()->user(), StockCountReason::tryFrom($reason));
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');
        }
    }

    public function confirmPost(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        $this->refreshImpact($counts);

        if ($this->impact !== null) {
            $this->dispatch('open-modal', 'count-post');
        }
    }

    public function post(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        try {
            $this->stockCount = $counts->post($this->stockCount, auth()->user(), $this->uncountedPolicy)->fresh();
        } catch (PosException $exception) {
            $this->dispatch('close-modal', 'count-post');
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('close-modal', 'count-post');
        $this->view = 'count';
        $this->filter = 'all';
        $this->resetPage();
        $this->dispatch('notify', message: $this->stockCount->status === StockCountStatus::Posted
            ? "Opname {$this->stockCount->number} selesai. Stok sudah disesuaikan."
            : "Opname {$this->stockCount->number} sedang diproses. Halaman ini diperbarui otomatis.");
    }

    public function resumePosting(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        try {
            $this->stockCount = $counts->resume($this->stockCount, auth()->user())->fresh();
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');
        }
    }

    public function pollPosting(): void
    {
        $this->stockCount->refresh();
    }

    public function cancel(StockCountService $counts): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        try {
            $this->stockCount = $counts->cancel($this->stockCount, auth()->user(), $this->cancelReason)->fresh();
        } catch (PosException $exception) {
            $this->addError('cancelReason', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'count-cancel');
        $this->dispatch('notify', message: "Opname {$this->stockCount->number} dibatalkan. Stok tidak berubah.");
        $this->broadcast();
    }

    public function createCorrection(StockCountService $counts)
    {
        if (! $this->onDocumentOutlet()) {
            return null;
        }

        try {
            $count = $counts->start(
                auth()->user(),
                $this->stockCount->outlet_id,
                StockCountScope::Products,
                [],
                $this->stockCount->items()->pluck('product_id')->all(),
                $this->stockCount->blind_count,
                $this->stockCount->hold_adjustments,
                "Koreksi {$this->stockCount->number}",
            );
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return null;
        }

        return $this->redirectRoute('inventory.opname.show', $count, navigate: true);
    }

    public function export(string $format = 'xlsx')
    {
        $canSeeSystem = $this->canSeeSystem();
        $rows = $this->stockCount->items()->with('product')
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->orderBy('products.name')
            ->select('stock_count_items.*')
            ->get()
            ->map(fn (StockCountItem $item) => [
                $item->product->sku,
                $item->product->name,
                $item->product->unit,
                $canSeeSystem && $item->reference_system_qty !== null ? Num::quantity((float) $item->reference_system_qty) : '',
                $item->counted_qty !== null ? Num::quantity((float) $item->counted_qty) : 'Belum dihitung',
                $canSeeSystem && $item->variance_qty !== null ? Num::quantity((float) $item->variance_qty) : '',
                $canSeeSystem && $item->variance_qty !== null ? Num::currency((int) round((float) $item->variance_qty * (int) $item->unit_cost)) : '',
                $item->reason?->label() ?? '',
            ]);

        return $this->exportFormattedResponse(
            Str::slug($this->stockCount->number),
            ['SKU', 'Barang', 'Satuan', 'Stok sistem', 'Hasil hitung', 'Selisih', 'Nilai selisih', 'Alasan'],
            $rows,
            "Stok Opname {$this->stockCount->number}",
            $this->stockCount->outlet?->name,
            $format,
        );
    }

    public function isPro(): bool
    {
        return (bool) app(CurrentTenant::class)->get()?->isPro();
    }

    public function downloadTemplate(StockCountSpreadsheet $spreadsheet)
    {
        if (! $this->onDocumentOutlet()) {
            return null;
        }

        abort_unless($this->isPro(), 403);

        return $spreadsheet->template($this->stockCount);
    }

    public function updatedImportFile(StockCountSpreadsheet $spreadsheet): void
    {
        abort_unless($this->isPro(), 403);

        $this->validate(['importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']], ['importFile.mimes' => 'Pakai berkas Excel (.xlsx) atau CSV.']);

        try {
            $this->importRows = $spreadsheet->parse($this->stockCount, $this->importFile->getRealPath());
        } catch (PosException $exception) {
            $this->importRows = [];
            $this->addError('importFile', $exception->getMessage());

            return;
        }

        if ($this->importRows === []) {
            $this->addError('importFile', 'Tidak ada baris dengan kolom Hitungan terisi.');
        }
    }

    public function openImport(): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        abort_unless($this->isPro(), 403);

        $this->resetValidation();
        $this->reset('importFile', 'importRows');
        $this->dispatch('open-modal', 'count-import');
    }

    public function importNow(StockCountSpreadsheet $spreadsheet): void
    {
        if (! $this->onDocumentOutlet()) {
            return;
        }

        abort_unless($this->isPro(), 403);

        try {
            $result = $spreadsheet->import($this->stockCount, auth()->user(), $this->importRows);
        } catch (PosException $exception) {
            $this->addError('importFile', $exception->getMessage());

            return;
        }

        $this->reset('importFile', 'importRows');
        $this->dispatch('close-modal', 'count-import');
        $this->dispatch('notify', message: "{$result['saved']} hitungan diimpor.".($result['failed'] !== [] ? ' '.count($result['failed']).' baris gagal: '.implode('; ', array_slice($result['failed'], 0, 3)) : ''), type: $result['failed'] === [] ? 'success' : 'warning');
        $this->broadcast();
    }

    /**
     * @return array{items: int, counted: int, changed: int, shortage_qty: float, shortage_value: int, surplus_qty: float, surplus_value: int, uncounted: int}
     */
    #[Computed]
    public function progress(): array
    {
        return app(StockCountVariance::class)->summarize($this->stockCount);
    }

    /**
     * Hal yang bisa membuat selisih salah, ditampilkan di atas tabel Periksa.
     *
     * @return list<array{tone: string, icon: string, message: string}>
     */
    #[Computed]
    public function warnings(): array
    {
        $warnings = [];
        $productIds = $this->stockCount->scope === StockCountScope::All ? null : $this->stockCount->items()->pluck('product_id')->flip();
        $held = HeldOrder::query()->where('outlet_id', $this->stockCount->outlet_id)->get(['cart'])
            ->filter(fn (HeldOrder $order) => collect($order->cart['items'] ?? [])->contains(fn ($line) => $productIds === null || $productIds->has((int) ($line['product_id'] ?? 0))))
            ->count();

        if ($held > 0) {
            $warnings[] = ['tone' => 'amber', 'icon' => 'clock', 'message' => "Ada {$held} transaksi tertunda/bill terbuka berisi barang yang dihitung. Selesaikan dulu di kasir supaya selisihnya tidak salah."];
        }

        $manual = $this->stockCount->items()->whereJsonContains('flags', StockCountVariance::FLAG_MANUAL_MOVEMENT)->count();

        if ($manual > 0) {
            $warnings[] = ['tone' => 'amber', 'icon' => 'arrow-left-right', 'message' => "{$manual} barang punya stok masuk/keluar atau transfer setelah dihitung. Periksa barisnya bila barang itu datang sebelum dihitung."];
        }

        $stale = $this->stockCount->items()->whereNotNull('reference_at')->where('reference_at', '<', now()->subDays(7))->count();

        if ($stale > 0) {
            $warnings[] = ['tone' => 'amber', 'icon' => 'calendar-clock', 'message' => "{$stale} barang dihitung lebih dari 7 hari lalu. Hitung ulang bila perlu."];
        }

        $warnings[] = ['tone' => 'sky', 'icon' => 'smartphone', 'message' => 'Pastikan semua HP kasir sudah online dan antrean transaksi offline sudah terkirim sebelum menyelesaikan opname.'];

        return $warnings;
    }

    /**
     * @return Collection<int, StockCountCorrection>
     */
    #[Computed]
    public function corrections(): Collection
    {
        return StockCountCorrection::query()->with(['item.product', 'sale'])
            ->whereHas('item', fn (Builder $query) => $query->where('stock_count_id', $this->stockCount->id))
            ->latest('id')->get();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->whereIn('id', $this->stockCount->items()->join('products', 'products.id', '=', 'stock_count_items.product_id')->select('products.category_id'))->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, ProductBatch>
     */
    public function batchesOf(StockCountItem $item): Collection
    {
        return $item->product->batches;
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeOutletEvents(): array
    {
        return ['stock-count.updated'];
    }

    private function findItem(int $itemId): StockCountItem
    {
        return $this->stockCount->items()->with('product.units')->findOrFail($itemId);
    }

    private function feedback(string $message, string $tone = 'emerald'): void
    {
        $this->scanMessage = $message;
        $this->scanTone = $tone;
    }

    private function handleRejection(PosException $exception, int $productId): void
    {
        if ($exception->reason === 'stock_count_out_of_scope') {
            $this->pendingAddProductId = $productId;
        }

        $this->feedback($exception->getMessage(), $exception->reason === 'stock_count_out_of_scope' ? 'amber' : 'rose');
    }

    private function broadcast(): void
    {
        StockCountUpdated::dispatch($this->stockCount);
    }

    public function render()
    {
        $this->stockCount->loadMissing(['outlet', 'creator', 'submitter', 'poster', 'canceller']);
        $review = $this->view === 'review' && $this->stockCount->status === StockCountStatus::Review && $this->isDocumentOutlet();

        if ($review && $this->impact === null && $this->canManage()) {
            $this->impact = app(StockCountService::class)->preview($this->stockCount);
        }

        $items = $this->stockCount->items()
            ->with(['product.units', 'product.category', 'product.batches' => fn ($query) => $query->where('outlet_id', $this->stockCount->outlet_id)->where('quantity', '>', 0)->fefo()])
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->select('stock_count_items.*')
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('products.name', 'like', "%{$this->search}%")
                ->orWhere('products.sku', 'like', "%{$this->search}%")
                ->orWhere('products.barcode', $this->search)))
            ->when($this->categoryId !== '', fn (Builder $query) => $query->where('products.category_id', (int) $this->categoryId))
            ->when($review, fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('stock_count_items.variance_qty', '!=', 0)->orWhere('stock_count_items.needs_recount', true))
                ->orderByRaw('ABS(COALESCE(stock_count_items.variance_qty, 0) * COALESCE(stock_count_items.unit_cost, 0)) DESC'))
            ->when(! $review, fn (Builder $query) => $query
                ->when($this->filter === 'uncounted', fn (Builder $query) => $query->whereNull('stock_count_items.counted_qty'))
                ->when($this->filter === 'counted', fn (Builder $query) => $query->whereNotNull('stock_count_items.counted_qty'))
                ->when($this->filter === 'variance' && $this->canSeeSystem(), fn (Builder $query) => $query->where('stock_count_items.variance_qty', '!=', 0))
                ->when($this->filter === 'recount', fn (Builder $query) => $query->where('stock_count_items.needs_recount', true))
                ->when($this->lastItemId, fn (Builder $query) => $query->orderByRaw('stock_count_items.id = ? DESC', [$this->lastItemId])))
            ->orderBy('products.name')
            ->paginate($this->perPage);

        return view('livewire.inventory.stock-count-show', [
            'items' => $items,
            'review' => $review,
            'multiOutlet' => app(CurrentOutlet::class)->isMultiOutlet(),
            'canManage' => $this->canManage(),
            'canSeeSystem' => $this->canSeeSystem(),
            'editable' => $this->isEditable(),
            'otherOutlet' => ! $this->isDocumentOutlet(),
            'postingProgress' => $this->stockCount->status === StockCountStatus::Posting
                ? [$this->stockCount->items()->whereNotNull('stock_movement_id')->count(), $this->stockCount->items()->where('variance_qty', '!=', 0)->count()]
                : null,
            'chunk' => StockCountPoster::CHUNK,
        ])->title("{$this->stockCount->number} · Stok Opname");
    }
}
