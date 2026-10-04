<?php

namespace App\Livewire\Sales;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Sale;
use App\Models\User;
use App\Support\DateInput;
use App\Support\NumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Riwayat Transaksi'])]
#[Title('Riwayat Transaksi')]
class SaleIndex extends Component
{
    use WithDataTable, WithRealtimeRefresh;

    #[Url]
    public string $search = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $method = '';

    #[Url]
    public string $cashier = '';

    public function mount(): void
    {
        $this->from = DateInput::valid($this->from) ?? now()->toDateString();
        $this->to = DateInput::valid($this->to) ?? now()->toDateString();

        if ($this->from > $this->to) {
            [$this->from, $this->to] = [$this->to, $this->from];
        }
    }

    public function updated(string $name): void
    {
        if ($name === 'from') {
            $this->from = DateInput::valid($this->from) ?? now()->toDateString();
            $this->to = max($this->to, $this->from);
        } elseif ($name === 'to') {
            $this->to = DateInput::valid($this->to) ?? now()->toDateString();
            $this->from = min($this->from, $this->to);
        }

        $this->resetPage();
    }

    public function preset(string $range): void
    {
        [$this->from, $this->to] = match ($range) {
            'yesterday' => [now()->subDay()->toDateString(), now()->subDay()->toDateString()],
            '7days' => [now()->subDays(6)->toDateString(), now()->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            default => [now()->toDateString(), now()->toDateString()],
        };
        $this->resetPage();
    }

    public function canViewAll(): bool
    {
        return auth()->user()->can('sales.view');
    }

    /**
     * @return Builder<Sale>
     */
    protected function query(): Builder
    {
        $term = trim($this->search);

        return Sale::query()
            ->when(! $this->canViewAll(), fn (Builder $query) => $query->where('user_id', auth()->id()))
            ->when($this->canViewAll() && ctype_digit($this->cashier), fn (Builder $query) => $query->where('user_id', (int) $this->cashier))
            ->whereBetween('sold_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when(SaleStatus::tryFrom($this->status), fn (Builder $query, SaleStatus $status) => $query->where('status', $status->value))
            ->when($this->status === 'credit', fn (Builder $query) => $query->completed()->where('due_amount', '>', 0))
            ->when(PaymentMethod::tryFrom($this->method), fn (Builder $query, PaymentMethod $method) => $query->whereHas('payments', fn (Builder $query) => $query->where('method', $method->value)))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $query) => $query->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orWhereHas('items', fn (Builder $query) => $query->where('product_name', 'like', "%{$term}%"))));
    }

    /**
     * @return array{count: int, total: int, voided: int}
     */
    protected function summary(): array
    {
        $completed = $this->query()->completed();

        return [
            'count' => (clone $completed)->count(),
            'total' => (int) (clone $completed)->sum('total'),
            'voided' => $this->query()->where('status', SaleStatus::Voided->value)->count(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    protected function cashiers(): Collection
    {
        return $this->canViewAll()
            ? User::query()->whereIn('id', Sale::query()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name'])
            : collect();
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->with(['customer', 'cashier', 'payments'])->orderBy('sold_at')->get()->map(fn (Sale $sale) => [
            $sale->number,
            $sale->sold_at->format('d/m/Y H:i'),
            $sale->cashier->name,
            $sale->customer?->name ?: 'Umum',
            NumberFormatter::currency($sale->subtotal),
            NumberFormatter::currency($sale->discount_amount),
            NumberFormatter::currency($sale->tax_amount),
            NumberFormatter::currency($sale->total),
            $sale->payments->map(fn ($payment) => $payment->method->shortLabel())->unique()->implode(', ') ?: '-',
            NumberFormatter::currency($sale->due_amount),
            $sale->status->label(),
        ]);

        return $this->exportFormattedResponse(
            'transaksi',
            ['No. Transaksi', 'Waktu', 'Kasir', 'Pelanggan', 'Subtotal', 'Diskon', 'Pajak', 'Total', 'Pembayaran', 'Sisa Kasbon', 'Status'],
            $rows,
            'Riwayat Transaksi',
            $this->from === $this->to ? $this->from : "{$this->from} s/d {$this->to}",
            $format,
        );
    }

    public function render()
    {
        return view('livewire.sales.sale-index', [
            'sales' => $this->query()->with(['customer', 'cashier', 'payments'])->withCount('items')->latest('sold_at')->latest('id')->paginate($this->perPage),
            'summary' => $this->summary(),
            'cashiers' => $this->cashiers(),
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
