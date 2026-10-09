<?php

namespace App\Livewire\Settings;

use App\Enums\PaymentMethod;
use App\Enums\StoreType;
use App\Models\Outlet;
use App\Models\User;
use App\Services\BusinessCapabilities;
use App\Services\OutletService;
use App\Services\StorePresetApplier;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\OutletFeatures;
use App\Support\OutletSettings;
use App\Support\PlanLimits;
use App\Support\PosSettings;
use App\Support\Qris;
use App\Support\StorePresets;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Outlet'])]
#[Title('Outlet')]
class Outlets extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $code = '';

    public string $address = '';

    public string $phone = '';

    public string $copyFromId = '';

    public int $wizardStep = 1;

    /** Kosong = jenis usaha sama dengan outlet sumber/utama. */
    public string $storeType = '';

    public bool $includeSampleProducts = true;

    /** @var list<string> */
    public array $presetCapabilities = [];

    public string $configMode = 'inherit';

    /** @var list<int> */
    public array $newOutletUserIds = [];

    public ?int $deletingId = null;

    public ?int $accessOutletId = null;

    /** @var list<int> */
    public array $accessUserIds = [];

    public ?int $configOutletId = null;

    public bool $inheritTax = true;

    public bool $taxEnabled = false;

    public string $taxRate = '11';

    public string $taxLabel = 'PPN';

    public bool $inheritService = true;

    public string $serviceChargeRate = '0';

    public bool $serviceChargeDineInOnly = true;

    public bool $inheritPayments = true;

    /** @var list<string> */
    public array $paymentMethods = [];

    public bool $inheritReceipt = true;

    public string $receiptWidth = '58';

    public string $receiptHeader = '';

    public string $receiptFooter = '';

    public bool $autoPrint = false;

    public bool $inheritQris = true;

    public bool $inheritRules = true;

    public bool $allowCredit = false;

    public bool $allowNegativeStock = false;

    public string $quickCash = '';

    public bool $inheritPharmacy = true;

    public string $prescriptionMode = 'strict';

    public bool $allowControlledDrugs = false;

    public bool $blockExpiredSale = true;

    public string $nearExpiryPercent = '0';

    public string $nearExpiryDays = '2';

    public string $qrisPayload = '';

    public ?int $copyingToId = null;

    public ?int $capabilityOutletId = null;

    public string $outletStoreType = '';

    /** @var list<string> */
    public array $outletCapabilities = [];

    public string $copySourceId = '';

    public bool $copyBusiness = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('outlets.view'), 403);
    }

    public function canManage(): bool
    {
        return auth()->user()->can('outlets.manage');
    }

    /**
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()->withCount('users')->byPriority()->get();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function operationalIds(): array
    {
        return app(CurrentTenant::class)->get()?->operationalOutletIds() ?? [];
    }

    /**
     * @return array{used: int, max: int, plan: string, canAdd: bool}
     */
    #[Computed]
    public function quota(): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $used = PlanLimits::count('outlets');
        $max = $tenant?->maxOutlets() ?? 1;

        return ['used' => $used, 'max' => $max, 'plan' => $tenant?->planLabel() ?? '', 'canAdd' => $used < $max];
    }

    public function openCreate(): void
    {
        abort_unless($this->canManage(), 403);

        $this->resetValidation();
        $this->reset([
            'editingId', 'name', 'code', 'address', 'phone', 'copyFromId',
            'wizardStep', 'configMode', 'newOutletUserIds',
            'storeType', 'includeSampleProducts', 'presetCapabilities',
        ]);
        $this->wizardStep = 1;
        $this->configMode = $this->outlets->isNotEmpty() ? 'copy' : 'inherit';
        $this->copyFromId = (string) ($this->outlets->firstWhere('is_primary', true)?->id ?? $this->outlets->first()?->id ?? '');

        $this->inheritTax = true;
        $this->taxEnabled = false;
        $this->taxRate = '11';
        $this->taxLabel = 'PPN';

        $this->inheritService = true;
        $this->serviceChargeRate = '0';
        $this->serviceChargeDineInOnly = true;

        $this->inheritPayments = true;
        $this->paymentMethods = ['cash', 'transfer', 'qris'];

        $this->inheritReceipt = true;
        $this->receiptWidth = '58';
        $this->receiptHeader = '';
        $this->receiptFooter = '';
        $this->autoPrint = false;

        $this->inheritQris = true;
        $this->qrisPayload = '';

        $this->newOutletUserIds = [auth()->id()];

        $this->dispatch('open-modal', 'record-form');
    }

    public function openEdit(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $outlet = Outlet::query()->findOrFail($id);
        $this->resetValidation();
        $this->editingId = $outlet->id;
        $this->name = $outlet->name;
        $this->code = $outlet->code;
        $this->address = (string) $outlet->address;
        $this->phone = (string) $outlet->phone;
        $this->copyFromId = '';
        $this->dispatch('open-modal', 'record-form');
    }

    public function closeModal(): void
    {
        $this->reset([
            'editingId', 'name', 'code', 'address', 'phone', 'copyFromId',
            'wizardStep', 'configMode', 'newOutletUserIds',
        ]);
        $this->resetValidation();
        $this->dispatch('close-modal', 'record-form');
    }

    public function closeAuxModal(string $name): void
    {
        $this->resetValidation();
        $this->dispatch('close-modal', $name);
    }

    public function updatedStoreType(): void
    {
        $type = StoreType::tryFrom($this->storeType);
        $this->presetCapabilities = $type ? StorePresets::capabilities($type) : [];
        $this->includeSampleProducts = true;
    }

    public function togglePresetCapability(string $key): void
    {
        if (! in_array($key, Features::optInFeatures(), true)) {
            return;
        }

        $this->presetCapabilities = in_array($key, $this->presetCapabilities, true)
            ? array_values(array_diff($this->presetCapabilities, [$key]))
            : [...$this->presetCapabilities, $key];
    }

    /**
     * Jenis usaha yang diikuti outlet baru bila pemilik tidak memilih jenis lain.
     */
    #[Computed]
    public function baseStoreType(): ?StoreType
    {
        return $this->outlets->firstWhere('is_primary', true)?->effectiveStoreType();
    }

    /**
     * @return list<array{key: string, label: string, description: string, recommended: bool}>
     */
    #[Computed]
    public function presetCapabilityOptions(): array
    {
        return $this->capabilityOptions(StoreType::tryFrom($this->storeType));
    }

    /**
     * @return list<array{key: string, label: string, description: string, recommended: bool}>
     */
    #[Computed]
    public function outletCapabilityOptions(): array
    {
        return $this->capabilityOptions(StoreType::tryFrom($this->outletStoreType));
    }

    /**
     * @return list<array{key: string, label: string, description: string, recommended: bool}>
     */
    private function capabilityOptions(?StoreType $type): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => Features::MODULES['business']['features'][explode('.', $key, 2)[1]]['label'],
            'description' => Features::MODULES['business']['features'][explode('.', $key, 2)[1]]['description'],
            'recommended' => $type !== null && in_array($key, StorePresets::capabilities($type), true),
        ], Features::optInFeatures());
    }

    public function nextStep(): void
    {
        if ($this->wizardStep === 1) {
            $this->code = strtoupper(trim($this->code));
            $this->validate([
                'name' => OutletService::rules()['name'],
                'code' => OutletService::rules()['code'],
                'address' => OutletService::rules()['address'],
                'phone' => OutletService::rules()['phone'],
                'storeType' => ['nullable', Rule::enum(StoreType::class)],
            ], OutletService::messages());
            $this->wizardStep = 2;

            return;
        }

        if ($this->wizardStep === 2) {
            if ($this->configMode === 'copy') {
                $this->validate([
                    'copyFromId' => ['required', 'integer', TenantRule::exists('outlets', 'id')],
                ], [
                    'copyFromId.required' => 'Pilih outlet sumber untuk disalin.',
                ]);
            } elseif ($this->configMode === 'custom') {
                $this->taxRate = str_replace(',', '.', trim($this->taxRate));
                $this->serviceChargeRate = str_replace(',', '.', trim($this->serviceChargeRate)) ?: '0';

                $this->validate([
                    'taxRate' => ['required_if:taxEnabled,true', 'nullable', 'numeric', 'min:0', 'max:100'],
                    'taxLabel' => ['required', 'string', 'max:20'],
                    'serviceChargeRate' => ['required', 'numeric', 'min:0', 'max:100'],
                    'paymentMethods' => ['array'],
                    'paymentMethods.*' => [Rule::enum(PaymentMethod::class)],
                    'receiptWidth' => ['required', Rule::in(['58', '80'])],
                    'receiptHeader' => ['nullable', 'string', 'max:300'],
                    'receiptFooter' => ['nullable', 'string', 'max:300'],
                ]);
            }
            $this->wizardStep = 3;

            return;
        }

        if ($this->wizardStep === 3) {
            $this->wizardStep = 4;

            return;
        }
    }

    public function previousStep(): void
    {
        if ($this->wizardStep > 1) {
            $this->wizardStep--;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step < $this->wizardStep) {
            $this->wizardStep = max(1, $step);

            return;
        }

        if ($step > $this->wizardStep) {
            while ($this->wizardStep < $step && ! $this->getErrorBag()->isNotEmpty()) {
                $curr = $this->wizardStep;
                $this->nextStep();
                if ($this->wizardStep === $curr) {
                    break;
                }
            }
        }
    }

    public function selectAllStaff(): void
    {
        $this->newOutletUserIds = $this->accessUsers->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function clearStaffSelection(): void
    {
        $this->newOutletUserIds = [auth()->id()];
    }

    public function save(OutletService $service): void
    {
        abort_unless($this->canManage(), 403);

        $this->code = strtoupper(trim($this->code));
        $outlet = $this->editingId ? Outlet::query()->findOrFail($this->editingId) : null;
        $validated = $this->validate([
            'name' => OutletService::rules($outlet)['name'],
            'code' => OutletService::rules($outlet)['code'],
            'address' => OutletService::rules()['address'],
            'phone' => OutletService::rules()['phone'],
            'copyFromId' => ['nullable', 'integer', TenantRule::exists('outlets', 'id')],
        ], OutletService::messages());

        $copySource = null;
        if ($this->configMode === 'copy' && filled($this->copyFromId)) {
            $copySource = (int) $this->copyFromId;
        } elseif (filled($this->copyFromId) && $this->configMode !== 'custom') {
            $copySource = (int) $this->copyFromId;
        }

        try {
            if ($outlet) {
                $service->update($outlet, $validated);
            } else {
                $type = StoreType::tryFrom($this->storeType);
                $preset = $type ? [
                    'store_type' => $type,
                    'include_sample_products' => $this->includeSampleProducts,
                    'capabilities' => array_values(array_intersect($this->presetCapabilities, Features::optInFeatures())),
                ] : null;

                $newOutlet = $service->create($validated, auth()->user(), $copySource, $preset);

                if ($this->configMode === 'custom') {
                    $this->taxRate = str_replace(',', '.', trim($this->taxRate));
                    $this->serviceChargeRate = str_replace(',', '.', trim($this->serviceChargeRate)) ?: '0';
                    $this->qrisPayload = trim($this->qrisPayload);

                    OutletSettings::applySections($newOutlet->id, [
                        'tax' => ['inherit' => $this->inheritTax, 'enabled' => $this->taxEnabled, 'rate' => $this->taxRate ?: '0', 'label' => $this->taxLabel],
                        'service' => ['inherit' => $this->inheritService, 'rate' => $this->serviceChargeRate ?: '0', 'dine_in_only' => $this->serviceChargeDineInOnly],
                        'payments' => ['inherit' => $this->inheritPayments, 'methods' => $this->paymentMethods],
                        'receipt' => ['inherit' => $this->inheritReceipt, 'width' => $this->receiptWidth, 'header' => $this->receiptHeader, 'footer' => $this->receiptFooter, 'auto_print' => $this->autoPrint],
                        'qris' => ['inherit' => $this->inheritQris, 'payload' => $this->qrisPayload],
                    ]);
                }

                if (! empty($this->newOutletUserIds)) {
                    $service->syncOutletUsers($newOutlet, $this->newOutletUserIds);
                }
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->closeModal();
        $this->forgetComputed();
        $this->dispatch('notify', message: $outlet ? 'Outlet diperbarui.' : 'Outlet ditambahkan.');
    }

    public function setPrimary(int $id, OutletService $service): void
    {
        $this->run(fn () => $service->setPrimary(Outlet::query()->findOrFail($id), auth()->user()), 'Outlet utama diganti.');
    }

    public function toggleActive(int $id, OutletService $service): void
    {
        $outlet = Outlet::query()->findOrFail($id);

        $this->run(
            fn () => $outlet->is_active ? $service->deactivate($outlet, auth()->user()) : $service->activate($outlet, auth()->user()),
            $outlet->is_active ? 'Outlet dinonaktifkan.' : 'Outlet diaktifkan kembali.',
        );
    }

    public function move(int $id, string $direction, OutletService $service): void
    {
        abort_unless($this->canManage(), 403);

        $ids = $this->outlets->where('is_primary', false)->pluck('id')->all();
        $position = array_search($id, $ids, true);
        $target = $direction === 'up' ? $position - 1 : $position + 1;

        if ($position === false || ! isset($ids[$target])) {
            return;
        }

        [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];
        $primary = $this->outlets->firstWhere('is_primary', true);

        $this->run(fn () => $service->setPriorities([...($primary ? [$primary->id] : []), ...$ids], auth()->user()), null);
    }

    public function confirmDelete(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $this->deletingId = $id;
        $this->dispatch('open-modal', 'confirm-delete');
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->dispatch('close-modal', 'confirm-delete');
    }

    public function delete(OutletService $service): void
    {
        abort_unless($this->canManage(), 403);

        $outlet = Outlet::query()->find($this->deletingId);
        $this->deletingId = null;
        $this->dispatch('close-modal', 'confirm-delete');

        if ($outlet) {
            $this->run(fn () => $service->delete($outlet, auth()->user()), 'Outlet dihapus.');
        }
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function accessUsers(): Collection
    {
        return User::query()->orderBy('name')->get()->reject(fn (User $user) => $user->isPlatformAdmin())->values();
    }

    public function openAccess(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $outlet = Outlet::query()->findOrFail($id);
        $this->accessOutletId = $outlet->id;
        $this->accessUserIds = DB::table('outlet_user')->where('outlet_id', $outlet->id)->pluck('user_id')->map(fn ($userId) => (int) $userId)->all();
        $this->dispatch('open-modal', 'outlet-access');
    }

    public function saveAccess(OutletService $service): void
    {
        abort_unless($this->canManage(), 403);

        $outlet = Outlet::query()->findOrFail($this->accessOutletId);

        try {
            $changed = $service->syncOutletUsers($outlet, $this->accessUserIds);
        } catch (ValidationException $exception) {
            $this->addError('accessUserIds', $exception->errors()['user_ids'][0]);

            return;
        }

        $this->dispatch('close-modal', 'outlet-access');
        $this->dispatch('notify', message: $changed === 0 ? 'Akses tidak berubah.' : 'Akses pengguna diperbarui.');
        $this->forgetComputed();
    }

    public function openConfig(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $outlet = Outlet::query()->findOrFail($id);
        $this->resetValidation();
        $this->configOutletId = $outlet->id;
        $sections = OutletSettings::sections($outlet->id);

        $this->inheritTax = $sections['tax']['inherit'];
        $this->taxEnabled = $sections['tax']['enabled'];
        $this->taxRate = $sections['tax']['rate'];
        $this->taxLabel = $sections['tax']['label'];

        $this->inheritService = $sections['service']['inherit'];
        $this->serviceChargeRate = $sections['service']['rate'];
        $this->serviceChargeDineInOnly = $sections['service']['dine_in_only'];

        $this->inheritPayments = $sections['payments']['inherit'];
        $this->paymentMethods = $sections['payments']['methods'];

        $this->inheritReceipt = $sections['receipt']['inherit'];
        $this->receiptWidth = $sections['receipt']['width'];
        $this->receiptHeader = $sections['receipt']['header'];
        $this->receiptFooter = $sections['receipt']['footer'];
        $this->autoPrint = $sections['receipt']['auto_print'];

        $this->inheritQris = $sections['qris']['inherit'];
        $this->qrisPayload = $sections['qris']['payload'];

        $this->inheritRules = $sections['rules']['inherit'];
        $this->allowCredit = $sections['rules']['allow_credit'];
        $this->allowNegativeStock = $sections['rules']['allow_negative_stock'];
        $this->quickCash = implode(', ', $sections['rules']['quick_cash']);

        $this->inheritPharmacy = $sections['pharmacy']['inherit'];
        $this->prescriptionMode = $sections['pharmacy']['prescription_mode'];
        $this->allowControlledDrugs = $sections['pharmacy']['allow_controlled_drugs'];
        $this->blockExpiredSale = $sections['pharmacy']['block_expired_sale'];
        $this->nearExpiryPercent = $sections['pharmacy']['near_expiry_discount_percent'];
        $this->nearExpiryDays = $sections['pharmacy']['near_expiry_discount_days'];

        $this->dispatch('open-modal', 'outlet-config');
    }

    public function saveConfig(): void
    {
        abort_unless($this->canManage(), 403);

        $outlet = Outlet::query()->findOrFail($this->configOutletId);
        $this->taxRate = str_replace(',', '.', trim($this->taxRate));
        $this->serviceChargeRate = str_replace(',', '.', trim($this->serviceChargeRate)) ?: '0';
        $this->qrisPayload = trim($this->qrisPayload);

        $this->validate([
            'taxRate' => ['required_if:taxEnabled,true', 'nullable', 'numeric', 'min:0', 'max:100'],
            'taxLabel' => ['required', 'string', 'max:20'],
            'serviceChargeRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'paymentMethods' => ['array'],
            'paymentMethods.*' => [Rule::enum(PaymentMethod::class)],
            'receiptWidth' => ['required', Rule::in(['58', '80'])],
            'receiptHeader' => ['nullable', 'string', 'max:300'],
            'receiptFooter' => ['nullable', 'string', 'max:300'],
            'quickCash' => ['nullable', 'string', 'max:200'],
            'prescriptionMode' => ['required', Rule::in(array_keys(PosSettings::PRESCRIPTION_MODES))],
            'nearExpiryPercent' => ['required', 'numeric', 'min:0', 'max:90'],
            'nearExpiryDays' => ['required', 'integer', 'min:0', 'max:60'],
        ]);

        if (! $this->inheritQris && $this->qrisPayload !== '') {
            $problem = Qris::problem($this->qrisPayload) ?? (Qris::isDynamic($this->qrisPayload) ? 'Ini QRIS dinamis sekali pakai. Pakai QRIS statis outlet.' : null);

            if ($problem) {
                $this->addError('qrisPayload', $problem);

                return;
            }
        }

        OutletSettings::applySections($outlet->id, [
            'tax' => ['inherit' => $this->inheritTax, 'enabled' => $this->taxEnabled, 'rate' => $this->taxRate ?: '0', 'label' => $this->taxLabel],
            'service' => ['inherit' => $this->inheritService, 'rate' => $this->serviceChargeRate, 'dine_in_only' => $this->serviceChargeDineInOnly],
            'payments' => ['inherit' => $this->inheritPayments, 'methods' => $this->paymentMethods],
            'receipt' => ['inherit' => $this->inheritReceipt, 'width' => $this->receiptWidth, 'header' => $this->receiptHeader, 'footer' => $this->receiptFooter, 'auto_print' => $this->autoPrint],
            'qris' => ['inherit' => $this->inheritQris, 'payload' => $this->qrisPayload],
            'rules' => ['inherit' => $this->inheritRules, 'allow_credit' => $this->allowCredit, 'allow_negative_stock' => $this->allowNegativeStock, 'quick_cash' => preg_split('/[\s,;]+/', $this->quickCash) ?: []],
            'pharmacy' => ['inherit' => $this->inheritPharmacy, 'prescription_mode' => $this->prescriptionMode, 'allow_controlled_drugs' => $this->allowControlledDrugs, 'block_expired_sale' => $this->blockExpiredSale, 'near_expiry_discount_percent' => $this->nearExpiryPercent, 'near_expiry_discount_days' => $this->nearExpiryDays],
        ]);

        $this->dispatch('close-modal', 'outlet-config');
        $this->dispatch('notify', message: "Pengaturan {$outlet->name} disimpan.");
    }

    public function openCapabilities(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $outlet = Outlet::query()->findOrFail($id);
        $this->capabilityOutletId = $outlet->id;
        $this->outletStoreType = $outlet->store_type?->value ?? '';
        $this->outletCapabilities = OutletFeatures::capabilities($outlet->id);

        $this->dispatch('open-modal', 'outlet-capabilities');
    }

    public function updatedOutletStoreType(): void
    {
        $type = StoreType::tryFrom($this->outletStoreType);

        if ($type) {
            $this->outletCapabilities = array_values(array_unique([...$this->outletCapabilities, ...StorePresets::capabilities($type)]));
        }
    }

    public function toggleOutletCapability(string $key): void
    {
        if (! in_array($key, Features::optInFeatures(), true)) {
            return;
        }

        $this->outletCapabilities = in_array($key, $this->outletCapabilities, true)
            ? array_values(array_diff($this->outletCapabilities, [$key]))
            : [...$this->outletCapabilities, $key];
    }

    /**
     * Kapabilitas yang punya data dan akan mati di seluruh toko kalau dimatikan di outlet ini.
     *
     * @return list<string>
     */
    #[Computed]
    public function capabilityWarnings(): array
    {
        if ($this->capabilityOutletId === null) {
            return [];
        }

        $elsewhere = Outlet::query()->whereKeyNot($this->capabilityOutletId)->pluck('id')
            ->flatMap(fn (int $id) => OutletFeatures::capabilities($id))->unique()->all();
        $removed = array_diff(OutletFeatures::capabilities($this->capabilityOutletId), $this->outletCapabilities, $elsewhere);

        return array_values(array_intersect($removed, app(BusinessCapabilities::class)->withData()));
    }

    public function saveCapabilities(BusinessCapabilities $capabilities, StorePresetApplier $presets): void
    {
        abort_unless($this->canManage(), 403);

        $outlet = Outlet::query()->findOrFail($this->capabilityOutletId);
        $type = StoreType::tryFrom($this->outletStoreType);
        $keys = array_values(array_intersect($this->outletCapabilities, Features::optInFeatures()));

        if ($type && $type !== $outlet->store_type) {
            $presets->applyToOutlet($outlet, $type, false, null, $keys);
        } else {
            $capabilities->syncOutlet($outlet, $keys);
        }

        $this->dispatch('close-modal', 'outlet-capabilities');
        $this->forgetComputed();
        $this->dispatch('notify', message: "Fitur usaha {$outlet->name} disimpan.");
    }

    public function openCopy(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $this->copyingToId = $id;
        $this->copySourceId = '';
        $this->copyBusiness = false;
        $this->resetValidation();
        $this->dispatch('open-modal', 'outlet-copy');
    }

    public function copyConfiguration(OutletService $service): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate(['copySourceId' => ['required', 'integer', TenantRule::exists('outlets', 'id')]], ['copySourceId.required' => 'Pilih outlet sumber.']);

        $target = Outlet::query()->findOrFail($this->copyingToId);
        $source = Outlet::query()->findOrFail((int) $this->copySourceId);

        $service->copyConfiguration($source, $target, $this->copyBusiness);
        $this->forgetComputed();
        $this->dispatch('close-modal', 'outlet-copy');
        $this->dispatch('notify', message: "Pengaturan dan harga {$source->name} disalin ke {$target->name}.");
    }

    public function render()
    {
        return view('livewire.settings.outlets');
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function run(callable $action, ?string $success): void
    {
        abort_unless($this->canManage(), 403);

        try {
            $action();
        } catch (ValidationException $exception) {
            $this->dispatch('notify', message: collect($exception->errors())->flatten()->first(), type: 'error');

            return;
        }

        $this->forgetComputed();

        if ($success) {
            $this->dispatch('notify', message: $success);
        }
    }

    private function forgetComputed(): void
    {
        unset($this->outlets, $this->operationalIds, $this->quota, $this->accessUsers);
    }
}
