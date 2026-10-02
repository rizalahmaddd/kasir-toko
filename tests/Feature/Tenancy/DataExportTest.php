<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\TenantDataExporter;
use App\Support\CurrentTenant;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array<string, list<list<string>>>
 */
function readExport(string $zipPath): array
{
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $zip->getFromIndex($i));
        $files[$name] = array_map(fn (string $line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", $content))));
    }

    $zip->close();

    return $files;
}

it('lets the shop owner download every dataset of their own shop only', function () {
    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->run($otherTenant, fn () => Product::factory()->create(['name' => 'Produk Toko Lain']));
    Product::factory()->create(['name' => 'Kopi Susu', 'price' => 15000]);
    Customer::factory()->create(['name' => 'Bu Siti'])->delete();
    Sale::factory()->create();
    actingAsSuperAdmin();

    $response = $this->get(route('settings.data-export.download'))->assertOk()->assertDownload();
    $files = readExport($response->getFile()->getPathname());
    @unlink($response->getFile()->getPathname());

    expect(array_keys($files))->toEqualCanonicalizing([
        'produk.csv', 'kategori.csv', 'pelanggan.csv', 'pengguna.csv', 'transaksi.csv', 'transaksi_item.csv',
        'pembayaran.csv', 'shift.csv', 'kas_masuk_keluar.csv', 'mutasi_stok.csv', 'pengaturan.csv',
    ])
        ->and(collect($files['produk.csv'])->pluck(3))->toContain('Kopi Susu')->not->toContain('Produk Toko Lain')
        ->and(collect($files['produk.csv'])->firstWhere(3, 'Kopi Susu')[8])->toBe('15000')
        ->and(collect($files['pelanggan.csv'])->pluck(2))->toContain('Bu Siti')
        ->and(count($files['transaksi.csv']))->toBe(2)
        ->and(Activity::query()->where('log_name', 'export')->exists())->toBeTrue();
});

it('shows what will be exported', function () {
    Product::factory()->count(3)->create();
    actingAsSuperAdmin();

    $this->get(route('settings.data-export'))->assertOk()->assertSee('produk.csv')->assertSee('Unduh Data (ZIP)');
});

it('keeps the export to the shop owner', function () {
    actingAsAdmin();

    $this->get(route('settings.data-export'))->assertForbidden();
    $this->get(route('settings.data-export.download'))->assertForbidden();
});

it('refuses to export without an active shop', function () {
    app(CurrentTenant::class)->set(null);

    app(TenantDataExporter::class)->export();
})->throws(LogicException::class);
