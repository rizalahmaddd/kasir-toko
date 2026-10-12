<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WithCrudActions;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Services\Pos\SelfOrderGate;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\OutletSettings;
use App\Support\PosSettings;
use App\Support\QrCodeSvg;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Meja dan pengaturan pesanan QR milik outlet aktif. Pengaturannya disimpan sebagai penimpaan outlet,
 * jadi tiap cabang bisa buka/tutup dan memilih alurnya sendiri.
 */
#[Layout('layouts.app', ['heading' => 'Meja & QR'])]
#[Title('Meja & QR')]
class DiningTables extends Component
{
    use WithCrudActions;

    public string $name = '';

    public string $area = '';

    public string $capacity = '2';

    public string $sort_order = '0';

    public bool $is_active = true;

    public string $kitchenFlow = 'confirm';

    public string $dineInPayment = 'after';

    public string $expireMinutes = '15';

    public ?int $rotatingTableId = null;

    public function mount(): void
    {
        $this->kitchenFlow = PosSettings::selfOrderKitchenFlow();
        $this->dineInPayment = PosSettings::selfOrderDineInPayment();
        $this->expireMinutes = (string) PosSettings::selfOrderExpireMinutes();
    }

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->get() ?? abort(404);
    }

    #[Computed]
    public function featureOn(): bool
    {
        return Features::enabledAt('business.self-order', $this->outlet->id);
    }

    #[Computed]
    public function orderingOn(): bool
    {
        return PosSettings::selfOrderEnabled();
    }

    #[Computed]
    public function closedReason(): ?string
    {
        return SelfOrderGate::closedReason($this->outlet->id);
    }

    /**
     * @return array{url: string, qr: string}
     */
    #[Computed]
    public function standee(): array
    {
        $url = route('self-order.outlet', $this->outlet->selfOrderToken());

        return ['url' => $url, 'qr' => QrCodeSvg::render($url)];
    }

    public function toggleOrdering(): void
    {
        $this->authorizeManage();

        $next = ! PosSettings::selfOrderEnabled();
        OutletSettings::put($this->outlet->id, 'pos.self_order_enabled', $next ? '1' : '0');
        unset($this->orderingOn, $this->closedReason);

        $this->notify($next ? __('Pemesanan QR dibuka.') : __('Pemesanan QR ditutup.'));
    }

    public function saveSettings(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'kitchenFlow' => ['required', Rule::in(['confirm', 'auto'])],
            'dineInPayment' => ['required', Rule::in(['after', 'before'])],
            'expireMinutes' => ['required', 'integer', 'min:1', 'max:240'],
        ], attributes: ['expireMinutes' => 'batas waktu']);

        OutletSettings::putMany($this->outlet->id, [
            'pos.self_order_kitchen_flow' => $validated['kitchenFlow'],
            'pos.self_order_dine_in_payment' => $validated['dineInPayment'],
            'pos.self_order_expire_minutes' => (string) (int) $validated['expireMinutes'],
        ]);

        $this->notify(__('Pengaturan pesanan QR disimpan.'));
    }

    public function save(): void
    {
        $this->authorizeManage();

        $this->name = trim($this->name);
        $validated = $this->validate();
        $validated['area'] = $validated['area'] ?: null;
        $isEditing = (bool) $this->editingId;

        if ($isEditing) {
            DiningTable::query()->forOutlet($this->outlet->id)->findOrFail($this->editingId)->update($validated);
        } else {
            DiningTable::create([...$validated, 'outlet_id' => $this->outlet->id]);
        }

        $this->closeModal();
        $this->resetPage();
        $this->notify($isEditing ? __('Meja diperbarui.') : __('Meja ditambahkan.'));
    }

    public function confirmRotate(int $id): void
    {
        $this->authorizeManage();

        $this->rotatingTableId = $id;
        $this->dispatch('open-modal', 'rotate-token');
    }

    /**
     * QR lama langsung mati; stiker atau akrilik meja ini harus dicetak ulang.
     */
    public function rotateToken(): void
    {
        $this->authorizeManage();

        if ($this->rotatingTableId === 0) {
            $this->outlet->rotateSelfOrderToken();
            unset($this->standee);
        } elseif ($this->rotatingTableId !== null) {
            DiningTable::query()->forOutlet($this->outlet->id)->findOrFail($this->rotatingTableId)->update(['token' => DiningTable::newToken()]);
        }

        $this->rotatingTableId = null;
        $this->dispatch('close-modal', 'rotate-token');
        $this->notify(__('QR baru dibuat. Cetak ulang supaya pelanggan tidak memindai QR lama.'));
    }

    public function canManage(): bool
    {
        return auth()->user()->can('self-order.manage');
    }

    public function render()
    {
        $query = DiningTable::query()
            ->forOutlet($this->outlet->id)
            ->when($this->search, fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$this->search}%")->orWhere('area', 'like', "%{$this->search}%")));

        $this->applySorting($query, [
            'name' => 'name',
            'area' => 'area',
            'capacity' => 'numeric',
            'is_active' => 'is_active',
        ], 'name', 'asc');

        return view('livewire.settings.dining-tables', [
            'tables' => $query->paginate($this->perPage),
            'tableCount' => DiningTable::query()->forOutlet($this->outlet->id)->count(),
            'hasQris' => PosSettings::qrisPayload() !== null,
        ]);
    }

    protected function modelClass(): string
    {
        return DiningTable::class;
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'area']);
        $this->capacity = '2';
        $this->sort_order = '0';
        $this->is_active = true;
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->area = (string) $record->area;
        $this->capacity = (string) $record->capacity;
        $this->sort_order = (string) $record->sort_order;
        $this->is_active = $record->is_active;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:30', Rule::unique('dining_tables', 'name')->where('outlet_id', $this->outlet->id)->ignore($this->editingId)],
            'area' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:999'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['name.unique' => 'Nama meja ini sudah dipakai di outlet ini.'];
    }
}
