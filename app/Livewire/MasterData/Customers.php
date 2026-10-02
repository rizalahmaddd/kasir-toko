<?php

namespace App\Livewire\MasterData;

use App\Livewire\Concerns\WithCrudActions;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Customer;
use App\Services\DocumentNumberGenerator;
use App\Support\TenantRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Pelanggan'])]
#[Title('Pelanggan')]
class Customers extends Component
{
    use WithCrudActions, WithRealtimeRefresh;

    public string $code = '';

    public string $name = '';

    public string $type = '';

    public string $contact_person = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $npwp = '';

    public string $payment_term_days = '0';

    public bool $is_active = true;

    public function save(DocumentNumberGenerator $numbers): void
    {
        $this->authorizeManage();

        $this->code = strtoupper(trim($this->code));
        $validated = $this->validate();
        $validated['code'] = $validated['code'] ?: $numbers->next('PLG', 4);
        $isEditing = (bool) $this->editingId;

        if ($isEditing) {
            Customer::findOrFail($this->editingId)->update($validated);
        } else {
            Customer::create($validated);
        }

        $this->closeModal();
        $this->resetPage();
        $this->notify($isEditing ? __('Pelanggan diperbarui.') : __('Pelanggan ditambahkan.'));
    }

    public function export(string $format = 'xlsx')
    {
        $query = Customer::query()
            ->when($this->search, function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%");
            });

        $this->applySorting($query, [
            'code' => 'code',
            'name' => 'name',
            'type' => 'type',
            'contact_person' => 'contact_person',
            'payment_term_days' => 'numeric',
            'is_active' => 'is_active',
        ], 'name', 'asc');

        $headers = ['Kode', 'Nama Pelanggan', 'Jenis/Tipe', 'PIC', 'Telepon', 'Email', 'Termin (Hari)', 'Status'];

        $rows = $query->get()->map(fn (Customer $c) => [
            $c->code,
            $c->name,
            $c->type ?: '-',
            $c->contact_person ?: '-',
            $c->phone ?: '-',
            $c->email ?: '-',
            $c->payment_term_days,
            $c->is_active ? 'Aktif' : 'Nonaktif',
        ]);

        return $this->exportFormattedResponse('pelanggan', $headers, $rows, 'Master Data Pelanggan', 'Seluruh data pelanggan terdaftar', $format);
    }

    public function render()
    {
        $query = Customer::query()
            ->withSum(['sales as due' => fn ($query) => $query->completed()], 'due_amount')
            ->when($this->search, function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%");
            });

        $this->applySorting($query, [
            'code' => 'code',
            'name' => 'name',
            'type' => 'type',
            'contact_person' => 'contact_person',
            'payment_term_days' => 'numeric',
            'due' => 'due',
            'is_active' => 'is_active',
        ], 'name', 'asc');

        return view('livewire.master-data.customers', [
            'customers' => $query->paginate($this->perPage),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return ['customer.changed'];
    }

    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function guardDelete($record): ?string
    {
        return $record->deletionBlockedReason();
    }

    protected function resetForm(): void
    {
        $this->reset(['code', 'name', 'type', 'contact_person', 'phone', 'email', 'address', 'npwp']);
        $this->payment_term_days = '0';
        $this->is_active = true;
    }

    protected function fillForm($record): void
    {
        $this->code = $record->code;
        $this->name = $record->name;
        $this->type = (string) $record->type;
        $this->contact_person = (string) $record->contact_person;
        $this->phone = (string) $record->phone;
        $this->email = (string) $record->email;
        $this->address = (string) $record->address;
        $this->npwp = (string) $record->npwp;
        $this->payment_term_days = (string) $record->payment_term_days;
        $this->is_active = $record->is_active;
    }

    protected function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50', TenantRule::unique('customers', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'payment_term_days' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
