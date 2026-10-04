<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithDateRangeFilter;
use App\Models\TenantSubscriptionLog;
use App\Support\SaasPlans;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pembayaran langganan yang dicatat manual saat admin platform memperpanjang atau mengubah
 * paket toko, dari semua toko sekaligus.
 */
#[Layout('layouts.app', ['heading' => 'Pembayaran Langganan'])]
#[Title('Pembayaran Langganan')]
class Payments extends Component
{
    use WithDataTable;
    use WithDateRangeFilter;

    #[Url(history: true)]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-platform'), 403);

        $this->sortField = 'created_at';
        $this->sortDirection = 'desc';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return Builder<TenantSubscriptionLog>
     */
    private function filteredQuery(): Builder
    {
        $query = TenantSubscriptionLog::query()
            ->with(['tenant', 'user'])
            ->whereNotNull('amount')
            ->whereBetween('created_at', [Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to)->endOfDay()])
            ->when(trim($this->search) !== '', function (Builder $query) {
                $term = '%'.trim($this->search).'%';

                $query->where(fn (Builder $query) => $query
                    ->whereHas('tenant', fn (Builder $tenant) => $tenant->where('name', 'like', $term)->orWhere('slug', 'like', $term))
                    ->orWhere('note', 'like', $term));
            });

        $this->applySorting($query, ['created_at' => 'created_at', 'amount' => 'amount'], 'created_at', 'desc');

        return $query->orderByDesc('id');
    }

    public function export(string $format = 'xlsx')
    {
        abort_unless(auth()->user()->can('manage-platform'), 403);

        $headers = ['Tanggal', 'Toko', 'Aksi', 'Paket', 'Aktif Sampai', 'Nominal', 'Catatan', 'Dicatat Oleh'];
        $rows = $this->filteredQuery()->lazy()->map(fn (TenantSubscriptionLog $log) => [
            $log->created_at->format('d/m/Y H:i'),
            $log->tenant?->name ?? '-',
            $log->actionLabel(),
            SaasPlans::label($log->to_plan),
            $log->to_ends_at?->format('d/m/Y') ?? 'Tanpa batas',
            $log->amount,
            $log->note ?? '-',
            $log->user?->name ?? 'Sistem',
        ]);

        $period = 'Periode: '.Carbon::parse($this->from)->translatedFormat('d M Y').' s/d '.Carbon::parse($this->to)->translatedFormat('d M Y');

        return $this->exportFormattedResponse('pembayaran-langganan', $headers, $rows, 'Pembayaran Langganan', $period, $format);
    }

    public function render()
    {
        $query = $this->filteredQuery();

        return view('livewire.platform.payments', [
            'payments' => $query->paginate($this->perPage),
            'total' => (int) (clone $query)->reorder()->sum('amount'),
            'count' => (clone $query)->reorder()->count(),
            'payingTenants' => (clone $query)->reorder()->distinct()->count('tenant_id'),
        ]);
    }
}
