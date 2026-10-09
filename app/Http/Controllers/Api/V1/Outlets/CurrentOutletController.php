<?php

namespace App\Http\Controllers\Api\V1\Outlets;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\Outlets\OutletResource;
use App\Models\Outlet;
use App\Support\CurrentOutlet;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[ApiTag('Outlet Aktif', 'Outlet')]
class CurrentOutletController extends Controller
{
    /**
     * Simpan outlet terakhir dipakai.
     *
     * Dipakai server sebagai outlet bawaan bila klien tidak mengirim `X-Outlet-Id`, jadi pilihan
     * ikut berpindah antar perangkat. Outlet harus bisa diakses akun ini dan tidak terkunci paket.
     */
    public function __invoke(Request $request): OutletResource
    {
        $outletId = (int) $request->validate(['outlet_id' => ['required', 'integer']])['outlet_id'];
        $current = app(CurrentOutlet::class);

        if (! $current->canAccess($outletId)) {
            throw ValidationException::withMessages(['outlet_id' => 'Anda tidak punya akses ke outlet ini.']);
        }

        if (! in_array($outletId, $current->operationalIds(), true)) {
            throw ValidationException::withMessages(['outlet_id' => 'Outlet ini terkunci oleh batas paket.']);
        }

        $request->user()->forceFill(['default_outlet_id' => $outletId])->saveQuietly();

        return new OutletResource(Outlet::query()->findOrFail($outletId));
    }
}
