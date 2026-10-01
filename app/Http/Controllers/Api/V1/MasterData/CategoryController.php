<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\MasterData\CategoryRequest;
use App\Http\Resources\V1\MasterData\CategoryResource;
use App\Models\Category;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

#[ApiTag('Kategori', 'Master Data')]
class CategoryController extends Controller
{
    /**
     * Daftar kategori.
     *
     * Urut `sort_order` lalu nama, sama dengan urutan tab kategori di kasir.
     */
    #[ApiQuery('search', description: 'Cari nama.')]
    #[ApiQuery('is_active', 'boolean', 'Hanya yang aktif (true) / nonaktif (false).')]
    #[ApiResponse(CategoryResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $records = Category::query()
            ->withCount('products')
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', "%{$request->search}%"))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return CategoryResource::collection($records);
    }

    /**
     * Detail kategori.
     */
    public function show(Category $category): CategoryResource
    {
        Gate::authorize('view-master-data');

        return new CategoryResource($category->loadCount('products'));
    }

    /**
     * Tambah kategori.
     */
    #[ApiResponse(CategoryResource::class, status: 201)]
    public function store(CategoryRequest $request): CategoryResource
    {
        return new CategoryResource(Category::create($request->validated()));
    }

    /**
     * Ubah kategori.
     */
    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($request->validated());

        return new CategoryResource($category);
    }

    /**
     * Hapus kategori.
     *
     * Produk di dalamnya tidak ikut terhapus, kategorinya dikosongkan.
     */
    public function destroy(Category $category): Response
    {
        Gate::authorize('manage-master-data');

        return $this->deleteRecord($category);
    }
}
