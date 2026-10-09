<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductUnit;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\User;
use App\Support\NumberFormatter;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Template Excel lembar hitung dan impor hasil hitungnya. Setiap baris berkas mendapat client_uuid tetap
 * (dari isi berkas + nomor baris), jadi mengimpor berkas yang sama dua kali tidak membuat hitungan dobel.
 */
class StockCountSpreadsheet
{
    public const HEADERS = ['SKU', 'Barcode', 'Nama', 'Satuan', 'Batch', 'ED', 'Hitungan'];

    public const MAX_ROWS = 5000;

    public function __construct(private StockCountService $counts) {}

    public function template(StockCount $count): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lembar hitung');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $row = 2;

        $count->items()->with(['product.units', 'product.batches' => fn ($query) => $query->where('outlet_id', $count->outlet_id)->where('quantity', '>', 0)->fefo()])
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->orderBy('products.name')
            ->select('stock_count_items.*')
            ->chunk(500, function ($items) use ($sheet, &$row) {
                foreach ($items as $item) {
                    $product = $item->product;
                    $batches = $product->tracksBatches() && $product->batches->isNotEmpty() ? $product->batches : collect([null]);

                    foreach ($batches as $batch) {
                        $sheet->setCellValueExplicit("A{$row}", (string) $product->sku, 's');
                        $sheet->setCellValueExplicit("B{$row}", (string) $product->barcode, 's');
                        $sheet->setCellValue("C{$row}", $product->name.($product->variantLabel() ? ' — '.$product->variantLabel() : ''));
                        $sheet->setCellValue("D{$row}", $product->unit);
                        $sheet->setCellValueExplicit("E{$row}", (string) $batch?->batch_number, 's');
                        $sheet->setCellValue("F{$row}", $batch?->expires_at?->format('Y-m-d'));
                        $row++;
                    }
                }
            });

        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, "lembar-hitung-{$count->number}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Baca berkas tanpa menyimpan apa pun. Hasilnya ditampilkan dulu sebelum diimpor.
     *
     * @return list<array{row: int, status: 'ok'|'skipped'|'error', message: ?string, name: string, quantity: ?float, entry: ?array<string, mixed>}>
     */
    public function parse(StockCount $count, string $path): array
    {
        try {
            $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new PosException('Berkas tidak bisa dibaca. Pakai template Excel dari halaman ini.');
        }

        $header = array_map(fn ($value) => mb_strtolower(trim((string) $value)), array_shift($rows) ?? []);
        $column = fn (string $name) => array_search(mb_strtolower($name), $header, true);
        $columns = array_combine(self::HEADERS, array_map($column, self::HEADERS));

        if ($columns['Hitungan'] === false || ($columns['SKU'] === false && $columns['Barcode'] === false)) {
            throw new PosException('Kolom SKU/Barcode dan Hitungan tidak ditemukan. Pakai template Excel dari halaman ini.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new PosException('Maksimal '.NumberFormatter::quantity(self::MAX_ROWS).' baris per berkas.');
        }

        $hash = hash_file('sha256', $path);
        $value = fn (array $row, string $name) => $columns[$name] === false ? '' : trim((string) ($row[$columns[$name]] ?? ''));
        $results = [];

        foreach ($rows as $index => $row) {
            $number = $index + 2;
            $rawQuantity = str_replace(',', '.', $value($row, 'Hitungan'));
            $name = $value($row, 'Nama') ?: ($value($row, 'SKU') ?: $value($row, 'Barcode'));

            if ($rawQuantity === '') {
                continue;
            }

            $result = ['row' => $number, 'status' => 'error', 'message' => null, 'name' => $name, 'quantity' => null, 'entry' => null];

            if (! is_numeric($rawQuantity) || (float) $rawQuantity < 0) {
                $results[] = [...$result, 'message' => 'Hitungan bukan angka yang valid.'];

                continue;
            }

            $quantity = round((float) $rawQuantity, 3);
            [$product, $unit] = $this->findProduct($value($row, 'SKU'), $value($row, 'Barcode'));

            if ($product === null) {
                $results[] = [...$result, 'message' => 'Barang tidak ditemukan.'];

                continue;
            }

            $result['name'] = $product->name;

            if (! $product->track_stock || $product->isVariantParent()) {
                $results[] = [...$result, 'message' => 'Stok barang ini tidak dilacak.'];

                continue;
            }

            if ($product->tracksSerials()) {
                $results[] = [...$result, 'message' => 'Barang bernomor seri dihitung dengan scan IMEI.'];

                continue;
            }

            $unitName = $value($row, 'Satuan');

            if ($unitName !== '' && mb_strtolower($unitName) !== mb_strtolower((string) $product->unit)) {
                $unit = $product->units()->whereRaw('LOWER(name) = ?', [mb_strtolower($unitName)])->first();

                if ($unit === null) {
                    $results[] = [...$result, 'message' => "Satuan \"{$unitName}\" tidak dikenal untuk barang ini."];

                    continue;
                }
            }

            $entry = [
                'client_uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, "stock-count:{$count->id}:{$hash}:{$number}")->toString(),
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_id' => $unit?->id,
                'source' => StockCountEntry::SOURCE_IMPORT,
                'note' => "Impor baris {$number}",
            ];

            $batchNumber = $value($row, 'Batch');

            if ($product->tracksBatches() && $batchNumber !== '') {
                $batch = ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', $count->outlet_id)->where('batch_number', $batchNumber)->first();
                $entry += $batch ? ['product_batch_id' => $batch->id] : ['new_batch' => ['number' => $batchNumber, 'expires_at' => $this->date($row[$columns['ED']] ?? null)]];
            }

            $results[] = [...$result, 'status' => 'ok', 'quantity' => $quantity, 'entry' => $entry];
        }

        return $results;
    }

    /**
     * @param  list<array{status: string, entry: ?array<string, mixed>}>  $rows
     * @return array{saved: int, failed: list<string>}
     */
    public function import(StockCount $count, User $user, array $rows): array
    {
        $saved = 0;
        $failed = [];

        foreach ($rows as $row) {
            if ($row['status'] !== 'ok' || $row['entry'] === null) {
                continue;
            }

            try {
                $this->counts->recordEntry($count, $user, $row['entry']);
                $saved++;
            } catch (PosException $exception) {
                if (in_array($exception->reason, ['stock_count_closed', 'forbidden'], true)) {
                    throw $exception;
                }

                if ($exception->reason === 'stock_count_out_of_scope') {
                    $this->counts->addProducts($count, $user, [(int) $row['entry']['product_id']]);
                    $this->counts->recordEntry($count, $user, $row['entry']);
                    $saved++;

                    continue;
                }

                $failed[] = "Baris {$row['row']}: {$exception->getMessage()}";
            }
        }

        return ['saved' => $saved, 'failed' => $failed];
    }

    /**
     * @return array{0: ?Product, 1: ?ProductUnit}
     */
    private function findProduct(string $sku, string $barcode): array
    {
        if ($sku !== '' && ($product = Product::withTrashed()->where('sku', $sku)->first())) {
            return [$product, null];
        }

        if ($barcode === '') {
            return [null, null];
        }

        if ($product = Product::query()->where('barcode', $barcode)->first()) {
            return [$product, null];
        }

        $unit = ProductUnit::query()->where('barcode', $barcode)->whereHas('product')->first();

        return [$unit?->product, $unit];
    }

    private function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return is_numeric($value) ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString() : Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
