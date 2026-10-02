<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentTenant;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use ZipArchive;

/**
 * Seluruh data milik toko aktif sebagai ZIP berisi satu CSV per jenis data, supaya pemilik toko
 * bisa menyimpan salinan sendiri atau pindah layanan. Nilai ditulis mentah (angka tanpa format,
 * tanggal "Y-m-d H:i:s") agar bisa diolah ulang; data yang dihapus lunak ikut disertakan.
 */
class TenantDataExporter
{
    public function __construct(private CurrentTenant $currentTenant) {}

    /**
     * File ZIP sementara; pemanggil yang menghapusnya setelah dikirim.
     */
    public function export(): string
    {
        // Tanpa tenant aktif global scope tidak memfilter apa pun, jadi ekspor berisi data semua toko.
        if ($this->currentTenant->id() === null) {
            throw new LogicException('Ekspor data hanya bisa dijalankan untuk tenant yang aktif.');
        }

        $zipPath = sys_get_temp_dir().'/tenant-export-'.Str::uuid().'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Berkas ekspor tidak bisa dibuat.');
        }

        $csvFiles = [];

        try {
            foreach ($this->datasets() as $name => ['headers' => $headers, 'rows' => $rows]) {
                $csvPath = $csvFiles[] = $this->writeCsv($headers, $rows());
                $zip->addFile($csvPath, "{$name}.csv");
            }

            $zip->close();
        } finally {
            foreach ($csvFiles as $csvPath) {
                @unlink($csvPath);
            }
        }

        return $zipPath;
    }

    /**
     * @return array<string, array{label: string, headers: list<string>, count: Closure(): int, rows: Closure(): iterable<list<mixed>>}>
     */
    public function datasets(): array
    {
        $tenantId = $this->currentTenant->id();

        return [
            'produk' => [
                'label' => 'Produk',
                'headers' => ['ID', 'SKU', 'Barcode', 'Nama', 'Kategori ID', 'Kategori', 'Satuan', 'Harga Pokok', 'Harga Jual', 'Lacak Stok', 'Stok', 'Stok Minimum', 'Aktif', 'Dibuat', 'Dihapus'],
                'count' => fn () => Product::withTrashed()->count(),
                'rows' => fn () => Product::withTrashed()->with('category')->orderBy('id')->lazy()->map(fn (Product $p) => [
                    $p->id, $p->sku, $p->barcode, $p->name, $p->category_id, $p->category?->name, $p->unit, $p->cost_price, $p->price,
                    $p->track_stock, $p->stock, $p->min_stock, $p->is_active, $p->created_at, $p->deleted_at,
                ]),
            ],
            'kategori' => [
                'label' => 'Kategori',
                'headers' => ['ID', 'Nama', 'Urutan', 'Aktif', 'Dibuat'],
                'count' => fn () => Category::query()->count(),
                'rows' => fn () => Category::query()->orderBy('id')->lazy()->map(fn (Category $c) => [$c->id, $c->name, $c->sort_order, $c->is_active, $c->created_at]),
            ],
            'pelanggan' => [
                'label' => 'Pelanggan',
                'headers' => ['ID', 'Kode', 'Nama', 'Jenis', 'Kontak', 'Telepon', 'Email', 'Alamat', 'NPWP', 'Termin (hari)', 'Aktif', 'Dibuat', 'Dihapus'],
                'count' => fn () => Customer::withTrashed()->count(),
                'rows' => fn () => Customer::withTrashed()->orderBy('id')->lazy()->map(fn (Customer $c) => [
                    $c->id, $c->code, $c->name, $c->type, $c->contact_person, $c->phone, $c->email, $c->address, $c->npwp,
                    $c->payment_term_days, $c->is_active, $c->created_at, $c->deleted_at,
                ]),
            ],
            'pengguna' => [
                'label' => 'Pengguna',
                'headers' => ['ID', 'Nama', 'Username', 'Email', 'Nomor HP', 'Peran', 'Dibuat'],
                'count' => fn () => User::query()->count(),
                'rows' => fn () => User::query()->with('roles')->orderBy('id')->lazy()->map(fn (User $u) => [
                    $u->id, $u->name, $u->username, $u->email, $u->phone, $u->roles->pluck('name')->join(', '), $u->created_at,
                ]),
            ],
            'transaksi' => [
                'label' => 'Transaksi',
                'headers' => ['ID', 'Nomor', 'Waktu', 'Status', 'Shift ID', 'Kasir', 'Pelanggan ID', 'Pelanggan', 'Subtotal', 'Diskon', 'Pajak (%)', 'Pajak', 'Total', 'Dibayar', 'Tunai Diterima', 'Kembalian', 'Sisa Tagihan', 'Catatan', 'Dibatalkan', 'Alasan Batal'],
                'count' => fn () => Sale::query()->count(),
                'rows' => fn () => Sale::query()->with(['cashier', 'customer' => fn ($q) => $q->withTrashed()])->orderBy('id')->lazy()->map(fn (Sale $s) => [
                    $s->id, $s->number, $s->sold_at, $s->status, $s->cash_shift_id, $s->cashier?->name, $s->customer_id, $s->customer?->name,
                    $s->subtotal, $s->discount_amount, $s->tax_rate, $s->tax_amount, $s->total, $s->paid_amount, $s->cash_received,
                    $s->change_amount, $s->due_amount, $s->note, $s->voided_at, $s->void_reason,
                ]),
            ],
            'transaksi_item' => [
                'label' => 'Item transaksi',
                'headers' => ['ID', 'Transaksi ID', 'Produk ID', 'Nama Produk', 'SKU', 'Satuan', 'Jumlah', 'Harga', 'Harga Pokok', 'Diskon', 'Total', 'Catatan'],
                'count' => fn () => SaleItem::query()->count(),
                'rows' => fn () => SaleItem::query()->orderBy('id')->lazy()->map(fn (SaleItem $i) => [
                    $i->id, $i->sale_id, $i->product_id, $i->product_name, $i->sku, $i->unit, $i->quantity, $i->price, $i->cost_price,
                    $i->discount_amount, $i->total, $i->note,
                ]),
            ],
            'pembayaran' => [
                'label' => 'Pembayaran',
                'headers' => ['ID', 'Transaksi ID', 'Shift ID', 'Jenis', 'Metode', 'Jumlah', 'Referensi', 'Waktu', 'Kasir'],
                'count' => fn () => SalePayment::query()->count(),
                'rows' => fn () => SalePayment::query()->with('user')->orderBy('id')->lazy()->map(fn (SalePayment $p) => [
                    $p->id, $p->sale_id, $p->cash_shift_id, $p->kind, $p->method, $p->amount, $p->reference, $p->paid_at, $p->user?->name,
                ]),
            ],
            'shift' => [
                'label' => 'Shift kasir',
                'headers' => ['ID', 'Nomor', 'Kasir', 'Dibuka', 'Kas Awal', 'Ditutup', 'Ditutup Oleh', 'Kas Seharusnya', 'Kas Dihitung', 'Selisih', 'Catatan'],
                'count' => fn () => CashShift::query()->count(),
                'rows' => fn () => CashShift::query()->with(['user', 'closer'])->orderBy('id')->lazy()->map(fn (CashShift $s) => [
                    $s->id, $s->number, $s->user?->name, $s->opened_at, $s->opening_cash, $s->closed_at, $s->closer?->name,
                    $s->expected_cash, $s->counted_cash, $s->cash_difference, $s->closing_note,
                ]),
            ],
            'kas_masuk_keluar' => [
                'label' => 'Kas masuk/keluar',
                'headers' => ['ID', 'Shift ID', 'Jenis', 'Jumlah', 'Alasan', 'Waktu', 'Oleh'],
                'count' => fn () => CashMovement::query()->count(),
                'rows' => fn () => CashMovement::query()->with('user')->orderBy('id')->lazy()->map(fn (CashMovement $m) => [
                    $m->id, $m->cash_shift_id, $m->type, $m->amount, $m->reason, $m->created_at, $m->user?->name,
                ]),
            ],
            'mutasi_stok' => [
                'label' => 'Mutasi stok',
                'headers' => ['ID', 'Produk ID', 'Produk', 'Jenis', 'Jumlah', 'Stok Sebelum', 'Stok Sesudah', 'Biaya Satuan', 'Catatan', 'Waktu', 'Oleh'],
                'count' => fn () => StockMovement::query()->count(),
                'rows' => fn () => StockMovement::query()->with(['product' => fn ($q) => $q->withTrashed(), 'user'])->orderBy('id')->lazy()->map(fn (StockMovement $m) => [
                    $m->id, $m->product_id, $m->product?->name, $m->type, $m->quantity, $m->stock_before, $m->stock_after, $m->unit_cost,
                    $m->note, $m->created_at, $m->user?->name,
                ]),
            ],
            'pengaturan' => [
                'label' => 'Pengaturan toko',
                'headers' => ['Kunci', 'Nilai'],
                'count' => fn () => Setting::query()->where('tenant_id', $tenantId)->count(),
                'rows' => fn () => Setting::query()->where('tenant_id', $tenantId)->orderBy('key')->lazy()->map(fn (Setting $s) => [$s->key, $s->value]),
            ],
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     */
    private function writeCsv(array $headers, iterable $rows): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'export-csv-');
        $handle = fopen($path, 'w');

        // BOM supaya Excel membaca UTF-8 (nama dengan huruf non-ASCII) dengan benar.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map($this->cell(...), $row), escape: '');
        }

        fclose($handle);

        return $path;
    }

    private function cell(mixed $value): string|int|float|null
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? 1 : 0,
            is_scalar($value), $value === null => $value,
            default => (string) json_encode($value),
        };
    }
}
