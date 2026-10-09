<?php

namespace App\Http\Controllers;

use App\Models\Prescription;
use App\Services\Pos\PrescriptionService;
use App\Support\CurrentOutlet;
use App\Support\OutletIdentity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto resep (disk privat, bukan URL publik) dan dokumen cetak farmasi. Setiap akses tercatat di log aktivitas.
 */
class PrescriptionController extends Controller
{
    public function image(Request $request, Prescription $prescription): StreamedResponse
    {
        abort_unless($prescription->image_path && Storage::disk(PrescriptionService::DISK)->exists($prescription->image_path), 404);

        activity('pharmacy')->performedOn($prescription)->causedBy($request->user())->event('downloaded')
            ->log("Foto resep {$prescription->number} dibuka.");

        return Storage::disk(PrescriptionService::DISK)->response($prescription->image_path, "resep-{$prescription->number}.".pathinfo($prescription->image_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Salinan resep (copy resep) untuk sisa obat yang belum ditebus.
     */
    public function copy(Request $request, Prescription $prescription): View
    {
        $prescription->load(['items', 'outlet', 'verifier']);
        app(CurrentOutlet::class)->set($prescription->outlet_id);

        activity('pharmacy')->performedOn($prescription)->causedBy($request->user())->event('exported')
            ->log("Salinan resep {$prescription->number} dicetak.");

        return view('print.prescription-copy', [
            'prescription' => $prescription,
            'identity' => OutletIdentity::for($prescription->outlet),
            'pharmacist' => $request->user(),
        ]);
    }

    /**
     * Etiket aturan pakai per obat (kertas thermal), bisa dibatasi ke satu item lewat ?item=.
     */
    public function labels(Request $request, Prescription $prescription): View
    {
        $prescription->load(['items', 'outlet']);
        app(CurrentOutlet::class)->set($prescription->outlet_id);

        $items = $prescription->items->when($request->integer('item'), fn ($items, int $id) => $items->where('id', $id))->values();
        abort_if($items->isEmpty(), 404);

        return view('print.prescription-labels', [
            'prescription' => $prescription,
            'items' => $items,
            'identity' => OutletIdentity::for($prescription->outlet),
            'pharmacist' => $request->user(),
            'width' => in_array($request->query('width'), ['58', '80'], true) ? $request->query('width') : '58',
        ]);
    }
}
