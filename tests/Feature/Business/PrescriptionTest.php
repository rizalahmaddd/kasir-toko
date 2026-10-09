<?php

use App\Enums\DrugClass;
use App\Enums\PrescriptionStatus;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pos\PosException;
use App\Services\Pos\PrescriptionService;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Support\Features;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function rxSale(User $cashier, Product $product, float $quantity, array $extra = [])
{
    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'price' => $product->price]],
        'payments' => [['method' => 'cash', 'amount' => 1_000_000]],
        ...$extra,
    ]);
}

function rxRejection(callable $callback): PosException
{
    try {
        $callback();
    } catch (PosException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a rejection.');
}

function rxFor(Product $product, float $quantity, bool $verified = true): Prescription
{
    $prescription = Prescription::factory()->state($verified ? ['verified_at' => now()] : [])->create();
    $prescription->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity_prescribed' => $quantity]);

    return $prescription->load('items');
}

beforeEach(function () {
    Features::setEnabled(['business.prescription']);
    $this->cashier = actingAsRole('kasir');
    $this->antibiotic = Product::factory()->create(['name' => 'Amoxicillin', 'unit' => 'kapsul', 'price' => 800, 'stock' => 100, 'drug_class' => DrugClass::Prescription->value, 'requires_prescription' => true]);
    app(ShiftService::class)->open($this->cashier, 0);
});

test('a prescription-only drug cannot be sold without a linked prescription', function () {
    expect(rxRejection(fn () => rxSale($this->cashier, $this->antibiotic, 10))->reason)->toBe('prescription_required');
});

test('strict mode refuses an unverified prescription', function () {
    $prescription = rxFor($this->antibiotic, 15, verified: false);

    expect(rxRejection(fn () => rxSale($this->cashier, $this->antibiotic, 10, ['prescription_id' => $prescription->id]))->reason)->toBe('prescription_unverified');
});

test('a verified prescription is dispensed partly, then fully, and a void gives the quantity back', function () {
    $prescription = rxFor($this->antibiotic, 15);

    $first = rxSale($this->cashier, $this->antibiotic, 10, ['prescription_id' => $prescription->id]);
    expect($prescription->fresh()->status)->toBe(PrescriptionStatus::PartiallyDispensed)
        ->and($first->prescription_id)->toBe($prescription->id)
        ->and($first->items->sole()->prescription_item_id)->toBe($prescription->items->sole()->id);

    expect(rxRejection(fn () => rxSale($this->cashier, $this->antibiotic, 6, ['prescription_id' => $prescription->id]))->reason)->toBe('prescription_exceeded');

    rxSale($this->cashier, $this->antibiotic, 5, ['prescription_id' => $prescription->id]);
    expect($prescription->fresh()->status)->toBe(PrescriptionStatus::Dispensed);

    app(SaleService::class)->void($first, actingAsRole('admin'), 'Salah obat');
    expect($prescription->fresh()->status)->toBe(PrescriptionStatus::PartiallyDispensed)
        ->and((float) $prescription->items()->sole()->quantity_dispensed)->toBe(5.0);
});

test('warn mode accepts doctor and patient typed at the cashier', function () {
    Setting::put('pos.prescription_mode', 'warn');

    $sale = rxSale($this->cashier, $this->antibiotic, 10, ['prescription' => ['doctor_name' => 'Budi', 'patient_name' => 'Ani']]);

    $prescription = $sale->prescription;
    expect($prescription->doctor_name)->toBe('Budi')
        ->and($prescription->status)->toBe(PrescriptionStatus::Dispensed)
        ->and($prescription->isVerified())->toBeFalse();
});

test('strict mode lets only a pharmacist type a prescription at the cashier', function () {
    $payload = ['prescription' => ['doctor_name' => 'Budi', 'patient_name' => 'Ani']];

    expect(rxRejection(fn () => rxSale($this->cashier, $this->antibiotic, 10, $payload))->reason)->toBe('prescription_unverified');

    $pharmacist = User::factory()->create();
    $pharmacist->givePermissionTo(['pos.sell', 'pharmacy.prescription.verify']);
    app(ShiftService::class)->open($pharmacist, 0);

    $sale = rxSale($pharmacist, $this->antibiotic, 10, $payload);
    expect($sale->prescription->isVerified())->toBeTrue();
});

test('offline sales that break the prescription rules are kept and flagged', function () {
    $sale = rxSale($this->cashier, $this->antibiotic, 10, ['offline' => true]);

    expect($sale->fresh()->flags)->toBe(['prescription_unverified'])
        ->and((float) $this->antibiotic->fresh()->stock)->toBe(90.0);
});

test('narcotics stay blocked at the cashier until the owner allows them', function () {
    $narcotic = Product::factory()->create(['price' => 5000, 'stock' => 10, 'drug_class' => DrugClass::Narcotic->value, 'requires_prescription' => true]);
    $prescription = rxFor($narcotic, 5);

    expect(rxRejection(fn () => rxSale($this->cashier, $narcotic, 1, ['prescription_id' => $prescription->id]))->reason)->toBe('controlled_drug');

    Setting::put('pos.allow_controlled_drugs', '1');
    expect(rxSale($this->cashier, $narcotic, 1, ['prescription_id' => $prescription->id])->exists)->toBeTrue();
});

test('nothing is checked while the capability is off', function () {
    Features::setEnabled([]);

    expect(rxSale($this->cashier, $this->antibiotic, 10)->prescription_id)->toBeNull();
});

test('prescription photos are private and their access is logged', function () {
    Storage::fake(PrescriptionService::DISK);
    $prescription = app(PrescriptionService::class)->create($this->cashier, ['doctor_name' => 'Budi', 'patient_name' => 'Ani'], [['product_id' => $this->antibiotic->id, 'quantity' => 10]], UploadedFile::fake()->image('resep.jpg'));

    Storage::disk(PrescriptionService::DISK)->assertExists($prescription->image_path);

    $this->get(route('pharmacy.prescriptions.image', $prescription))->assertOk();
    $this->assertDatabaseHas('activity_log', ['subject_id' => $prescription->id, 'event' => 'downloaded']);

    $staff = actingAsRole('staff');
    $this->get(route('pharmacy.prescriptions.image', $prescription))->assertForbidden();
    $this->get(route('pharmacy.prescriptions'))->assertForbidden();
});

test('the API records, verifies, and lists prescriptions', function () {
    $user = apiActingAs('admin');
    $user->givePermissionTo('pharmacy.prescription.verify');

    $id = $this->postJson('/api/v1/pharmacy/prescriptions', [
        'doctor_name' => 'Budi',
        'patient_name' => 'Ani',
        'items' => [['product_id' => $this->antibiotic->id, 'quantity' => 15, 'dosage_instructions' => '3 x sehari 1 kapsul']],
    ])->assertCreated()->assertJsonPath('data.is_verified', false)->json('data.id');

    $this->postJson("/api/v1/pharmacy/prescriptions/{$id}/verify")->assertOk()->assertJsonPath('data.is_verified', true);

    $this->getJson('/api/v1/pharmacy/prescriptions?search=Ani')
        ->assertOk()
        ->assertJsonPath('data.0.items.0.remaining', '15.000')
        ->assertJsonPath('data.0.items.0.unit', 'kapsul');

    Features::setEnabled([]);
    $this->getJson('/api/v1/pharmacy/prescriptions')->assertForbidden();
});

test('prescription photos past the retention period are deleted while the prescription stays', function () {
    Storage::fake(PrescriptionService::DISK);
    Storage::disk(PrescriptionService::DISK)->put('resep/lama.jpg', 'x');
    Storage::disk(PrescriptionService::DISK)->put('resep/baru.jpg', 'x');
    $old = Prescription::factory()->create(['prescription_date' => today()->subYears(6), 'image_path' => 'resep/lama.jpg']);
    $recent = Prescription::factory()->create(['prescription_date' => today()->subYears(2), 'image_path' => 'resep/baru.jpg']);

    $this->artisan('pharmacy:prune-prescription-photos')->assertSuccessful();
    expect($old->fresh()->image_path)->toBe('resep/lama.jpg');

    Setting::put('pharmacy.photo_retention_years', '5');
    $this->artisan('pharmacy:prune-prescription-photos')->assertSuccessful();

    expect($old->fresh()->image_path)->toBeNull()
        ->and($recent->fresh()->image_path)->toBe('resep/baru.jpg');
    Storage::disk(PrescriptionService::DISK)->assertMissing('resep/lama.jpg');
    Storage::disk(PrescriptionService::DISK)->assertExists('resep/baru.jpg');
});
