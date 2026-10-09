<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\DeliveryNote;
use App\Models\Modifier;
use App\Models\Outlet;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductComponent;
use App\Models\ProductOutletPrice;
use App\Models\ProductPriceTier;
use App\Models\ProductSerial;
use App\Models\ProductStock;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Scopes\OutletAccessScope;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
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
                'headers' => ['ID', 'Kode', 'Nama', 'Jenis', 'Kontak', 'Telepon', 'Email', 'Alamat', 'NPWP', 'Termin (hari)', 'Batas Kasbon', 'Aktif', 'Dibuat', 'Dihapus'],
                'count' => fn () => Customer::withTrashed()->count(),
                'rows' => fn () => Customer::withTrashed()->orderBy('id')->lazy()->map(fn (Customer $c) => [
                    $c->id, $c->code, $c->name, $c->type, $c->contact_person, $c->phone, $c->email, $c->address, $c->npwp,
                    $c->payment_term_days, $c->credit_limit, $c->is_active, $c->created_at, $c->deleted_at,
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
                'headers' => ['ID', 'Nomor', 'Waktu', 'Status', 'Shift ID', 'Kasir', 'Pelanggan ID', 'Pelanggan', 'Subtotal', 'Diskon', 'Pajak (%)', 'Pajak', 'Service', 'Tipe Pesanan', 'Meja', 'Total', 'Dibayar', 'Tunai Diterima', 'Kembalian', 'Sisa Tagihan', 'Catatan', 'Dibatalkan', 'Alasan Batal', 'Outlet'],
                'count' => fn () => Sale::query()->count(),
                'rows' => fn () => Sale::query()->with(['cashier', 'outlet', 'customer' => fn ($q) => $q->withTrashed()])->orderBy('id')->lazy()->map(fn (Sale $s) => [
                    $s->id, $s->number, $s->sold_at, $s->status, $s->cash_shift_id, $s->cashier?->name, $s->customer_id, $s->customer?->name,
                    $s->subtotal, $s->discount_amount, $s->tax_rate, $s->tax_amount, $s->service_charge_amount, $s->order_type, $s->table_label, $s->total, $s->paid_amount, $s->cash_received,
                    $s->change_amount, $s->due_amount, $s->note, $s->voided_at, $s->void_reason, $s->outlet?->name,
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
                'headers' => ['ID', 'Transaksi ID', 'Shift ID', 'Jenis', 'Metode', 'Jumlah', 'Referensi', 'Waktu', 'Kasir', 'Outlet Penerima'],
                'count' => fn () => SalePayment::query()->count(),
                'rows' => fn () => SalePayment::query()->with(['user', 'outlet'])->orderBy('id')->lazy()->map(fn (SalePayment $p) => [
                    $p->id, $p->sale_id, $p->cash_shift_id, $p->kind, $p->method, $p->amount, $p->reference, $p->paid_at, $p->user?->name, $p->outlet?->name,
                ]),
            ],
            'shift' => [
                'label' => 'Shift kasir',
                'headers' => ['ID', 'Nomor', 'Kasir', 'Dibuka', 'Kas Awal', 'Ditutup', 'Ditutup Oleh', 'Kas Seharusnya', 'Kas Dihitung', 'Selisih', 'Catatan', 'Outlet'],
                'count' => fn () => CashShift::query()->count(),
                'rows' => fn () => CashShift::query()->with(['user', 'closer', 'outlet'])->orderBy('id')->lazy()->map(fn (CashShift $s) => [
                    $s->id, $s->number, $s->user?->name, $s->opened_at, $s->opening_cash, $s->closed_at, $s->closer?->name,
                    $s->expected_cash, $s->counted_cash, $s->cash_difference, $s->closing_note, $s->outlet?->name,
                ]),
            ],
            'kas_masuk_keluar' => [
                'label' => 'Kas masuk/keluar',
                'headers' => ['ID', 'Shift ID', 'Jenis', 'Jumlah', 'Alasan', 'Waktu', 'Oleh', 'Outlet'],
                'count' => fn () => CashMovement::query()->count(),
                'rows' => fn () => CashMovement::query()->with(['user', 'outlet'])->orderBy('id')->lazy()->map(fn (CashMovement $m) => [
                    $m->id, $m->cash_shift_id, $m->type, $m->amount, $m->reason, $m->created_at, $m->user?->name, $m->outlet?->name,
                ]),
            ],
            'mutasi_stok' => [
                'label' => 'Mutasi stok',
                'headers' => ['ID', 'Produk ID', 'Produk', 'Jenis', 'Jumlah', 'Stok Sebelum', 'Stok Sesudah', 'Biaya Satuan', 'Catatan', 'Waktu', 'Oleh', 'Outlet'],
                'count' => fn () => StockMovement::query()->count(),
                'rows' => fn () => StockMovement::query()->with(['product' => fn ($q) => $q->withTrashed(), 'user', 'outlet'])->orderBy('id')->lazy()->map(fn (StockMovement $m) => [
                    $m->id, $m->product_id, $m->product?->name, $m->type, $m->quantity, $m->stock_before, $m->stock_after, $m->unit_cost,
                    $m->note, $m->created_at, $m->user?->name, $m->outlet?->name,
                ]),
            ],
            'outlet' => [
                'label' => 'Outlet',
                'headers' => ['ID', 'Kode', 'Nama', 'Alamat', 'Telepon', 'Utama', 'Aktif', 'Dibuat'],
                'count' => fn () => Outlet::query()->count(),
                'rows' => fn () => Outlet::query()->orderBy('id')->lazy()->map(fn (Outlet $o) => [$o->id, $o->code, $o->name, $o->address, $o->phone, $o->is_primary, $o->is_active, $o->created_at]),
            ],
            'stok_outlet' => [
                'label' => 'Stok per outlet',
                'headers' => ['Produk ID', 'Produk', 'Outlet', 'Stok', 'Stok Minimum'],
                'count' => fn () => ProductStock::query()->count(),
                'rows' => fn () => ProductStock::query()->with(['product', 'outlet'])->orderBy('id')->lazy()->map(fn (ProductStock $s) => [$s->product_id, $s->product?->name, $s->outlet?->name, $s->stock, $s->min_stock]),
            ],
            'harga_outlet' => [
                'label' => 'Harga khusus outlet',
                'headers' => ['Produk ID', 'Produk', 'Outlet', 'Harga Jual'],
                'count' => fn () => ProductOutletPrice::query()->count(),
                'rows' => fn () => ProductOutletPrice::query()->with(['product', 'outlet'])->orderBy('id')->lazy()->map(fn (ProductOutletPrice $p) => [$p->product_id, $p->product?->name, $p->outlet?->name, $p->price]),
            ],
            'transfer_stok' => [
                'label' => 'Transfer stok',
                'headers' => ['ID', 'Nomor', 'Dari', 'Ke', 'Status', 'Waktu', 'Catatan', 'Jumlah Produk'],
                'count' => fn () => StockTransfer::query()->count(),
                'rows' => fn () => StockTransfer::query()->with(['fromOutlet', 'toOutlet'])->withCount('items')->orderBy('id')->lazy()->map(fn (StockTransfer $t) => [$t->id, $t->number, $t->fromOutlet?->name, $t->toOutlet?->name, $t->status, $t->transferred_at, $t->note, $t->items_count]),
            ],
            'stok_opname' => [
                'label' => 'Stok opname',
                'headers' => ['ID', 'Nomor', 'Outlet', 'Status', 'Lingkup', 'Mulai', 'Selesai', 'Catatan', 'Barang Berubah', 'Nilai Kurang', 'Nilai Lebih'],
                'count' => fn () => StockCount::query()->count(),
                'rows' => fn () => StockCount::query()->with('outlet')->orderBy('id')->lazy()->map(fn (StockCount $c) => [$c->id, $c->number, $c->outlet?->name, $c->status->label(), $c->scope->label(), $c->started_at, $c->posted_at, $c->note, $c->summary['changed'] ?? null, $c->summary['shortage_value'] ?? null, $c->summary['surplus_value'] ?? null]),
            ],
            'stok_opname_barang' => [
                'label' => 'Stok opname per barang',
                'headers' => ['Opname ID', 'Produk ID', 'Produk', 'Stok Saat Dimulai', 'Stok Sistem Acuan', 'Hasil Hitung', 'Selisih', 'HPP', 'Alasan'],
                'count' => fn () => StockCountItem::query()->count(),
                'rows' => fn () => StockCountItem::query()->with('product')->orderBy('id')->lazy()->map(fn (StockCountItem $i) => [$i->stock_count_id, $i->product_id, $i->product?->name, $i->expected_qty, $i->reference_system_qty, $i->counted_qty, $i->variance_qty, $i->unit_cost, $i->reason?->label()]),
            ],
            'satuan_produk' => [
                'label' => 'Satuan jual produk',
                'headers' => ['ID', 'Produk ID', 'Produk', 'Satuan', 'Isi (satuan dasar)', 'Harga Tetap', 'Barcode', 'Default Kasir', 'Dihapus'],
                'count' => fn () => ProductUnit::withTrashed()->count(),
                'rows' => fn () => ProductUnit::withTrashed()->with('product')->orderBy('id')->lazy()->map(fn (ProductUnit $u) => [$u->id, $u->product_id, $u->product?->name, $u->name, $u->factor, $u->price, $u->barcode, $u->is_default_sale, $u->deleted_at]),
            ],
            'batch_stok' => [
                'label' => 'Batch & kedaluwarsa',
                'headers' => ['ID', 'Produk ID', 'Produk', 'Outlet', 'No. Batch', 'Kedaluwarsa', 'Jumlah', 'Harga Beli', 'Diterima', 'Asal'],
                'count' => fn () => ProductBatch::query()->count(),
                'rows' => fn () => ProductBatch::query()->with(['product', 'outlet'])->orderBy('id')->lazy()->map(fn (ProductBatch $b) => [$b->id, $b->product_id, $b->product?->name, $b->outlet?->name, $b->batch_number, $b->expires_at?->toDateString(), $b->quantity, $b->unit_cost, $b->received_at, $b->source->label()]),
            ],
            'pilihan_tambahan' => [
                'label' => 'Pilihan tambahan (modifier)',
                'headers' => ['ID', 'Grup', 'Aturan', 'Pilihan', 'Harga Tambahan', 'Bahan', 'Jumlah Bahan', 'Aktif', 'Dihapus'],
                'count' => fn () => Modifier::withTrashed()->count(),
                'rows' => fn () => Modifier::withTrashed()->with(['group', 'ingredient'])->orderBy('id')->lazy()->map(fn (Modifier $m) => [$m->id, $m->group?->name, $m->group?->ruleLabel(), $m->name, $m->price, $m->ingredient?->name, $m->ingredient_quantity, $m->is_active, $m->deleted_at]),
            ],
            'harga_grosir' => [
                'label' => 'Harga grosir',
                'headers' => ['Produk ID', 'Produk', 'Mulai Jumlah', 'Harga'],
                'count' => fn () => ProductPriceTier::query()->count(),
                'rows' => fn () => ProductPriceTier::query()->with('product')->orderBy('product_id')->orderBy('min_quantity')->lazy()->map(fn (ProductPriceTier $t) => [$t->product_id, $t->product?->name, $t->min_quantity, $t->price]),
            ],
            'komposisi_produk' => [
                'label' => 'Komposisi & racikan',
                'headers' => ['Produk ID', 'Produk', 'Bahan ID', 'Bahan', 'Jumlah per Satuan'],
                'count' => fn () => ProductComponent::query()->count(),
                'rows' => fn () => ProductComponent::query()->with(['product', 'component'])->orderBy('product_id')->lazy()->map(fn (ProductComponent $c) => [$c->product_id, $c->product?->name, $c->component_id, $c->component?->name, $c->quantity]),
            ],
            'nomor_seri' => [
                'label' => 'Nomor seri / IMEI',
                'headers' => ['ID', 'Produk ID', 'Produk', 'Outlet', 'Nomor Seri', 'Status', 'Item Transaksi ID', 'Terjual'],
                'count' => fn () => ProductSerial::query()->count(),
                'rows' => fn () => ProductSerial::query()->with(['product', 'outlet'])->orderBy('id')->lazy()->map(fn (ProductSerial $s) => [$s->id, $s->product_id, $s->product?->name, $s->outlet?->name, $s->serial, $s->status, $s->sale_item_id, $s->sold_at]),
            ],
            'pesanan' => [
                'label' => 'Pesanan & servis',
                'headers' => ['ID', 'Nomor', 'Jenis', 'Status', 'Pelanggan', 'Telepon', 'Ambil', 'Perkiraan Total', 'DP', 'Perangkat', 'IMEI/SN', 'Keluhan', 'Catatan', 'Transaksi ID', 'Barang'],
                'count' => fn () => CustomerOrder::query()->withoutGlobalScopes([OutletAccessScope::class])->count(),
                'rows' => fn () => CustomerOrder::query()->withoutGlobalScopes([OutletAccessScope::class])->with('items')->orderBy('id')->lazy()->map(fn (CustomerOrder $o) => [
                    $o->id, $o->number, $o->type, $o->status, $o->customer_name, $o->customer_phone, $o->pickup_at, $o->estimated_total, $o->deposit,
                    $o->device, $o->device_serial, $o->complaint, $o->notes, $o->sale_id, $o->items->map(fn ($item) => "{$item->name} ({$item->quantity})")->implode('; '),
                ]),
            ],
            'surat_jalan' => [
                'label' => 'Surat jalan',
                'headers' => ['ID', 'Nomor', 'Transaksi ID', 'Penerima', 'Telepon', 'Alamat', 'Proyek', 'Sopir', 'Kendaraan', 'Status', 'Diterima'],
                'count' => fn () => DeliveryNote::query()->withoutGlobalScopes([OutletAccessScope::class])->count(),
                'rows' => fn () => DeliveryNote::query()->withoutGlobalScopes([OutletAccessScope::class])->orderBy('id')->lazy()->map(fn (DeliveryNote $d) => [$d->id, $d->number, $d->sale_id, $d->recipient, $d->phone, $d->address, $d->project, $d->driver, $d->vehicle, $d->status, $d->delivered_at]),
            ],
            // Data pasien (UU PDP): hanya ikut bila pengekspor berizin melihat resep.
            ...(auth()->user()?->can('pharmacy.prescription.view') ? ['resep' => [
                'label' => 'Resep obat',
                'headers' => ['ID', 'Nomor', 'Tanggal', 'Dokter', 'SIP', 'Klinik', 'Pasien', 'Umur', 'Telepon', 'Status', 'Diverifikasi', 'Obat'],
                'count' => fn () => Prescription::query()->count(),
                'rows' => fn () => Prescription::query()->with('items')->orderBy('id')->lazy()->map(fn (Prescription $p) => [
                    $p->id, $p->number, $p->prescription_date->toDateString(), $p->doctor_name, $p->doctor_sip, $p->clinic_name, $p->patient_name, $p->patient_age, $p->patient_phone,
                    $p->status->label(), $p->verified_at, $p->items->map(fn ($item) => "{$item->product_name} ({$item->quantity_prescribed}, ditebus {$item->quantity_dispensed})")->implode('; '),
                ]),
            ]] : []),
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
