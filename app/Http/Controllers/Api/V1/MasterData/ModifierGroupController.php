<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\MasterData\ModifierGroupResource;
use App\Models\ModifierGroup;
use App\Services\ModifierGroupService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

#[ApiTag('Pilihan Tambahan', 'Master Data', 'Grup pilihan tambahan (modifier) yang ditanyakan kasir, mis. ukuran atau level gula. Aktif bila kapabilitas `business.modifiers` menyala.')]
class ModifierGroupController extends Controller
{
    /**
     * Daftar grup pilihan.
     */
    #[ApiQuery('search', description: 'Cari nama grup.')]
    #[ApiResponse(ModifierGroupResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $records = ModifierGroup::query()
            ->with('modifiers')
            ->withCount('products')
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ModifierGroupResource::collection($records);
    }

    public function show(ModifierGroup $modifierGroup): ModifierGroupResource
    {
        Gate::authorize('view-master-data');

        return new ModifierGroupResource($modifierGroup->load('modifiers', 'products')->loadCount('products'));
    }

    /**
     * Tambah grup pilihan.
     *
     * `options` daftar pilihan `{name, price, product_id?, ingredient_quantity?, is_active}`; `product_ids` produk yang
     * memakai grup ini (kosongkan untuk melepas semua, abaikan field ini untuk tidak mengubah).
     */
    #[ApiResponse(ModifierGroupResource::class, status: 201)]
    public function store(Request $request, ModifierGroupService $groups): ModifierGroupResource
    {
        Gate::authorize('manage-master-data');

        $group = $groups->save(null, $request->validate(ModifierGroupService::rules(), ModifierGroupService::messages()));

        return new ModifierGroupResource($group->load('modifiers', 'products')->loadCount('products'));
    }

    /**
     * Ubah grup pilihan.
     *
     * Pilihan yang punya `id` diperbarui, tanpa `id` ditambahkan, dan yang tidak dikirim lagi dihapus.
     */
    public function update(Request $request, ModifierGroup $modifierGroup, ModifierGroupService $groups): ModifierGroupResource
    {
        Gate::authorize('manage-master-data');

        $group = $groups->save($modifierGroup, $request->validate(ModifierGroupService::rules($modifierGroup->id), ModifierGroupService::messages()));

        return new ModifierGroupResource($group->load('modifiers', 'products')->loadCount('products'));
    }

    /**
     * Hapus grup pilihan.
     *
     * Grup dilepas dari semua produk; transaksi lama tetap menyimpan nama & harga pilihannya.
     */
    public function destroy(ModifierGroup $modifierGroup): Response
    {
        Gate::authorize('manage-master-data');

        return $this->deleteRecord($modifierGroup);
    }
}
