<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Enums\StockMovementType;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\MasterData\ProductImageRequest;
use App\Http\Requests\Api\V1\MasterData\ProductRequest;
use App\Http\Resources\V1\MasterData\ProductResource;
use App\Models\Product;
use App\Services\DocumentNumberGenerator;
use App\Services\Pos\StockService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

#[ApiTag('Produk', 'Master Data', 'Produk beserta harga jual, HPP, barcode, dan stok. Stok produk yang sudah ada diubah lewat endpoint penyesuaian stok, bukan lewat ubah produk.')]
class ProductController extends Controller
{
    private const SORTS = ['name', 'sku', 'price', 'cost_price', 'stock'];

    /**
     * Daftar produk.
     */
    #[ApiQuery('search', description: 'Cari nama atau SKU, atau barcode yang persis sama.')]
    #[ApiQuery('category_id', description: 'ID kategori, atau `none` untuk produk tanpa kategori.')]
    #[ApiQuery('status', description: '`active`, `inactive`, atau `low` (stok menipis).', enum: ['active', 'inactive', 'low'])]
    #[ApiQuery('sort', description: 'Kolom urutan. Awali `-` untuk menurun, mis. `-price`.', enum: ['name', '-name', 'sku', '-sku', 'price', '-price', 'cost_price', '-cost_price', 'stock', '-stock'])]
    #[ApiResponse(ProductResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $sort = ltrim((string) $request->query('sort', 'name'), '-');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'name';

        $records = Product::query()
            ->with('category')
            ->search($request->query('search'))
            ->when($request->query('category_id') === 'none', fn (Builder $query) => $query->whereNull('category_id'))
            ->when(ctype_digit((string) $request->query('category_id')), fn (Builder $query) => $query->where('category_id', (int) $request->query('category_id')))
            ->when($request->query('status') === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($request->query('status') === 'low', fn (Builder $query) => $query->lowStock())
            ->orderBy($sort, str_starts_with((string) $request->query('sort'), '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return ProductResource::collection($records);
    }

    /**
     * Detail produk.
     */
    public function show(Product $product): ProductResource
    {
        Gate::authorize('view-master-data');

        return new ProductResource($product->load('category'));
    }

    /**
     * Tambah produk.
     *
     * SKU kosong diisi otomatis (PRD-xxxxx). `stock` di sini menjadi stok awal dan tercatat di
     * kartu stok.
     */
    #[ApiResponse(ProductResource::class, status: 201)]
    public function store(ProductRequest $request, DocumentNumberGenerator $numbers, StockService $stock): ProductResource
    {
        $data = $request->validated();

        $product = DB::transaction(function () use ($data, $numbers, $stock, $request) {
            $product = Product::create([
                ...$this->attributes($data),
                'sku' => ($data['sku'] ?? null) ?: $numbers->next('PRD', 5),
                'stock' => 0,
            ]);

            $initialStock = (float) ($data['stock'] ?? 0);

            if ($product->track_stock && $initialStock != 0.0) {
                $stock->move($product, StockMovementType::Initial, $initialStock, $request->user(), null, 'Stok awal saat produk dibuat', $product->cost_price);
            }

            return $product;
        });

        return new ProductResource($product->load('category'));
    }

    /**
     * Ubah produk.
     *
     * `stock` diabaikan; pakai penyesuaian stok.
     */
    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $data = $request->validated();

        $product->update([
            ...$this->attributes($data, $product),
            'sku' => ($data['sku'] ?? null) ?: $product->sku,
        ]);

        return new ProductResource($product->load('category'));
    }

    /**
     * Hapus produk.
     *
     * Riwayat transaksi tetap menyimpan nama dan harga produk ini.
     */
    public function destroy(Product $product): Response
    {
        Gate::authorize('manage-master-data');

        return $this->deleteRecord($product);
    }

    /**
     * Unggah foto produk.
     *
     * Kirim sebagai multipart/form-data. Foto lama diganti.
     */
    public function uploadImage(ProductImageRequest $request, Product $product): ProductResource
    {
        $old = $product->image_path;
        $product->update(['image_path' => $request->file('image')->store('products', 'public')]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return new ProductResource($product->load('category'));
    }

    /**
     * Hapus foto produk.
     */
    public function deleteImage(Product $product): ProductResource
    {
        Gate::authorize('manage-master-data');

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $product->update(['image_path' => null]);
        }

        return new ProductResource($product->load('category'));
    }

    /**
     * Flags the client left out keep their current value, so an older app version cannot
     * reactivate a product by omitting `is_active`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Product $current = null): array
    {
        $trackStock = (bool) ($data['track_stock'] ?? $current?->track_stock ?? true);

        return [
            'category_id' => $data['category_id'] ?? null,
            'barcode' => filled($data['barcode'] ?? null) ? trim($data['barcode']) : null,
            'name' => $data['name'],
            'unit' => $data['unit'],
            'cost_price' => (int) ($data['cost_price'] ?? 0),
            'price' => (int) $data['price'],
            'track_stock' => $trackStock,
            'min_stock' => $trackStock ? (float) ($data['min_stock'] ?? $current?->min_stock ?? 0) : 0,
            'is_active' => (bool) ($data['is_active'] ?? $current?->is_active ?? true),
        ];
    }
}
