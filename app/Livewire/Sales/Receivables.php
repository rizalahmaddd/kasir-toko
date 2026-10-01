<?php

namespace App\Livewire\Sales;

use App\Enums\PaymentMethod;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Sale;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Piutang (Kasbon)'])]
#[Title('Piutang (Kasbon)')]
class Receivables extends Component
{
    use WithDataTable, WithRealtimeRefresh;

    #[Url]
    public string $search = '';

    public ?int $collectingId = null;

    public string $payAmount = '';

    public string $payMethod = 'cash';

    public string $payReference = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return Builder<Sale>
     */
    protected function query(): Builder
    {
        $term = trim($this->search);

        return Sale::query()
            ->completed()
            ->where('due_amount', '>', 0)
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $query) => $query->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))));
    }

    public function openCollect(int $saleId): void
    {
        $sale = $this->query()->findOrFail($saleId);

        $this->resetValidation();
        $this->collectingId = $sale->id;
        $this->payAmount = (string) $sale->due_amount;
        $this->payMethod = 'cash';
        $this->payReference = '';
        $this->dispatch('open-modal', 'collect-payment');
    }

    public function collect(SaleService $sales): void
    {
        $sale = Sale::findOrFail($this->collectingId);

        $this->payAmount = preg_replace('/\D/', '', $this->payAmount) ?? '';
        $validated = $this->validate([
            'payAmount' => ['required', 'integer', 'min:1'],
            'payMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'payReference' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $sales->payReceivable($sale, auth()->user(), (int) $validated['payAmount'], PaymentMethod::from($validated['payMethod']), $validated['payReference'] ?: null);
        } catch (PosException $exception) {
            $this->addError('payAmount', $exception->getMessage());

            return;
        }

        $this->collectingId = null;
        $this->dispatch('close-modal', 'collect-payment');
        $this->dispatch('notify', message: 'Pembayaran '.NumberFormatter::currency((int) $validated['payAmount'])." untuk {$sale->number} dicatat.");
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->with('customer')->orderBy('sold_at')->get()->map(fn (Sale $sale) => [
            $sale->number,
            $sale->sold_at->format('d/m/Y'),
            $sale->customer?->name ?: '-',
            $sale->customer?->phone ?: '-',
            NumberFormatter::currency($sale->total),
            NumberFormatter::currency($sale->paid_amount),
            NumberFormatter::currency($sale->due_amount),
            (int) $sale->sold_at->startOfDay()->diffInDays(now()->startOfDay()).' hari',
        ]);

        return $this->exportFormattedResponse('piutang', ['No. Transaksi', 'Tanggal', 'Pelanggan', 'Telepon', 'Total', 'Dibayar', 'Sisa', 'Umur'], $rows, 'Daftar Piutang', now()->translatedFormat('d F Y'), $format);
    }

    public function render()
    {
        $base = $this->query();

        return view('livewire.sales.receivables', [
            'sales' => (clone $base)->with('customer')->orderBy('sold_at')->paginate($this->perPage),
            'totalDue' => (int) Sale::query()->completed()->sum('due_amount'),
            'customerCount' => Sale::query()->completed()->where('due_amount', '>', 0)->distinct()->count('customer_id'),
            'collecting' => $this->collectingId ? Sale::with('customer')->find($this->collectingId) : null,
            'methods' => PosSettings::paymentMethods(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return ['sale.recorded'];
    }
}
