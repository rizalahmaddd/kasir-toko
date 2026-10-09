<?php

namespace App\Services\Pos;

use App\Enums\PrescriptionStatus;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Resep obat: pencatatan, verifikasi apoteker, dan pengecekan obat wajib resep saat checkout.
 * Jumlah di resep memakai satuan dasar produk, sama dengan sale_items.base_quantity.
 */
class PrescriptionService
{
    public const DISK = 'local';

    public function __construct(private DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{product_id?: ?int, product_name?: ?string, quantity: float|int|string, iteration?: ?int, dosage_instructions?: ?string}>  $items
     */
    public function create(User $user, array $data, array $items, ?UploadedFile $image = null, ?int $outletId = null, bool $verify = false): Prescription
    {
        if ($items === []) {
            throw new PosException('Isi minimal satu obat di resep.');
        }

        return DB::transaction(function () use ($user, $data, $items, $image, $outletId, $verify) {
            $prescription = Prescription::query()->create([
                ...$this->header($data),
                'outlet_id' => $outletId ?? app(CurrentOutlet::class)->idOrPrimary(),
                'number' => $this->numbers->next('RSP', 5),
                'status' => PrescriptionStatus::Pending,
                'created_by' => $user->id,
                'image_path' => $image?->store(app(CurrentTenant::class)->storagePath('prescriptions'), self::DISK),
            ]);

            $this->syncItems($prescription, $items);

            if ($verify) {
                $this->verify($prescription, $user);
            }

            return $prescription->load('items');
        });
    }

    /**
     * Ubah resep yang belum ditebus sama sekali. Setelah verifikasi isi resep tidak boleh berubah tanpa verifikasi ulang.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{product_id?: ?int, product_name?: ?string, quantity: float|int|string, iteration?: ?int, dosage_instructions?: ?string}>  $items
     */
    public function update(Prescription $prescription, array $data, array $items): Prescription
    {
        return DB::transaction(function () use ($prescription, $data, $items): Prescription {
            $locked = Prescription::query()->with('items')->whereKey($prescription->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PrescriptionStatus::Pending || $locked->items->contains(fn (PrescriptionItem $item) => (float) $item->quantity_dispensed > 0)) {
                throw new PosException('Resep yang sudah ditebus atau dibatalkan tidak bisa diubah.');
            }

            $locked->fill([...$this->header($data), 'verified_by' => null, 'verified_at' => null])->save();
            $this->syncItems($locked, $items);

            return $locked->load('items');
        });
    }

    public function verify(Prescription $prescription, User $user): Prescription
    {
        if (! $user->can('pharmacy.prescription.verify')) {
            throw new PosException('Hanya apoteker yang bisa memverifikasi resep.', 'forbidden');
        }

        if ($prescription->status === PrescriptionStatus::Cancelled) {
            throw new PosException('Resep yang dibatalkan tidak bisa diverifikasi.');
        }

        $prescription->forceFill(['verified_by' => $user->id, 'verified_at' => now()])->save();

        return $prescription;
    }

    public function cancel(Prescription $prescription): Prescription
    {
        $hasDispensed = $prescription->items()->where('quantity_dispensed', '>', 0)->exists();

        if ($hasDispensed) {
            throw new PosException('Resep yang sudah ditebus tidak bisa dibatalkan. Batalkan transaksinya dulu.');
        }

        $prescription->update(['status' => PrescriptionStatus::Cancelled]);

        return $prescription;
    }

    public function replaceImage(Prescription $prescription, ?UploadedFile $image): Prescription
    {
        $old = $prescription->image_path;
        $prescription->update(['image_path' => $image?->store(app(CurrentTenant::class)->storagePath('prescriptions'), self::DISK)]);

        if ($old) {
            Storage::disk(self::DISK)->delete($old);
        }

        return $prescription;
    }

    /**
     * Cek obat wajib resep di keranjang. Transaksi online yang melanggar ditolak; transaksi offline
     * sudah terjadi di dunia nyata, jadi diterima dan ditandai untuk ditinjau apoteker.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<string, mixed>  $data  payload checkout
     * @param  array<int, float>  $baseQuantities  indeks baris keranjang => jumlah satuan dasar
     * @return array{prescription: ?Prescription, items: array<int, int>, flags: list<string>}
     */
    public function resolveForSale(User $cashier, int $outletId, Collection $products, array $data, array $baseQuantities): array
    {
        $none = ['prescription' => null, 'items' => [], 'flags' => []];

        if (! Features::enabledAt('business.prescription', $outletId)) {
            return $none;
        }

        $offline = (bool) ($data['offline'] ?? false);
        $strict = PosSettings::prescriptionMode() === 'strict';
        $flags = [];
        $needed = [];
        $prescribed = [];

        foreach ($data['items'] as $index => $item) {
            $product = $products->get($item['product_id']);
            $needed[$product->id] = round(($needed[$product->id] ?? 0) + $baseQuantities[$index], 3);

            if ($product->requires_prescription) {
                $prescribed[$product->id] = $needed[$product->id];
            }

            $class = $product->drugClass();

            if ($class?->isControlled() && ! PosSettings::allowControlledDrugs()) {
                if (! $offline) {
                    throw new PosException("{$product->name} ({$class->label()}) tidak bisa dijual lewat kasir. Pemilik toko bisa membukanya di Pengaturan Kasir.", 'controlled_drug', ['product_ids' => [$product->id]]);
                }

                $flags[] = 'controlled_drug_sold';
            }
        }

        $prescription = $this->prescriptionFor($cashier, $outletId, $data, $prescribed, $products, $offline, $strict);

        if ($prescription === null) {
            if ($prescribed === []) {
                return [...$none, 'flags' => $flags];
            }

            $names = $products->only(array_keys($prescribed))->pluck('name')->take(3)->implode(', ');

            if ($offline) {
                return [...$none, 'flags' => [...$flags, 'prescription_unverified']];
            }

            throw new PosException($strict ? "Tautkan resep yang sudah diverifikasi untuk {$names}." : "Isi nama dokter dan pasien untuk {$names}.", 'prescription_required', ['product_ids' => array_keys($prescribed)]);
        }

        if ($strict && $prescribed !== [] && ! $prescription->isVerified()) {
            if (! $offline) {
                throw new PosException("Resep {$prescription->number} belum diverifikasi apoteker.", 'prescription_unverified', ['prescription_id' => $prescription->id]);
            }

            $flags[] = 'prescription_unverified';
        }

        $map = [];

        foreach ($needed as $productId => $quantity) {
            $prescriptionItem = $prescription->items->firstWhere('product_id', $productId);
            $isPrescribed = isset($prescribed[$productId]);

            if (! $prescriptionItem) {
                if ($isPrescribed && ! $offline) {
                    throw new PosException("{$products->get($productId)->name} tidak ada di resep {$prescription->number}.", 'prescription_exceeded');
                }

                $isPrescribed && $flags[] = 'prescription_unverified';

                continue;
            }

            if ($quantity > $prescriptionItem->remaining() + 0.0001) {
                if (! $offline) {
                    $left = NumberFormatter::quantity($prescriptionItem->remaining());

                    throw new PosException("Jumlah {$products->get($productId)->name} melebihi sisa resep {$prescription->number} (tersisa {$left} {$products->get($productId)->unit}).", 'prescription_exceeded');
                }

                $flags[] = 'prescription_unverified';
            }

            $map[$productId] = $prescriptionItem->id;
        }

        return ['prescription' => $prescription, 'items' => $map, 'flags' => array_values(array_unique($flags))];
    }

    /**
     * Catat jumlah yang diserahkan dari baris transaksi yang tertaut ke item resep.
     */
    public function dispense(Prescription $prescription, Sale $sale): void
    {
        $this->applyDispensed($prescription, $sale, 1);
    }

    /**
     * Kebalikan dispense() saat transaksi dibatalkan.
     */
    public function undispense(Sale $sale): void
    {
        $prescription = Prescription::query()->whereKey($sale->prescription_id)->lockForUpdate()->first();

        if ($prescription) {
            $this->applyDispensed($prescription, $sale, -1);
        }
    }

    private function applyDispensed(Prescription $prescription, Sale $sale, int $direction): void
    {
        $sale->loadMissing('items');
        $items = $prescription->items()->lockForUpdate()->get()->keyBy('id');

        foreach ($sale->items as $saleItem) {
            $item = $items->get($saleItem->prescription_item_id);

            if ($item) {
                $item->quantity_dispensed = max(0, round((float) $item->quantity_dispensed + $direction * $saleItem->baseQuantity(), 3));
                $item->save();
            }
        }

        if ($prescription->status === PrescriptionStatus::Cancelled) {
            return;
        }

        $status = match (true) {
            $items->every(fn (PrescriptionItem $item) => $item->remaining() <= 0) => PrescriptionStatus::Dispensed,
            $items->contains(fn (PrescriptionItem $item) => (float) $item->quantity_dispensed > 0) => PrescriptionStatus::PartiallyDispensed,
            default => PrescriptionStatus::Pending,
        };

        $prescription->update(['status' => $status]);
    }

    /**
     * Resep yang ditautkan ke transaksi: yang sudah ada (prescription_id) atau dibuat langsung dari isian kasir.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, float>  $prescribed
     * @param  Collection<int, Product>  $products
     */
    private function prescriptionFor(User $cashier, int $outletId, array $data, array $prescribed, Collection $products, bool $offline, bool $strict): ?Prescription
    {
        if (isset($data['prescription_id'])) {
            $prescription = Prescription::query()->with('items')->whereKey($data['prescription_id'])->lockForUpdate()->first();

            if ($prescription && $prescription->status !== PrescriptionStatus::Cancelled) {
                return $prescription;
            }

            if (! $offline) {
                throw new PosException('Resep yang ditautkan tidak ditemukan atau sudah dibatalkan.', 'prescription_invalid');
            }

            return null;
        }

        if (! isset($data['prescription']) || $prescribed === []) {
            return null;
        }

        $canVerify = $cashier->can('pharmacy.prescription.verify');

        if ($strict && ! $canVerify && ! $offline) {
            throw new PosException('Resep perlu diverifikasi apoteker. Simpan resepnya dulu dari tombol Resep, lalu minta apoteker memverifikasi.', 'prescription_unverified');
        }

        $items = collect($prescribed)->map(fn (float $quantity, int $productId) => [
            'product_id' => $productId,
            'product_name' => $products->get($productId)->name,
            'quantity' => $quantity,
        ])->values()->all();

        return $this->create($cashier, $data['prescription'], $items, null, $outletId, $canVerify);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        return [
            'prescription_date' => $data['prescription_date'] ?? today()->toDateString(),
            'doctor_name' => trim((string) $data['doctor_name']),
            'doctor_sip' => filled($data['doctor_sip'] ?? null) ? trim($data['doctor_sip']) : null,
            'clinic_name' => filled($data['clinic_name'] ?? null) ? trim($data['clinic_name']) : null,
            'patient_name' => trim((string) $data['patient_name']),
            'patient_age' => isset($data['patient_age']) && $data['patient_age'] !== '' ? (int) $data['patient_age'] : null,
            'patient_phone' => filled($data['patient_phone'] ?? null) ? trim($data['patient_phone']) : null,
            'patient_address' => filled($data['patient_address'] ?? null) ? trim($data['patient_address']) : null,
            'customer_id' => $data['customer_id'] ?? null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        ];
    }

    /**
     * @param  list<array{product_id?: ?int, product_name?: ?string, quantity: float|int|string, iteration?: ?int, dosage_instructions?: ?string}>  $items
     */
    private function syncItems(Prescription $prescription, array $items): void
    {
        $prescription->items()->delete();
        $products = Product::withTrashed()->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');

        foreach ($items as $item) {
            $product = isset($item['product_id']) ? $products->get($item['product_id']) : null;
            $name = trim((string) ($item['product_name'] ?? '')) ?: $product?->name;
            $quantity = round((float) $item['quantity'], 3);

            if ($name === null || $name === '' || $quantity <= 0) {
                throw new PosException('Setiap obat di resep harus punya nama dan jumlah lebih dari 0.');
            }

            $prescription->items()->create([
                'product_id' => $product?->id,
                'product_name' => mb_substr($name, 0, 150),
                'quantity_prescribed' => $quantity,
                'iteration' => (int) ($item['iteration'] ?? 0),
                'dosage_instructions' => filled($item['dosage_instructions'] ?? null) ? mb_substr(trim($item['dosage_instructions']), 0, 150) : null,
            ]);
        }
    }
}
