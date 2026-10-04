<?php

namespace App\Livewire\Settings;

use App\Services\TenantDataExporter;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Ekspor Data Toko'])]
#[Title('Ekspor Data Toko')]
class DataExport extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()->isSuperAdmin(), 403);
    }

    public function export(TenantDataExporter $exporter, CurrentTenant $currentTenant)
    {
        abort_unless(Auth::user()->isSuperAdmin(), 403);
        abort_if($currentTenant->get() && ! $currentTenant->get()->isPro(), 403, 'Fitur ekspor data toko hanya tersedia untuk paket Pro.');

        $path = $exporter->export();
        $name = 'data-'.($currentTenant->get()?->slug ?? 'toko').'-'.now()->format('Ymd-His').'.zip';

        activity('export')->causedBy(Auth::user())
            ->event('exported')
            ->withProperties(['file' => $name])
            ->log("Mengekspor seluruh data toko ({$name}).");

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    public function render(TenantDataExporter $exporter)
    {
        return view('livewire.settings.data-export', [
            'tenant' => app(CurrentTenant::class)->get(),
            'datasets' => collect($exporter->datasets())->map(fn (array $dataset, string $name) => [
                'file' => "{$name}.csv",
                'label' => $dataset['label'],
                'count' => $dataset['count'](),
            ])->values(),
        ]);
    }
}
