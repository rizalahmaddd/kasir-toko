<?php

namespace App\Livewire\Pharmacy;

use App\Enums\PrescriptionStatus;
use App\Livewire\Concerns\WithCrudActions;
use App\Models\Prescription;
use App\Models\Product;
use App\Services\Pos\PosException;
use App\Services\Pos\PrescriptionService;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app', ['heading' => 'Resep'])]
#[Title('Resep')]
class Prescriptions extends Component
{
    use WithCrudActions, WithFileUploads;

    #[Url(as: 'status')]
    public string $statusFilter = 'open';

    public string $prescription_date = '';

    public string $doctor_name = '';

    public string $doctor_sip = '';

    public string $clinic_name = '';

    public string $patient_name = '';

    public string $patient_age = '';

    public string $patient_phone = '';

    public string $patient_address = '';

    public string $customer_id = '';

    public string $notes = '';

    public bool $verifyNow = false;

    /**
     * @var list<array{product_id: string, product_name: string, quantity: string, iteration: string, dosage_instructions: string}>
     */
    public array $items = [];

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public function mount(): void
    {
        if (request()->boolean('create') && $this->canManage()) {
            $this->openCreateModal();
        }
    }

    public function canManage(): bool
    {
        return auth()->user()->can('pharmacy.prescription.manage');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function addItem(): void
    {
        if (count($this->items) < 30) {
            $this->items[] = ['product_id' => '', 'product_name' => '', 'quantity' => '', 'iteration' => '0', 'dosage_instructions' => ''];
        }
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /**
     * Memilih obat dari katalog mengisi nama dan aturan pakai bawaannya.
     */
    public function updatedItems(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if ($field !== 'product_id' || ! isset($this->items[$index])) {
            return;
        }

        $product = $value ? Product::query()->find((int) $value) : null;

        if ($product) {
            $this->items[$index]['product_name'] = $product->name;
            $this->items[$index]['dosage_instructions'] = $this->items[$index]['dosage_instructions'] ?: (string) ($product->custom_attributes['default_dosage'] ?? '');
        }
    }

    /**
     * Obat yang bisa dipilih di resep: produk yang punya golongan obat atau wajib resep.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function productOptions(): Collection
    {
        return Product::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNotNull('drug_class')->orWhere('requires_prescription', true))
            ->orderBy('name')
            ->limit(1000)
            ->get(['id', 'name', 'unit']);
    }

    public function save(PrescriptionService $prescriptions): void
    {
        $this->authorizeManage();

        $validated = $this->validate();
        $user = auth()->user();
        $items = collect($validated['items'])->map(fn (array $item) => [
            'product_id' => $item['product_id'] !== '' && $item['product_id'] !== null ? (int) $item['product_id'] : null,
            'product_name' => $item['product_name'],
            'quantity' => str_replace(',', '.', (string) $item['quantity']),
            'iteration' => (int) ($item['iteration'] ?? 0),
            'dosage_instructions' => $item['dosage_instructions'] ?? null,
        ])->all();
        $header = [...$validated, 'customer_id' => $validated['customer_id'] ?: null];

        try {
            if ($this->editingId) {
                $prescription = $prescriptions->update(Prescription::query()->findOrFail($this->editingId), $header, $items);

                if ($this->verifyNow && $user->can('pharmacy.prescription.verify')) {
                    $prescriptions->verify($prescription, $user);
                }
            } else {
                $prescription = $prescriptions->create($user, $header, $items, null, null, $this->verifyNow && $user->can('pharmacy.prescription.verify'));
            }

            if ($this->photo) {
                $prescriptions->replaceImage($prescription, $this->photo);
            }
        } catch (PosException $exception) {
            $this->addError('items', $exception->getMessage());

            return;
        }

        $this->closeModal();
        $this->notify("Resep {$prescription->number} tersimpan.");
    }

    /**
     * Resep tidak dihapus (bisa jadi bukti pemeriksaan); "hapus" di daftar berarti membatalkan.
     */
    public function delete(): void
    {
        $this->authorizeManage();
        $prescription = Prescription::query()->findOrFail($this->confirmingDeleteId);

        try {
            app(PrescriptionService::class)->cancel($prescription);
        } catch (PosException $exception) {
            $this->notify($exception->getMessage(), 'error');

            return;
        } finally {
            $this->confirmingDeleteId = null;
            $this->dispatch('close-modal', 'confirm-delete');
        }

        $this->notify("Resep {$prescription->number} dibatalkan.");
    }

    public function render()
    {
        $query = Prescription::query()
            ->withCount('items')
            ->search($this->search)
            ->when($this->statusFilter === 'open', fn (Builder $query) => $query->open())
            ->when(in_array($this->statusFilter, array_column(PrescriptionStatus::cases(), 'value'), true), fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->when($this->statusFilter === 'unverified', fn (Builder $query) => $query->open()->whereNull('verified_at'));

        $this->applySorting($query, ['number' => 'number', 'prescription_date' => 'prescription_date', 'patient_name' => 'patient_name'], 'prescription_date', 'desc');

        return view('livewire.pharmacy.prescriptions', [
            'prescriptions' => $query->orderByDesc('id')->paginate($this->perPage),
        ]);
    }

    protected function modelClass(): string
    {
        return Prescription::class;
    }

    protected function resetForm(): void
    {
        $this->reset(['doctor_name', 'doctor_sip', 'clinic_name', 'patient_name', 'patient_age', 'patient_phone', 'patient_address', 'customer_id', 'notes', 'verifyNow', 'items', 'photo']);
        $this->prescription_date = today()->toDateString();
        $this->addItem();
    }

    protected function fillForm($record): void
    {
        abort_unless($record->status === PrescriptionStatus::Pending, 403, 'Resep yang sudah ditebus atau dibatalkan tidak bisa diubah.');

        $this->prescription_date = $record->prescription_date->toDateString();
        $this->doctor_name = $record->doctor_name;
        $this->doctor_sip = (string) $record->doctor_sip;
        $this->clinic_name = (string) $record->clinic_name;
        $this->patient_name = $record->patient_name;
        $this->patient_age = (string) $record->patient_age;
        $this->patient_phone = (string) $record->patient_phone;
        $this->patient_address = (string) $record->patient_address;
        $this->customer_id = (string) $record->customer_id;
        $this->notes = (string) $record->notes;
        $this->items = $record->items()->get()->map(fn ($item) => [
            'product_id' => (string) $item->product_id,
            'product_name' => $item->product_name,
            'quantity' => rtrim(rtrim((string) $item->quantity_prescribed, '0'), '.'),
            'iteration' => (string) $item->iteration,
            'dosage_instructions' => (string) $item->dosage_instructions,
        ])->all();
    }

    protected function rules(): array
    {
        return [
            'prescription_date' => ['required', 'date', 'before_or_equal:today'],
            'doctor_name' => ['required', 'string', 'max:100'],
            'doctor_sip' => ['nullable', 'string', 'max:50'],
            'clinic_name' => ['nullable', 'string', 'max:150'],
            'patient_name' => ['required', 'string', 'max:100'],
            'patient_age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'patient_phone' => ['nullable', 'string', 'max:30'],
            'patient_address' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', TenantRule::exists('customers', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['nullable', TenantRule::exists('products', 'id')],
            'items.*.product_name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:150'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.iteration' => ['nullable', 'integer', 'min:0', 'max:10'],
            'items.*.dosage_instructions' => ['nullable', 'string', 'max:150'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'items.required' => 'Isi minimal satu obat.',
            'items.*.product_name.required_without' => 'Pilih obat dari katalog atau tulis namanya.',
            'items.*.quantity.required' => 'Isi jumlah obat.',
            'items.*.quantity.gt' => 'Jumlah harus lebih dari 0.',
            'prescription_date.before_or_equal' => 'Tanggal resep tidak boleh di masa depan.',
        ];
    }
}
