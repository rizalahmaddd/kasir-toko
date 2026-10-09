<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StoreType;
use App\Http\Requests\Api\V1\Onboarding\ApplyStorePresetRequest;
use App\Http\Resources\V1\Auth\TenantResource;
use App\Http\Resources\V1\Onboarding\StorePresetResource;
use App\Http\Resources\V1\Onboarding\StorePresetResultResource;
use App\Services\StorePresetApplier;
use App\Support\CurrentTenant;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiTag('Persiapan Toko', 'Akun', 'Pilih jenis toko setelah mendaftar. Selama `tenant.onboarded` di `auth/me` masih false, tampilkan layar ini ke pemilik toko.')]
class OnboardingController extends Controller
{
    /**
     * Daftar preset jenis toko.
     *
     * Kategori, jumlah produk contoh, dan pengaturan kasir yang akan diterapkan tiap jenis toko.
     */
    #[ApiResponse(StorePresetResource::class, collection: true)]
    public function presets(): AnonymousResourceCollection
    {
        return StorePresetResource::collection(StoreType::cases());
    }

    /**
     * Terapkan preset.
     *
     * Hanya pemilik toko (superadmin). Membuat kategori dan produk contoh yang belum ada, mengganti
     * pengaturan kasir, menyalakan kapabilitas usaha (`capabilities`; tanpa field ini memakai bawaan
     * preset), lalu menandai persiapan toko selesai. Setelah selesai, preset hanya bisa
     * diterapkan lagi selama toko belum punya transaksi penjualan (422 di `store_type`).
     */
    public function apply(ApplyStorePresetRequest $request, StorePresetApplier $applier): StorePresetResultResource
    {
        $tenant = app(CurrentTenant::class)->get();

        $result = $this->attempt(fn () => $applier->apply(
            $tenant,
            StoreType::from($request->validated('store_type')),
            $request->boolean('include_sample_products', true),
            $request->input('categories'),
            $request->input('settings'),
            $request->has('capabilities') ? (array) $request->input('capabilities', []) : null,
        ), 'store_type');

        return new StorePresetResultResource([...$result, 'tenant' => $tenant->refresh()]);
    }

    /**
     * Lewati persiapan toko.
     *
     * Hanya pemilik toko (superadmin). Menandai persiapan selesai tanpa membuat data apa pun.
     */
    public function skip(Request $request, StorePresetApplier $applier): TenantResource
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $tenant = app(CurrentTenant::class)->get();
        $applier->skip($tenant);

        return new TenantResource($tenant->refresh());
    }
}
