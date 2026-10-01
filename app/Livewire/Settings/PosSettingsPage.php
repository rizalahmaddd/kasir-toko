<?php

namespace App\Livewire\Settings;

use App\Enums\PaymentMethod;
use App\Models\Setting;
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

    public string $qrisText = '';

    /** @var TemporaryUploadedFile|null */
    public $qrisImage = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.pos.manage'), 403);

        $this->taxEnabled = PosSettings::get('pos.tax_enabled') === '1';
        $this->taxRate = PosSettings::get('pos.tax_rate');
        $this->taxLabel = PosSettings::get('pos.tax_label');
        $this->allowNegativeStock = PosSettings::allowNegativeStock();
        $this->allowCredit = PosSettings::allowCredit();
        $this->paymentMethods = collect(PosSettings::paymentMethods())->map(fn (PaymentMethod $method) => $method->value)->all();
        $this->receiptWidth = PosSettings::receiptWidth();
        $this->receiptHeader = PosSettings::get('pos.receipt_header');
        $this->receiptFooter = PosSettings::get('pos.receipt_footer');
        $this->autoPrint = PosSettings::autoPrint();
        $this->quickCash = implode(', ', PosSettings::quickCash());
        $this->qrisPayload = PosSettings::get('pos.qris_payload');
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
            'qrisSaved' => $this->qrisPayload === PosSettings::get('pos.qris_payload'),
        ]);
    }
}
