<?php

namespace App\Livewire\Settings;

use App\Enums\PaymentMethod;
use App\Models\Setting;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockCountVariance;
use App\Support\PosSettings;
use App\Support\Qris;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app', ['heading' => 'Pengaturan Kasir'])]
#[Title('Pengaturan Kasir')]
class PosSettingsPage extends Component
{
    use WithFileUploads;

    public bool $taxEnabled = false;

    public string $taxRate = '11';

    public string $taxLabel = 'PPN';

    public bool $allowNegativeStock = false;

    public bool $allowCredit = true;

    /** @var list<string> */
    public array $paymentMethods = [];

    public string $receiptWidth = '58';

    public string $receiptHeader = '';

    public string $receiptFooter = '';

    public bool $autoPrint = false;

    public string $quickCash = '';

    public string $qrisPayload = '';

    public bool $blockExpiredSale = true;

    public string $expiryWarningDays = '30';

    public string $prescriptionMode = 'strict';

    public bool $allowControlledDrugs = false;

    public string $serviceChargeRate = '0';

    public bool $serviceChargeDineInOnly = true;

    public string $photoRetentionYears = '0';

    public string $nearExpiryPercent = '0';

    public string $nearExpiryDays = '2';

    public string $qrisText = '';

    public string $opnameReasonAbove = '0';

    public string $opnameRecountPercent = '20';

    public string $opnameAlertAbove = '0';

    /** @var TemporaryUploadedFile|null */
    public $qrisImage = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.pos.manage'), 403);

        // Halaman ini mengatur nilai toko; penimpaan per outlet diatur di halaman Outlet.
        $this->taxEnabled = PosSettings::tenantValue('pos.tax_enabled') === '1';
        $this->taxRate = PosSettings::tenantValue('pos.tax_rate');
        $this->taxLabel = PosSettings::tenantValue('pos.tax_label');
        $this->allowNegativeStock = PosSettings::tenantValue('pos.allow_negative_stock') === '1';
        $this->allowCredit = PosSettings::tenantValue('pos.allow_credit') === '1';
        $methods = json_decode(PosSettings::tenantValue('pos.payment_methods'), true);
        $this->paymentMethods = array_values(array_filter(is_array($methods) ? $methods : [], fn ($method) => PaymentMethod::tryFrom((string) $method) !== null));
        $this->receiptWidth = PosSettings::tenantValue('pos.receipt_width') === '80' ? '80' : '58';
        $this->receiptHeader = PosSettings::tenantValue('pos.receipt_header');
        $this->receiptFooter = PosSettings::tenantValue('pos.receipt_footer');
        $this->autoPrint = PosSettings::tenantValue('pos.auto_print') === '1';
        $this->quickCash = implode(', ', PosSettings::parseQuickCash(PosSettings::tenantValue('pos.quick_cash')));
        $this->qrisPayload = PosSettings::tenantValue('pos.qris_payload');
        $this->blockExpiredSale = PosSettings::tenantValue('pos.block_expired_sale') === '1';
        $this->expiryWarningDays = (string) PosSettings::expiryWarningDays();
        $this->prescriptionMode = PosSettings::tenantValue('pos.prescription_mode') === 'warn' ? 'warn' : 'strict';
        $this->allowControlledDrugs = PosSettings::tenantValue('pos.allow_controlled_drugs') === '1';
        $this->serviceChargeRate = PosSettings::tenantValue('pos.service_charge_rate');
        $this->serviceChargeDineInOnly = PosSettings::tenantValue('pos.service_charge_dine_in_only') === '1';
        $this->photoRetentionYears = (string) PosSettings::prescriptionPhotoRetentionYears();
        $this->nearExpiryPercent = PosSettings::tenantValue('pos.near_expiry_discount_percent');
        $this->nearExpiryDays = PosSettings::tenantValue('pos.near_expiry_discount_days');
        $this->opnameReasonAbove = (string) (int) Setting::get(StockCountService::REASON_REQUIRED_ABOVE_KEY, '0');
        $this->opnameRecountPercent = (string) (float) Setting::get(StockCountVariance::RECOUNT_PERCENT_KEY, '20');
        $this->opnameAlertAbove = (string) (int) Setting::get(StockCountService::ALERT_ABOVE_KEY, '0');
    }

    public function updatedQrisImage(): void
    {
        abort_unless(auth()->user()->can('settings.pos.manage'), 403);

        $this->validate(['qrisImage' => ['required', 'image', 'max:5120']], ['qrisImage.image' => 'File harus berupa gambar (JPG/PNG).']);

        $payload = Qris::readImage($this->qrisImage->getRealPath());
        $this->reset('qrisImage');

        if ($payload === null) {
            $this->addError('qrisImage', 'QR tidak terbaca. Pakai foto/tangkapan layar yang tajam dan tidak terpotong, atau tempel teks QRIS-nya di bawah.');

            return;
        }

        $this->useQrisPayload($payload, 'qrisImage');
    }

    public function applyQrisText(): void
    {
        abort_unless(auth()->user()->can('settings.pos.manage'), 403);

        $this->useQrisPayload(trim($this->qrisText), 'qrisText');
    }

    private function useQrisPayload(string $payload, string $errorField): void
    {
        if ($problem = Qris::problem($payload)) {
            $this->addError($errorField, $problem);

            return;
        }

        if (Qris::isDynamic($payload)) {
            $this->addError($errorField, 'Ini QRIS dinamis sekali pakai (sudah berisi nominal). Unggah QRIS statis toko yang dipajang di meja kasir.');

            return;
        }

        $this->resetErrorBag(['qrisImage', 'qrisText']);
        $this->qrisPayload = $payload;
        $this->qrisText = '';
        $merchant = Qris::merchant($payload)['name'];
        $this->dispatch('notify', message: "QRIS {$merchant} terbaca. Tekan Simpan Pengaturan Kasir untuk memakainya.");
    }

    public function removeQris(): void
    {
        abort_unless(auth()->user()->can('settings.pos.manage'), 403);

        $this->qrisPayload = '';
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('settings.pos.manage'), 403);

        $this->taxRate = str_replace(',', '.', trim($this->taxRate));
        $this->serviceChargeRate = str_replace(',', '.', trim($this->serviceChargeRate)) ?: '0';
        $this->nearExpiryPercent = str_replace(',', '.', trim($this->nearExpiryPercent)) ?: '0';
        $this->opnameReasonAbove = preg_replace('/\D/', '', $this->opnameReasonAbove) ?: '0';
        $this->opnameAlertAbove = preg_replace('/\D/', '', $this->opnameAlertAbove) ?: '0';
        $this->opnameRecountPercent = str_replace(',', '.', trim($this->opnameRecountPercent)) ?: '0';
        $validated = $this->validate([
            'taxEnabled' => ['boolean'],
            'taxRate' => ['required_if:taxEnabled,true', 'nullable', 'numeric', 'min:0', 'max:100'],
            'taxLabel' => ['required', 'string', 'max:20'],
            'allowNegativeStock' => ['boolean'],
            'allowCredit' => ['boolean'],
            'paymentMethods' => ['array'],
            'paymentMethods.*' => [Rule::enum(PaymentMethod::class)],
            'receiptWidth' => ['required', Rule::in(['58', '80'])],
            'receiptHeader' => ['nullable', 'string', 'max:300'],
            'receiptFooter' => ['nullable', 'string', 'max:300'],
            'autoPrint' => ['boolean'],
            'quickCash' => ['nullable', 'string', 'max:200'],
            'blockExpiredSale' => ['boolean'],
            'expiryWarningDays' => ['required', 'integer', 'min:1', 'max:365'],
            'prescriptionMode' => ['required', Rule::in(array_keys(PosSettings::PRESCRIPTION_MODES))],
            'allowControlledDrugs' => ['boolean'],
            'serviceChargeRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'serviceChargeDineInOnly' => ['boolean'],
            'photoRetentionYears' => ['required', 'integer', 'min:0', 'max:30'],
            'nearExpiryPercent' => ['required', 'numeric', 'min:0', 'max:90'],
            'nearExpiryDays' => ['required', 'integer', 'min:0', 'max:60'],
            'opnameReasonAbove' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'opnameRecountPercent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'opnameAlertAbove' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);

        $quickCash = collect(preg_split('/[\s,;]+/', (string) $validated['quickCash']))
            ->map(fn (string $value) => (int) preg_replace('/\D/', '', $value))
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->sort()
            ->take(8)
            ->values();

        Setting::putMany([
            'pos.tax_enabled' => $validated['taxEnabled'] ? '1' : '0',
            'pos.tax_rate' => (string) ($validated['taxRate'] ?? '0'),
            'pos.tax_label' => $validated['taxLabel'],
            'pos.allow_negative_stock' => $validated['allowNegativeStock'] ? '1' : '0',
            'pos.allow_credit' => $validated['allowCredit'] ? '1' : '0',
            'pos.payment_methods' => json_encode(array_values(array_unique([PaymentMethod::Cash->value, ...$validated['paymentMethods']]))),
            'pos.receipt_width' => $validated['receiptWidth'],
            'pos.receipt_header' => trim((string) $validated['receiptHeader']),
            'pos.receipt_footer' => trim((string) $validated['receiptFooter']),
            'pos.auto_print' => $validated['autoPrint'] ? '1' : '0',
            'pos.quick_cash' => json_encode($quickCash->all()),
            'pos.qris_payload' => $this->qrisPayload !== '' && Qris::problem($this->qrisPayload) === null ? $this->qrisPayload : '',
            'pos.block_expired_sale' => $validated['blockExpiredSale'] ? '1' : '0',
            'pos.expiry_warning_days' => (string) $validated['expiryWarningDays'],
            'pos.prescription_mode' => $validated['prescriptionMode'],
            'pos.allow_controlled_drugs' => $validated['allowControlledDrugs'] ? '1' : '0',
            'pos.service_charge_rate' => (string) (float) $validated['serviceChargeRate'],
            'pos.service_charge_dine_in_only' => $validated['serviceChargeDineInOnly'] ? '1' : '0',
            'pharmacy.photo_retention_years' => (string) (int) $validated['photoRetentionYears'],
            'pos.near_expiry_discount_percent' => (string) (float) $validated['nearExpiryPercent'],
            'pos.near_expiry_discount_days' => (string) (int) $validated['nearExpiryDays'],
            StockCountService::REASON_REQUIRED_ABOVE_KEY => (string) (int) $validated['opnameReasonAbove'],
            StockCountVariance::RECOUNT_PERCENT_KEY => (string) (float) $validated['opnameRecountPercent'],
            StockCountService::ALERT_ABOVE_KEY => (string) (int) $validated['opnameAlertAbove'],
        ]);

        $this->quickCash = $quickCash->implode(', ');
        $this->dispatch('notify', message: 'Pengaturan kasir disimpan. Layar kasir yang sedang terbuka perlu dimuat ulang.');
    }

    public function render()
    {
        $merchant = null;
        $preview = null;

        if ($this->qrisPayload !== '' && Qris::problem($this->qrisPayload) === null) {
            $merchant = Qris::merchant($this->qrisPayload);
            $preview = Qris::svg(Qris::withAmount($this->qrisPayload, 10000));
        }

        return view('livewire.settings.pos-settings', [
            'qrisMerchant' => $merchant,
            'qrisPreview' => $preview,
            'qrisSaved' => $this->qrisPayload === PosSettings::tenantValue('pos.qris_payload'),
        ]);
    }
}
