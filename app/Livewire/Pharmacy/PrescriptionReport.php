<?php

namespace App\Livewire\Pharmacy;

use App\Enums\SaleStatus;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithDateRangeFilter;
use App\Models\SaleItem;
use App\Support\NumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Penjualan obat wajib resep per periode beserta resep & dokternya, untuk arsip dan pemeriksaan.
 */
#[Layout('layouts.app', ['heading' => 'Laporan Obat Keras'])]
#[Title('Laporan Obat Keras')]
class PrescriptionReport extends Component
{
    use WithDataTable, WithDateRangeFilter;

    /**
     * @return Builder<SaleItem>
     */
    protected function query(): Builder
    {
        return SaleItem::query()
            ->with(['sale.prescription', 'sale.cashier', 'product'])
            ->whereHas('sale', fn (Builder $query) => $query
                ->where('status', SaleStatus::Completed->value)
                ->whereBetween('sold_at', [$this->from.' 00:00:00', $this->to.' 23:59:59']))
            ->where(fn (Builder $query) => $query
                ->whereNotNull('prescription_item_id')
                ->orWhereHas('product', fn (Builder $query) => $query->withTrashed()->where('requires_prescription', true)))
            ->orderByDesc('id');
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->get()->map(fn (SaleItem $item) => [
            $item->sale->sold_at->format('d/m/Y H:i'),
            $item->sale->number,
            $item->product_name,
            NumberFormatter::quantity((float) $item->quantity).' '.$item->unit,
            $item->sale->prescription?->number ?? 'TANPA RESEP',
            $item->sale->prescription ? 'dr. '.$item->sale->prescription->doctor_name : '-',
            $item->sale->prescription?->patient_name ?? '-',
            $item->sale->cashier?->name ?? '-',
        ]);

        activity('pharmacy')->causedBy(auth()->user())->event('exported')->log("Laporan obat keras {$this->from} s/d {$this->to} diekspor.");

        return $this->exportFormattedResponse('laporan-obat-keras', ['Waktu', 'Transaksi', 'Obat', 'Jumlah', 'No. Resep', 'Dokter', 'Pasien', 'Kasir'], $rows, 'Laporan Obat Keras', "{$this->from} s/d {$this->to}", $format);
    }

    public function render()
    {
        return view('livewire.pharmacy.prescription-report', [
            'items' => $this->query()->paginate($this->perPage),
        ]);
    }
}
