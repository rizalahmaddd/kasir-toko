<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Models\Tenant;
use App\Services\Pos\PrescriptionService;
use App\Support\CurrentTenant;
use App\Support\PosSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Retensi foto resep (UU PDP): toko yang mengisi masa simpan menghapus foto resep yang tanggal resepnya
 * sudah lewat masa itu. Data resep (dokter, pasien, obat) tetap disimpan; hanya fotonya yang dihapus.
 */
class PrunePrescriptionPhotos extends Command
{
    protected $signature = 'pharmacy:prune-prescription-photos {--dry-run : Hanya hitung, tidak menghapus}';

    protected $description = 'Hapus foto resep yang melewati masa simpan yang diatur tiap toko.';

    public function handle(CurrentTenant $currentTenant): int
    {
        $deleted = 0;
        $dryRun = (bool) $this->option('dry-run');

        Tenant::query()->whereIn('id', Prescription::query()->withoutGlobalScopes()->whereNotNull('image_path')->distinct()->select('tenant_id'))->each(function (Tenant $tenant) use ($currentTenant, $dryRun, &$deleted) {
            $currentTenant->run($tenant, function () use ($dryRun, &$deleted) {
                $years = PosSettings::prescriptionPhotoRetentionYears();

                if ($years <= 0) {
                    return;
                }

                Prescription::query()
                    ->whereNotNull('image_path')
                    ->whereDate('prescription_date', '<', today()->subYears($years))
                    ->chunkById(200, function ($prescriptions) use ($dryRun, &$deleted) {
                        foreach ($prescriptions as $prescription) {
                            $deleted++;

                            if ($dryRun) {
                                continue;
                            }

                            Storage::disk(PrescriptionService::DISK)->delete($prescription->image_path);
                            $prescription->forceFill(['image_path' => null])->saveQuietly();
                            activity('pharmacy')->performedOn($prescription)->event('photo_pruned')->log("Foto resep {$prescription->number} dihapus karena melewati masa simpan.");
                        }
                    });
            });
        });

        $this->info(($dryRun ? 'Akan dihapus: ' : 'Foto resep dihapus: ').$deleted);

        return self::SUCCESS;
    }
}
