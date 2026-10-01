<?php

namespace Database\Seeders;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Support\CustomerDisplaySettings;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Katalog toko contoh beserta riwayat penjualan seminggu terakhir, supaya dashboard, laporan,
 * dan rekap shift langsung terisi saat dicoba. Hanya untuk pengembangan/demo.
 */
class PosDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * @var array<string, list<array{0: string, 1: int, 2: int, 3: string, 4: float, 5?: float}>>
     */
    private const CATALOG = [
        'Minuman' => [
            ['Air Mineral 600ml', 2500, 4000, 'btl', 120],
            ['Teh Botol Kotak 250ml', 3200, 5000, 'pcs', 60],
            ['Kopi Susu Kaleng 240ml', 6500, 9000, 'kaleng', 36],
            ['Minuman Isotonik 500ml', 5200, 7500, 'btl', 24],
            ['Susu UHT Cokelat 200ml', 4300, 6000, 'pcs', 4],
            ['Soda Jeruk 390ml', 4800, 7000, 'btl', 18],
        ],
        'Makanan Ringan' => [
            ['Keripik Kentang 68g', 8500, 11500, 'bks', 30],
            ['Biskuit Cokelat 120g', 7200, 10000, 'bks', 22],
            ['Wafer Vanila 50g', 1700, 2500, 'pcs', 80],
            ['Kacang Atom 140g', 6000, 8500, 'bks', 0],
            ['Roti Tawar Kupas', 13500, 17000, 'bks', 8, 5],
        ],
        'Sembako' => [
            ['Beras Premium 5kg', 68000, 76000, 'karung', 15, 3],
            ['Minyak Goreng 1L', 16500, 19000, 'btl', 40, 10],
            ['Gula Pasir Curah', 15500, 18000, 'kg', 25.5, 5],
            ['Telur Ayam', 27500, 31000, 'kg', 12, 3],
            ['Mi Instan Goreng', 2800, 3500, 'bks', 200, 40],
            ['Tepung Terigu 1kg', 11000, 13500, 'bks', 20],
        ],
        'Kebutuhan Rumah' => [
            ['Sabun Mandi Batang', 3500, 5000, 'pcs', 48],
            ['Sampo Sachet', 900, 1500, 'sachet', 150],
            ['Deterjen Bubuk 800g', 18500, 23000, 'bks', 14],
            ['Pasta Gigi 190g', 11500, 14500, 'pcs', 3, 4],
            ['Tisu Wajah 250 lembar', 13000, 16500, 'pak', 20],
        ],
        'Jasa & Lainnya' => [
            ['Isi Ulang Galon', 4000, 7000, 'galon', -1],
            ['Kantong Belanja Besar', 300, 1000, 'pcs', -1],
        ],
    ];

    public function run(): void
    {
        $sequence = 1;

        foreach (array_keys(self::CATALOG) as $order => $categoryName) {
            $category = Category::firstOrCreate(['name' => $categoryName], ['sort_order' => $order + 1, 'is_active' => true]);

            foreach (self::CATALOG[$categoryName] as $item) {
                [$name, $cost, $price, $unit, $stock] = $item;
                $sku = 'BRG-'.str_pad((string) $sequence++, 4, '0', STR_PAD_LEFT);

                $existing = Product::withTrashed()->where('sku', $sku)->first();

                if ($existing) {
                    if (! $existing->image_path) {
                        $existing->update(['image_path' => $this->publishImage('products/'.Str::slug($name).'.jpg')]);
                    }

                    continue;
                }

                $tracked = $stock >= 0;
                $product = Product::create([
                    'category_id' => $category->id,
                    'sku' => $sku,
                    'barcode' => $tracked ? '899'.str_pad((string) crc32($name) % 10000000000, 10, '0', STR_PAD_LEFT) : null,
                    'name' => $name,
                    'unit' => $unit,
                    'cost_price' => $cost,
                    'price' => $price,
                    'image_path' => $this->publishImage('products/'.Str::slug($name).'.jpg'),
                    'track_stock' => $tracked,
                    'stock' => 0,
                    'min_stock' => $item[5] ?? ($tracked ? 6 : 0),
                    'is_active' => true,
                ]);

                if ($tracked && $stock > 0) {
                    app(StockService::class)->move($product, StockMovementType::Initial, $stock > 10 ? $stock + 30 : $stock, null, null, 'Stok awal', $cost);
                }
            }
        }

        $this->seedCustomerDisplay();

        $cashier = User::role('kasir')->first();

        if ($cashier && ! $cashier->cashShifts()->exists()) {
            $this->seedSalesHistory($cashier);
        }
    }

    /**
     * Gambar demo (foto Unsplash) disimpan di repo supaya seeder tetap jalan tanpa internet,
     * lalu disalin ke disk public dengan path yang sama.
     */
    private function publishImage(string $path): ?string
    {
        $source = database_path('seeders/images/'.$path);

        if (! is_file($source)) {
            return null;
        }

        Storage::disk('public')->put($path, file_get_contents($source));

        return $path;
    }

    /**
     * Diisi hanya kalau slideshow masih kosong, supaya pengaturan yang sudah diubah lewat UI tidak tertimpa.
     */
    private function seedCustomerDisplay(): void
    {
        if (CustomerDisplaySettings::slides() !== []) {
            return;
        }

        $slides = collect(glob(database_path('seeders/images/display/*.jpg')) ?: [])
            ->sort()
            ->map(fn (string $file) => $this->publishImage('display/'.basename($file)))
            ->filter()
            ->values()
            ->all();

        Setting::putMany([
            'display.enabled' => '1',
            'display.theme' => 'dark',
            'display.welcome' => 'Selamat datang di toko kami! Semua kebutuhan harian ada di sini.',
            'display.promo_text' => 'Hemat minggu ini: Minyak Goreng 1L Rp19.000 • Beli 2 Kopi Susu Kaleng cuma Rp17.000 • Bayar pakai QRIS atau transfer di semua kasir • Isi ulang galon Rp7.000',
            'display.show_items' => '1',
            'display.thank_you_seconds' => '6',
            'display.slides' => json_encode($slides),
            'display.slide_seconds' => '8',
        ]);
    }

    /**
     * Enam hari terakhir: satu shift per hari, belasan transaksi, lalu shift ditutup. Hari ini sengaja
     * dibiarkan tanpa shift supaya alur "buka shift" bisa dicoba.
     */
    private function seedSalesHistory(User $cashier): void
    {
        $shifts = app(ShiftService::class);
        $sales = app(SaleService::class);
        $products = Product::query()->where('is_active', true)->get();
        $customers = Customer::query()->where('is_active', true)->limit(5)->get();
        mt_srand(2026);

        $today = today();

        foreach (range(6, 1) as $daysAgo) {
            $day = $today->copy()->subDays($daysAgo);
            Carbon::setTestNow($day->copy()->setTime(7, 30));
            $shift = $shifts->open($cashier, 200000);

            $count = mt_rand(9, 18);
            for ($i = 0; $i < $count; $i++) {
                Carbon::setTestNow($day->copy()->setTime(8, 0)->addMinutes(intdiv(12 * 60, $count) * $i + mt_rand(0, 20)));

                $picked = $products->filter(fn (Product $product) => ! $product->track_stock || (float) $product->fresh()->stock > 3)->random(mt_rand(1, 4));
                $items = $picked->map(fn (Product $product) => [
                    'product_id' => $product->id,
                    'quantity' => $product->unit === 'kg' ? mt_rand(1, 4) / 2 : mt_rand(1, 3),
                    'price' => $product->price,
                    'discount' => 0,
                ])->values()->all();

                $total = collect($items)->sum(fn (array $item) => (int) round($item['price'] * $item['quantity']));
                $roll = mt_rand(1, 10);
                $customer = null;

                if ($roll <= 6) {
                    $tendered = (int) (ceil($total / 10000) * 10000);
                    $payments = [['method' => 'cash', 'amount' => $tendered]];
                } elseif ($roll <= 8) {
                    $payments = [['method' => 'qris', 'amount' => $total]];
                } elseif ($roll === 9) {
                    $payments = [['method' => 'transfer', 'amount' => $total, 'reference' => 'TRF'.mt_rand(1000, 9999)]];
                } else {
                    $customer = $customers->random();
                    $payments = [['method' => 'cash', 'amount' => (int) (floor($total / 2 / 1000) * 1000)]];
                }

                $sales->checkout($cashier, [
                    'client_uuid' => (string) Str::uuid(),
                    'customer_id' => $customer?->id,
                    'items' => $items,
                    'payments' => $payments,
                ]);
            }

            Carbon::setTestNow($day->copy()->setTime(20, 45));
            $expected = $shift->summary()['expected'];
            $shifts->close($shift, $daysAgo === 3 ? $expected - 2000 : $expected, $cashier, $daysAgo === 3 ? 'Kurang Rp2.000, kemungkinan salah kembalian.' : null);
        }

        Carbon::setTestNow();
    }
}
