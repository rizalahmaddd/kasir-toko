<?php

namespace App\Http\Controllers\Api\V1\Pharmacy;

use App\Enums\PrescriptionStatus;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Pharmacy\PrescriptionRequest;
use App\Http\Resources\V1\Pharmacy\PrescriptionResource;
use App\Models\Prescription;
use App\Services\Pos\PrescriptionService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[ApiTag('Resep', 'Farmasi', 'Resep dokter untuk obat wajib resep. Aktif bila kapabilitas `business.prescription` menyala. Data pasien hanya untuk izin `pharmacy.prescription.view`; setiap akses foto tercatat.')]
class PrescriptionController extends Controller
{
    /**
     * Daftar resep.
     */
    #[ApiQuery('search', description: 'Nomor resep, nama pasien, atau dokter.')]
    #[ApiQuery('status', description: '`open` (default, belum tuntas), `unverified`, `all`, atau status resep.', enum: ['open', 'unverified', 'all', 'pending', 'partially_dispensed', 'dispensed', 'cancelled'])]
    #[ApiResponse(PrescriptionResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('pharmacy.prescription.view');

        $status = (string) $request->query('status', 'open');

        $records = Prescription::query()
            ->with(['items.product', 'verifier'])
            ->search($request->query('search'))
            ->when($status === 'open', fn (Builder $query) => $query->open())
            ->when($status === 'unverified', fn (Builder $query) => $query->open()->whereNull('verified_at'))
            ->when(PrescriptionStatus::tryFrom($status), fn (Builder $query, PrescriptionStatus $value) => $query->where('status', $value->value))
            ->latest('prescription_date')
            ->latest('id')
            ->paginate($this->perPage($request));

        return PrescriptionResource::collection($records);
    }

    public function show(Prescription $prescription): PrescriptionResource
    {
        Gate::authorize('pharmacy.prescription.view');

        return new PrescriptionResource($prescription);
    }

    /**
     * Catat resep.
     *
     * Kirim sebagai multipart/form-data bila menyertakan `image` (foto resep). `verify` true langsung
     * memverifikasi bila akun ini berizin `pharmacy.prescription.verify`.
     */
    #[ApiResponse(PrescriptionResource::class, status: 201)]
    public function store(PrescriptionRequest $request, PrescriptionService $prescriptions): PrescriptionResource
    {
        $data = $request->validated();
        $user = $request->user();

        return new PrescriptionResource($prescriptions->create($user, $data, $data['items'], $request->file('image'), null, $request->boolean('verify') && $user->can('pharmacy.prescription.verify')));
    }

    /**
     * Ubah resep.
     *
     * Hanya resep yang belum ditebus; verifikasi sebelumnya dicabut dan perlu diulang.
     */
    public function update(PrescriptionRequest $request, Prescription $prescription, PrescriptionService $prescriptions): PrescriptionResource
    {
        $data = $request->validated();
        $prescription = $prescriptions->update($prescription, $data, $data['items']);

        if ($request->boolean('verify') && $request->user()->can('pharmacy.prescription.verify')) {
            $prescriptions->verify($prescription, $request->user());
        }

        return new PrescriptionResource($prescription);
    }

    /**
     * Verifikasi resep (apoteker).
     */
    public function verify(Request $request, Prescription $prescription, PrescriptionService $prescriptions): PrescriptionResource
    {
        Gate::authorize('pharmacy.prescription.verify');

        return new PrescriptionResource($prescriptions->verify($prescription, $request->user()));
    }

    /**
     * Batalkan resep.
     *
     * Ditolak (422) bila sebagian obat sudah ditebus.
     */
    public function cancel(Prescription $prescription, PrescriptionService $prescriptions): PrescriptionResource
    {
        Gate::authorize('pharmacy.prescription.manage');

        return new PrescriptionResource($prescriptions->cancel($prescription));
    }

    /**
     * Unggah foto resep.
     *
     * multipart/form-data dengan field `image`. Foto lama diganti.
     */
    public function uploadImage(Request $request, Prescription $prescription, PrescriptionService $prescriptions): PrescriptionResource
    {
        Gate::authorize('pharmacy.prescription.manage');
        $request->validate(['image' => ['required', 'image', 'max:5120']]);

        return new PrescriptionResource($prescriptions->replaceImage($prescription, $request->file('image')));
    }

    /**
     * Foto resep.
     *
     * Berkas gambar; akses tercatat di log aktivitas.
     */
    public function image(Request $request, Prescription $prescription): StreamedResponse
    {
        Gate::authorize('pharmacy.prescription.view');
        abort_unless($prescription->image_path && Storage::disk(PrescriptionService::DISK)->exists($prescription->image_path), 404);

        activity('pharmacy')->performedOn($prescription)->causedBy($request->user())->event('downloaded')
            ->log("Foto resep {$prescription->number} dibuka lewat aplikasi.");

        return Storage::disk(PrescriptionService::DISK)->response($prescription->image_path, null, ['Cache-Control' => 'private, no-store']);
    }
}
