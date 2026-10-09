<?php

namespace App\Http\Controllers\Api\V1\Outlets;

use App\Enums\StoreType;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Outlets\OutletRequest;
use App\Http\Requests\Api\V1\Outlets\UpdateOutletSettingsRequest;
use App\Http\Resources\V1\Outlets\OutletResource;
use App\Http\Resources\V1\Outlets\OutletSettingsResource;
use App\Http\Resources\V1\Outlets\OutletUserResource;
use App\Models\Outlet;
use App\Models\User;
use App\Services\BusinessCapabilities;
use App\Services\OutletService;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\OutletFeatures;
use App\Support\OutletSettings;
use App\Support\StorePresets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

#[ApiTag('Outlet', 'Outlet', 'Cabang toko. Kirim outlet yang sedang dipakai lewat header `X-Outlet-Id` di semua endpoint kasir, stok, dan laporan. Menambah outlet dibatasi paket (422 pada field `name`); pengelolaan butuh izin `outlets.manage`.')]
class OutletController extends Controller
{
    /**
     * Daftar outlet.
     *
     * Secara bawaan hanya outlet aktif yang boleh dipakai akun ini (untuk pemilih outlet), terurut
     * outlet utama dulu lalu prioritas. `scope=all` menampilkan semua outlet termasuk yang nonaktif
     * beserta jumlah penggunanya, khusus akun berizin `outlets.view`.
     */
    #[ApiQuery('scope', description: '`all` untuk daftar pengelolaan.', enum: ['all'])]
    #[ApiResponse(OutletResource::class, collection: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $manage = $request->query('scope') === 'all';

        if ($manage) {
            abort_unless($request->user()->can('outlets.view'), 403);
        }

        $outlets = Outlet::query()
            ->when(! $manage, fn ($query) => $query->whereIn('id', app(CurrentOutlet::class)->accessibleIds()))
            ->when($manage, fn ($query) => $query->withCount('users'))
            ->byPriority()
            ->get();

        return OutletResource::collection($outlets);
    }

    /**
     * Detail outlet.
     *
     * Berisi `user_ids`: akun yang ditugaskan ke outlet ini (pemilik dan akun "semua outlet" tidak
     * perlu ditugaskan).
     */
    public function show(Request $request, Outlet $outlet): OutletResource
    {
        abort_unless($request->user()->can('outlets.view'), 403);

        return new OutletResource($outlet->loadCount('users')->load('users'));
    }

    /**
     * Tambah outlet.
     *
     * Ditolak 422 pada `name` bila batas outlet paket tercapai. `copy_from_outlet_id` menyalin
     * pajak, metode bayar, struk, dan harga khusus dari outlet lain sebagai titik awal. Tanpa
     * `store_type`, jenis usaha dan fitur mengikuti outlet sumber atau outlet utama. Dengan `store_type`
     * (mis. outlet apotek di toko kelontong), preset jenis itu diterapkan khusus ke outlet ini:
     * kategori contoh hanya tampil di outlet ini, `capabilities` (bawaan: saran preset) hanya menyala
     * di outlet ini, dan pajak toko tidak berubah.
     */
    #[ApiResponse(OutletResource::class, status: 201)]
    public function store(OutletRequest $request, OutletService $service)
    {
        $data = $request->validated();
        $preset = isset($data['store_type']) ? [
            'store_type' => StoreType::from($data['store_type']),
            'include_sample_products' => (bool) ($data['include_sample_products'] ?? true),
            'capabilities' => $data['capabilities'] ?? null,
        ] : null;
        $outlet = $service->create($data, $request->user(), isset($data['copy_from_outlet_id']) ? (int) $data['copy_from_outlet_id'] : null, $preset);

        return $this->created(new OutletResource($outlet));
    }

    /**
     * Ubah outlet.
     *
     * Kode tidak bisa diubah setelah outlet dipakai bertransaksi. `store_type` yang berbeda menerapkan
     * preset jenis itu secara menambah: kategori dan fitur baru dinyalakan, yang lama tetap ada.
     */
    public function update(OutletRequest $request, Outlet $outlet, OutletService $service): OutletResource
    {
        $data = $request->validated();
        $outlet = $service->update($outlet, $data);

        if (! empty($data['store_type'])) {
            $service->changeStoreType($outlet, StoreType::from($data['store_type']));
        }

        return new OutletResource($outlet);
    }

    /**
     * Fitur usaha outlet.
     *
     * `capabilities` = fitur khusus usaha yang menyala di outlet ini (mis. resep, batch & kedaluwarsa).
     * `disabled_features` = fitur kasir yang dimatikan khusus outlet ini (`pos.receivables`,
     * `pos.customer-display`). Daftar fitur level toko (`enabled_features` di `auth/me`) menjadi
     * gabungan semua outlet.
     */
    public function updateCapabilities(Request $request, Outlet $outlet, BusinessCapabilities $capabilities): OutletResource
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'capabilities' => ['present', 'array'],
            'capabilities.*' => [Rule::in(Features::optInFeatures())],
            'disabled_features' => ['sometimes', 'array'],
            'disabled_features.*' => [Rule::in(StorePresets::MANAGED_FEATURES)],
        ]);

        $capabilities->syncOutlet($outlet, $data['capabilities']);

        if (array_key_exists('disabled_features', $data)) {
            OutletFeatures::setDisabled($outlet, $data['disabled_features']);
        }

        return new OutletResource($outlet->fresh());
    }

    /**
     * Hapus outlet.
     *
     * Hanya outlet tanpa riwayat transaksi, shift, atau mutasi stok. Selain itu nonaktifkan.
     */
    public function destroy(Request $request, Outlet $outlet, OutletService $service): Response
    {
        $this->authorizeManage($request);
        $service->delete($outlet, $request->user());

        return response()->noContent();
    }

    /**
     * Jadikan outlet utama.
     *
     * Saat jumlah outlet melebihi batas paket, outlet yang beroperasi hanya bisa diganti sekali per 30 hari.
     */
    public function primary(Request $request, Outlet $outlet, OutletService $service): OutletResource
    {
        $this->authorizeManage($request);

        return new OutletResource($service->setPrimary($outlet, $request->user()));
    }

    /**
     * Aktifkan atau nonaktifkan outlet.
     *
     * Outlet utama dan outlet dengan shift terbuka tidak bisa dinonaktifkan; mengaktifkan kembali
     * mengikuti batas paket.
     */
    public function active(Request $request, Outlet $outlet, OutletService $service): OutletResource
    {
        $this->authorizeManage($request);
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        return new OutletResource($active ? $service->activate($outlet, $request->user()) : $service->deactivate($outlet, $request->user()));
    }

    /**
     * Urutan prioritas outlet.
     *
     * Menentukan outlet mana yang tetap beroperasi bila batas paket lebih kecil dari jumlah outlet.
     */
    #[ApiResponse(OutletResource::class, collection: true)]
    public function priorities(Request $request, OutletService $service): AnonymousResourceCollection
    {
        $this->authorizeManage($request);
        $data = $request->validate(['outlet_ids' => ['required', 'array', 'min:1'], 'outlet_ids.*' => ['integer']]);

        $service->setPriorities($data['outlet_ids'], $request->user());

        return OutletResource::collection(Outlet::query()->withCount('users')->byPriority()->get());
    }

    /**
     * Akun dan akses outletnya.
     *
     * Semua akun toko beserta status aksesnya ke outlet ini, untuk layar pengaturan akses.
     */
    #[ApiResponse(OutletUserResource::class, collection: true)]
    public function accessList(Request $request, Outlet $outlet): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('outlets.view'), 403);

        $assigned = DB::table('outlet_user')->where('outlet_id', $outlet->id)->pluck('user_id')->all();
        $users = User::query()->orderBy('name')->get()
            ->reject(fn (User $user) => $user->isPlatformAdmin())
            ->each(fn (User $user) => $user->setAttribute('assigned', in_array($user->id, $assigned, true)))
            ->values();

        return OutletUserResource::collection($users);
    }

    /**
     * Atur akun yang boleh memakai outlet.
     *
     * `user_ids` menggantikan penugasan outlet ini. Akun terbatas tidak boleh kehilangan outlet terakhirnya.
     */
    public function users(Request $request, Outlet $outlet, OutletService $service): OutletResource
    {
        $this->authorizeManage($request);
        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['integer']]);

        $service->syncOutletUsers($outlet, $data['user_ids']);

        return new OutletResource($outlet->loadCount('users')->load('users'));
    }

    /**
     * Salin pengaturan dan harga dari outlet lain.
     *
     * Menggantikan pajak, metode bayar, struk, QRIS, dan harga khusus outlet ini dengan milik outlet sumber.
     * `include_business: true` ikut menyamakan jenis usaha, fitur khusus, dan fitur kasir yang dimatikan.
     */
    public function copy(Request $request, Outlet $outlet, OutletService $service): OutletResource
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'source_outlet_id' => ['required', 'integer', Rule::exists('outlets', 'id')->where('tenant_id', $outlet->tenant_id), Rule::notIn([$outlet->id])],
            'include_business' => ['sometimes', 'boolean'],
        ]);

        $service->copyConfiguration(Outlet::query()->findOrFail($data['source_outlet_id']), $outlet, (bool) ($data['include_business'] ?? false));

        return new OutletResource($outlet->fresh());
    }

    /**
     * Pengaturan outlet.
     *
     * Pajak, layanan, metode bayar, struk, QRIS, aturan kasir (`rules`: kasbon, stok minus, uang cepat), dan
     * aturan apotek (`pharmacy`). Bagian `inherit: true` mengikuti pengaturan toko.
     */
    public function settings(Request $request, Outlet $outlet): OutletSettingsResource
    {
        abort_unless($request->user()->can('outlets.view'), 403);

        return new OutletSettingsResource(OutletSettings::sections($outlet->id));
    }

    /**
     * Ubah pengaturan outlet.
     *
     * Kirim hanya bagian yang berubah. `inherit: true` pada suatu bagian menghapus penimpaannya.
     */
    public function updateSettings(UpdateOutletSettingsRequest $request, Outlet $outlet): OutletSettingsResource
    {
        OutletSettings::applySections($outlet->id, $request->validated());

        return new OutletSettingsResource(OutletSettings::sections($outlet->id));
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('outlets.manage'), 403);
    }
}
