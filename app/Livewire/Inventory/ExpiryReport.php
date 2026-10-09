<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\WithDataTable;
use App\Models\ProductBatch;
use App\Support\CurrentOutlet;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Batch yang sudah atau hampir kedaluwarsa di outlet aktif, beserta nilai kerugiannya (harga beli batch).
 */
#[Layout('layouts.app', ['heading' => 'Kedaluwarsa'])]
#[Title('Stok Kedaluwarsa')]
class ExpiryReport extends Component
{
    use WithDataTable;

    #[Url]
    public string $window = '';

    public function mount(): void
    {
        if (! in_array($this->window, ['expired', '30', '60', '90', '180'], true)) {
            $this->window = (string) PosSettings::expiryWarningDays();
        }
    }

    public function updatingWindow(): void
    {
        $this->resetPage();
    }

    /**
     * @return Builder<ProductBatch>
     */
    protected function query(): Builder
    {
        return ProductBatch::query()
            ->with('product')
            ->whereHas('product', fn (Builder $query) => $query->where('track_batch', true))
            ->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->when($this->window === 'expired', fn (Builder $query) => $query->whereDate('expires_at', '<', today()))
            ->when($this->window !== 'expired', fn (Builder $query) => $query->whereDate('expires_at', '<=', today()->addDays((int) $this->window)))
            ->orderBy('expires_at')
            ->orderBy('id');
    }

    /**
     * @return array{expired_count: int, expired_value: int, soon_count: int, soon_value: int}
     */
    #[Computed]
    public function summary(): array
    {
        $outletId = app(CurrentOutlet::class)->idOrPrimary();
        $base = fn () => ProductBatch::query()->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('product_batches.outlet_id', $outletId)
            ->where('products.track_batch', true)
            ->where('product_batches.quantity', '>', 0)
            ->whereNotNull('product_batches.expires_at');
        $value = 'product_batches.quantity * COALESCE(product_batches.unit_cost, products.cost_price)';

        $expired = $base()->whereDate('product_batches.expires_at', '<', today())->selectRaw("COUNT(*) as total, COALESCE(SUM({$value}), 0) as worth")->first();
        $soon = $base()->whereDate('product_batches.expires_at', '>=', today())->whereDate('product_batches.expires_at', '<=', today()->addDays(PosSettings::expiryWarningDays()))->selectRaw("COUNT(*) as total, COALESCE(SUM({$value}), 0) as worth")->first();

        return [
            'expired_count' => (int) $expired->total,
            'expired_value' => (int) $expired->worth,
            'soon_count' => (int) $soon->total,
            'soon_value' => (int) $soon->worth,
        ];
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->get()->map(fn (ProductBatch $batch) => [
            $batch->product?->name ?? '-',
            $batch->batch_number ?: 'Tanpa nomor',
            $batch->expires_at->format('d/m/Y'),
            (int) today()->diffInDays($batch->expires_at, false),
            NumberFormatter::quantity((float) $batch->quantity).' '.$batch->product?->unit,
            NumberFormatter::currency((int) round((float) $batch->quantity * ($batch->unit_cost ?? $batch->product?->cost_price ?? 0))),
        ]);

        return $this->exportFormattedResponse('stok-kedaluwarsa', ['Produk', 'Batch', 'Kedaluwarsa', 'Sisa Hari', 'Jumlah', 'Nilai'], $rows, 'Stok Kedaluwarsa', now()->translatedFormat('d F Y'), $format);
    }

    public function render()
    {
        return view('livewire.inventory.expiry-report', [
            'batches' => $this->query()->paginate($this->perPage),
        ]);
    }
}
