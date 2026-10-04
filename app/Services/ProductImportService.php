<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Product;
use App\Services\Pos\StockService;
use App\Support\PlanLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportService
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected StockService $stockService
    ) {}

    /**
     * Template CSV untuk diisi oleh pengguna toko.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="template_import_produk.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            // Header kolom
            fputcsv($handle, [
                'nama_produk',
                'kategori',
                'sku',
                'barcode',
                'satuan',
                'harga_beli',
                'harga_jual',
                'stok_awal',
                'stok_minimum',
                'lacak_stok',
            ]);

            // Contoh data
            fputcsv($handle, [
                'Kopi Susu Gula Aren',
                'Minuman',
                'PRD-0001',
                '899123456701',
                'cup',
                '8000',
                '18000',
                '50',
                '10',
                'ya',
            ]);

            fputcsv($handle, [
                'Croissant Butter',
                'Bakery',
                'PRD-0002',
                '899123456702',
                'pcs',
                '12000',
                '25000',
                '30',
                '5',
                'ya',
            ]);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Mengimpor data produk dari file spreadsheet/CSV.
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function import(UploadedFile|string $file): array
    {
        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) <= 1) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['File kosong atau hanya berisi baris header.']];
        }

        // Ambil baris pertama sebagai header dan bersihkan
        $rawHeaders = array_shift($rows);
        $headerMap = [];

        foreach ($rawHeaders as $index => $colName) {
            $cleaned = strtolower(trim((string) $colName));
            $cleaned = str_replace([' ', '-', '/'], '_', $cleaned);
            $headerMap[$cleaned] = $index;
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        // Cache kategori yang sudah dibuat untuk optimasi performa
        $categoryCache = [];

        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 2; // +2 karena 1-based dan baris 1 adalah header

            // Cek jika baris kosong
            if (empty(array_filter($row))) {
                continue;
            }

            $name = trim((string) ($row[$headerMap['nama_produk'] ?? 0] ?? ''));
            if ($name === '') {
                $errors[] = "Baris {$rowNumber}: Nama produk tidak boleh kosong.";
                $skipped++;

                continue;
            }

            $rawPrice = $row[$headerMap['harga_jual'] ?? 6] ?? '0';
            $price = (int) preg_replace('/\D/', '', (string) $rawPrice);
            if ($price < 0) {
                $errors[] = "Baris {$rowNumber}: Harga jual tidak valid.";
                $skipped++;

                continue;
            }

            $rawCostPrice = $row[$headerMap['harga_beli'] ?? 5] ?? '0';
            $costPrice = (int) preg_replace('/\D/', '', (string) $rawCostPrice);

            $sku = trim((string) ($row[$headerMap['sku'] ?? 2] ?? ''));
            $barcode = trim((string) ($row[$headerMap['barcode'] ?? 3] ?? ''));
            $barcode = $barcode === '-' ? null : ($barcode ?: null);
            $unit = trim((string) ($row[$headerMap['satuan'] ?? 4] ?? 'pcs')) ?: 'pcs';
            $stock = (float) str_replace(',', '.', (string) ($row[$headerMap['stok_awal'] ?? 7] ?? 0));
            $minStock = (float) str_replace(',', '.', (string) ($row[$headerMap['stok_minimum'] ?? 8] ?? 0));

            $rawTrack = strtolower(trim((string) ($row[$headerMap['lacak_stok'] ?? 9] ?? 'ya')));
            $trackStock = in_array($rawTrack, ['ya', '1', 'true', 'yes'], true);

            // Validasi SKU / Barcode duplikat di tenant ini
            if ($sku !== '' && Product::query()->where('sku', $sku)->exists()) {
                $errors[] = "Baris {$rowNumber}: SKU '{$sku}' sudah digunakan di produk lain.";
                $skipped++;

                continue;
            }

            if ($barcode !== null && Product::query()->where('barcode', $barcode)->exists()) {
                $errors[] = "Baris {$rowNumber}: Barcode '{$barcode}' sudah digunakan di produk lain.";
                $skipped++;

                continue;
            }

            // Cek batas kuota paket produk
            try {
                PlanLimits::ensureCanAdd('products', 'import');
            } catch (ValidationException $e) {
                $errors[] = "Batas paket tercapai saat memproses baris {$rowNumber}. Produk berikutnya dibatalkan.";
                break;
            }

            // Kategori: cari atau buat jika belum ada
            $categoryName = trim((string) ($row[$headerMap['kategori'] ?? 1] ?? ''));
            $categoryId = null;

            if ($categoryName !== '' && $categoryName !== '-') {
                if (! isset($categoryCache[$categoryName])) {
                    $category = Category::query()->firstOrCreate(['name' => $categoryName]);
                    $categoryCache[$categoryName] = $category->id;
                }
                $categoryId = $categoryCache[$categoryName];
            }

            // Jika SKU kosong, generate otomatis
            if ($sku === '') {
                $sku = $this->numbers->next('PRD', 4);
            }

            DB::transaction(function () use (
                $name,
                $categoryId,
                $sku,
                $barcode,
                $unit,
                $costPrice,
                $price,
                $trackStock,
                $stock,
                $minStock
            ) {
                $product = Product::create([
                    'name' => $name,
                    'category_id' => $categoryId,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'unit' => $unit,
                    'cost_price' => $costPrice,
                    'price' => $price,
                    'track_stock' => $trackStock,
                    'stock' => 0, // Akan diupdate lewat move() jika ada stok awal
                    'min_stock' => $minStock,
                    'is_active' => true,
                ]);

                if ($trackStock && $stock != 0.0) {
                    $this->stockService->move(
                        $product,
                        StockMovementType::Initial,
                        $stock,
                        auth()->user(),
                        null,
                        'Stok awal saat import produk',
                        $costPrice
                    );
                }
            });

            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
