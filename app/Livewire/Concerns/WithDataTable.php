<?php

namespace App\Livewire\Concerns;

use App\Models\Setting;
use App\Support\Branding;
use App\Support\ExportNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trait reusable untuk tabel data modern:
 * - Server-side column sorting dengan toggle asc/desc
 * - Paginasi fleksibel dengan kontrol per-page (10, 25, 50, 100)
 * - Helper export CSV / Excel-ready ber-encoding UTF-8 BOM
 */
trait WithDataTable
{
    use WithPagination;

    public string $sortField = 'id';

    public string $sortDirection = 'desc';

    public int $perPage = 10;

    /**
     * Ubah kolom pengurutan data tabel.
     */
    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            // Default pengurutan untuk tanggal / nominal adalah desc, lainnya asc
            $this->sortDirection = in_array($field, ['created_at', 'invoice_date', 'contract_date', 'order_date', 'delivery_date', 'due_date', 'total_amount', 'paid_amount', 'remaining_amount', 'target_quota', 'shipped_quantity', 'quantity', 'balance', 'min_stock', 'capacity_ton', 'payment_term_days'], true) ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    /**
     * Terapkan sorting ke Eloquent Query Builder dengan validasi ketat,
     * penanganan numerik (CAST AS DECIMAL) dan subquery relasi.
     *
     * @param  Builder  $query
     * @param  array<string, string|\Closure|Expression>  $sortMap
     * @return Builder
     */
    protected function applySorting($query, array $sortMap = [], ?string $defaultField = null, string $defaultDirection = 'desc')
    {
        $direction = strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';

        if (array_key_exists($this->sortField, $sortMap)) {
            $handler = $sortMap[$this->sortField];

            if ($handler instanceof \Closure) {
                $handler($query, $direction);

                return $query;
            }

            if ($handler === 'numeric') {
                return $query->orderByRaw("CAST({$this->sortField} AS DECIMAL(16,4)) {$direction}");
            }

            if (is_string($handler) && (str_contains($handler, 'CAST') || str_contains($handler, '-') || str_contains($handler, '+') || str_contains($handler, '*'))) {
                $handlerSql = str_replace('AS REAL', 'AS DECIMAL(16,4)', $handler);

                return $query->orderByRaw("{$handlerSql} {$direction}");
            }

            return $query->orderBy($handler, $direction);
        }

        if ($defaultField) {
            $defDirection = strtolower($defaultDirection) === 'asc' ? 'asc' : 'desc';
            if (array_key_exists($defaultField, $sortMap)) {
                $handler = $sortMap[$defaultField];
                if ($handler instanceof \Closure) {
                    $handler($query, $defDirection);

                    return $query;
                }
                if ($handler === 'numeric') {
                    return $query->orderByRaw("CAST({$defaultField} AS DECIMAL(16,4)) {$defDirection}");
                }
                if (is_string($handler) && (str_contains($handler, 'CAST') || str_contains($handler, '-') || str_contains($handler, '+') || str_contains($handler, '*'))) {
                    $handlerSql = str_replace('AS REAL', 'AS DECIMAL(16,4)', $handler);

                    return $query->orderByRaw("{$handlerSql} {$defDirection}");
                }

                return $query->orderBy($handler, $defDirection);
            }

            return $query->orderBy($defaultField, $defDirection);
        }

        return $query->latest('id');
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function updatingSortField(): void
    {
        $this->resetPage();
    }

    public function updatingSortDirection(): void
    {
        $this->resetPage();
    }

    /**
     * Ekspor multi-format (CSV, XLSX, PDF) otomatis berdasarkan format yang diminta.
     *
     * @param  string  $filenameBase  Nama dasar berkas (tanpa ekstensi), misal 'pelanggan'
     * @param  array<string>  $headers  Daftar label kolom
     * @param  iterable<array<mixed>>  $rows  Baris data yang akan diekspor (seluruhnya tanpa paginasi)
     * @param  string  $title  Judul dokumen laporan
     * @param  string|null  $subtitle  Subjudul atau rentang filter
     * @param  string  $format  Format yang dipilih ('csv', 'xlsx', atau 'pdf')
     */
    protected function exportFormattedResponse(string $filenameBase, array $headers, iterable $rows, string $title, ?string $subtitle = null, string $format = 'xlsx'): StreamedResponse
    {
        $timestamp = now()->format('Ymd-His');
        $format = strtolower($format);

        activity('export')
            ->event('exported')
            ->withProperties([
                'component' => static::class,
                'file' => $filenameBase,
                'format' => $format,
                'title' => $title,
                'subtitle' => $subtitle,
            ])
            ->log("Mengekspor {$title} (".strtoupper($format).')'.($subtitle ? ", {$subtitle}" : '').'.');

        return match ($format) {
            'pdf' => $this->exportPdfResponse("{$filenameBase}-{$timestamp}.pdf", $headers, $rows, $title, $subtitle),
            'csv' => $this->exportCsvResponse("{$filenameBase}-{$timestamp}.csv", $headers, $rows),
            default => $this->exportXlsxResponse("{$filenameBase}-{$timestamp}.xlsx", $headers, $rows, $title),
        };
    }

    /**
     * Streaming download Excel (.xlsx) dengan PhpSpreadsheet asli.
     *
     * @param  string  $filename  Nama file unduhan
     * @param  array<string>  $headers  Daftar label kolom
     * @param  iterable<array<mixed>>  $rows  Baris data
     * @param  string|null  $title  Judul sheet / judul atas
     */
    protected function exportXlsxResponse(string $filename, array $headers, iterable $rows, ?string $title = null): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $title) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(substr($title ?? 'Data', 0, 31));

            $currentRow = 1;

            if ($title) {
                $sheet->setCellValue('A1', $title);
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('047857'));
                $sheet->setCellValue('A2', 'Waktu Ekspor: '.now()->translatedFormat('d F Y H:i:s'));
                $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('64748B');
                $currentRow = 4;
            }

            // Header kolom
            $colIndex = 1;
            foreach ($headers as $header) {
                $coord = Coordinate::stringFromColumnIndex($colIndex).$currentRow;
                $sheet->setCellValue($coord, $header);
                $colIndex++;
            }

            $lastColLetter = Coordinate::stringFromColumnIndex(count($headers));
            $headerRange = "A{$currentRow}:{$lastColLetter}{$currentRow}";

            $sheet->getStyle($headerRange)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 10,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '047857'], // Emerald 700
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
            $sheet->getRowDimension($currentRow)->setRowHeight(24);

            $numericColumns = ExportNumber::numericColumns($headers);

            foreach ($numericColumns as $index => $isNumeric) {
                if ($isNumeric) {
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($index + 1).$currentRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            }

            $currentRow++;

            // Data baris
            $startDataRow = $currentRow;
            foreach ($rows as $row) {
                $colIndex = 1;
                foreach (array_values((array) $row) as $index => $val) {
                    $coord = Coordinate::stringFromColumnIndex($colIndex).$currentRow;
                    $number = ($numericColumns[$index] ?? false) ? ExportNumber::parse($val) : null;

                    if ($number !== null) {
                        // Angka asli supaya bisa dijumlah/difilter di Excel.
                        $sheet->setCellValueExplicit($coord, $number['value'], DataType::TYPE_NUMERIC);
                        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(ExportNumber::excelFormat($number));
                        $sheet->getStyle($coord)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    } else {
                        // Selalu teks: nilai dari input user yang diawali "=" tidak boleh jadi rumus Excel.
                        $sheet->setCellValueExplicit($coord, (string) ($val ?? ''), DataType::TYPE_STRING);
                    }

                    $colIndex++;
                }
                $currentRow++;
            }

            $lastDataRow = max($startDataRow, $currentRow - 1);
            $dataRange = "A{$startDataRow}:{$lastColLetter}{$lastDataRow}";

            $sheet->getStyle($dataRange)->applyFromArray([
                'font' => ['size' => 9],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'E2E8F0'],
                    ],
                ],
            ]);

            // Auto size columns
            for ($i = 1; $i <= count($headers); $i++) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ]);
    }

    /**
     * Streaming download PDF menggunakan Barryvdh\DomPDF.
     *
     * @param  string  $filename  Nama file unduhan
     * @param  array<string>  $headers  Daftar label kolom
     * @param  iterable<array<mixed>>  $rows  Baris data
     * @param  string  $title  Judul dokumen
     * @param  string|null  $subtitle  Subjudul / informasi filter
     */
    protected function exportPdfResponse(string $filename, array $headers, iterable $rows, string $title, ?string $subtitle = null): StreamedResponse
    {
        $rowsArray = is_array($rows) ? $rows : iterator_to_array($rows);

        $pdf = Pdf::loadView('print.pdf-export', [
            'title' => $title,
            'subtitle' => $subtitle,
            'companyName' => Branding::companyName(),
            'companyTagline' => Setting::get('company_tagline', ''),
            'companyAddress' => Setting::get('company_address', ''),
            'companyPhone' => Setting::get('company_phone', ''),
            'headers' => $headers,
            'rows' => $rowsArray,
            'numericColumns' => ExportNumber::numericColumns($headers),
            'exportedAt' => now()->translatedFormat('d F Y, H:i:s'),
            'user' => auth()->user(),
        ]);

        // Gunakan landscape jika kolom lebih dari 4 agar muat rapi
        if (count($headers) > 4) {
            $pdf->setPaper('a4', 'landscape');
        } else {
            $pdf->setPaper('a4', 'portrait');
        }

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ]);
    }

    /**
     * Streaming download CSV dengan BOM UTF-8 (kompatibel langsung dengan Excel tanpa scrambling).
     *
     * @param  string  $filename  Nama file unduhan, misal 'pelanggan-2026-09-23.csv'
     * @param  array<string>  $headers  Daftar label header kolom CSV
     * @param  iterable<array<mixed>>  $rows  Baris data yang akan ditulis
     */
    protected function exportCsvResponse(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');

            // Tulis BOM UTF-8 agar Microsoft Excel otomatis mendeteksi encoding UTF-8
            fwrite($handle, "\xEF\xBB\xBF");

            // Header kolom
            fputcsv($handle, $headers);

            // Baris data
            foreach ($rows as $row) {
                $sanitizedRow = array_map(function ($value) {
                    if (is_null($value)) {
                        return '';
                    }

                    $str = (string) $value;

                    // Cegah CSV Formula Injection jika nilai dimulai dengan =, +, -, @, kecuali angka
                    // negatif hasil format laporan (mis. "-1.250.000,00") yang aman dan harus tetap angka.
                    if (strlen($str) > 0 && in_array($str[0], ['=', '+', '-', '@'], true) && ! preg_match('/^-[\d.,]+$/', $str)) {
                        return "'".$str;
                    }

                    return $str;
                }, (array) $row);

                fputcsv($handle, $sanitizedRow);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ]);
    }
}
