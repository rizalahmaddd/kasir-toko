<?php

namespace App\Livewire\Platform;

use App\Models\Setting;
use App\Models\Tenant;
use App\Support\SaasPlans;
use App\Support\SaasSettings;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Pengaturan Layanan'])]
#[Title('Pengaturan Layanan')]
class ServiceSettings extends Component
{
    public bool $registrationOpen = true;

    public string $trialDays = '';

    public string $supportContact = '';

    public string $paymentInstructions = '';

    public ?string $editingPlan = null;

    public ?string $deletingPlan = null;

    public string $planKey = '';

    public string $planLabel = '';

    public string $planPrice = '';

    public string $planMonthlyDiscount = '';

    public string $planMonthlyFinalPrice = '';

    public string $planMonthlyDiscountPercent = '';

    public string $planYearlyPrice = '';

    public string $planYearlyDiscount = '';

    public string $planYearlyFinalPrice = '';

    public string $planYearlyDiscountPercent = '';

    public string $planMaxUsers = '';

    public string $planMaxProducts = '';

    public function updatedPlanPrice($value): void
    {
        $price = (int) preg_replace('/\D/', '', (string) $value);
        if (filled($this->planMonthlyDiscountPercent) && $price > 0) {
            $this->updatedPlanMonthlyDiscountPercent($this->planMonthlyDiscountPercent);
        } elseif (filled($this->planMonthlyFinalPrice) && $price > 0) {
            $this->updatedPlanMonthlyFinalPrice($this->planMonthlyFinalPrice);
        }
    }

    public function updatedPlanMonthlyFinalPrice($value): void
    {
        $price = (int) preg_replace('/\D/', '', $this->planPrice);
        $cleanValue = preg_replace('/\D/', '', (string) $value);
        $final = filled($cleanValue) ? (int) $cleanValue : null;

        if ($final !== null && $price > 0) {
            $discount = max(0, $price - $final);
            $this->planMonthlyDiscount = (string) $discount;
            $this->planMonthlyDiscountPercent = (string) round(($discount / $price) * 100);
        } elseif ($final === null) {
            $this->planMonthlyDiscount = '';
            $this->planMonthlyDiscountPercent = '';
        }
    }

    public function updatedPlanMonthlyDiscountPercent($value): void
    {
        $price = (int) preg_replace('/\D/', '', $this->planPrice);
        $percent = filled($value) ? min(100, max(0, (float) $value)) : null;

        if ($percent !== null && $price > 0) {
            $discount = (int) round(($price * $percent) / 100);
            $final = max(0, $price - $discount);
            $this->planMonthlyDiscount = (string) $discount;
            $this->planMonthlyFinalPrice = (string) $final;
        } elseif ($percent === null) {
            $this->planMonthlyDiscount = '';
            $this->planMonthlyFinalPrice = '';
        }
    }

    public function updatedPlanMonthlyDiscount($value): void
    {
        $price = (int) preg_replace('/\D/', '', $this->planPrice);
        $cleanValue = preg_replace('/\D/', '', (string) $value);
        if (filled($cleanValue) && $price > 0) {
            $discount = (int) $cleanValue;
            if ($discount <= $price) {
                $this->planMonthlyFinalPrice = (string) ($price - $discount);
                $this->planMonthlyDiscountPercent = (string) round(($discount / $price) * 100);
            } else {
                $this->planMonthlyFinalPrice = '';
                $this->planMonthlyDiscountPercent = '';
            }
        }
    }

    public function updatedPlanYearlyPrice($value): void
    {
        $price = (int) preg_replace('/\D/', '', (string) $value);
        if (filled($this->planYearlyDiscountPercent) && $price > 0) {
            $this->updatedPlanYearlyDiscountPercent($this->planYearlyDiscountPercent);
        } elseif (filled($this->planYearlyFinalPrice) && $price > 0) {
            $this->updatedPlanYearlyFinalPrice($this->planYearlyFinalPrice);
        }
    }

    public function updatedPlanYearlyFinalPrice($value): void
    {
        $price = (int) preg_replace('/\D/', '', $this->planYearlyPrice);
        $cleanValue = preg_replace('/\D/', '', (string) $value);
        $final = filled($cleanValue) ? (int) $cleanValue : null;

        if ($final !== null && $price > 0) {
            $discount = max(0, $price - $final);
            $this->planYearlyDiscount = (string) $discount;
            $this->planYearlyDiscountPercent = (string) round(($discount / $price) * 100);
        } elseif ($final === null) {
            $this->planYearlyDiscount = '';
            $this->planYearlyDiscountPercent = '';
        }
    }

    public function updatedPlanYearlyDiscountPercent($value): void
    {
        $price = (int) preg_replace('/\D/', '', $this->planYearlyPrice);
        $percent = filled($value) ? min(100, max(0, (float) $value)) : null;

        if ($percent !== null && $price > 0) {
            $discount = (int) round(($price * $percent) / 100);
            $final = max(0, $price - $discount);
            $this->planYearlyDiscount = (string) $discount;
            $this->planYearlyFinalPrice = (string) $final;
        } elseif ($percent === null) {
            $this->planYearlyDiscount = '';
            $this->planYearlyFinalPrice = '';
        }
    }

    public function updatedPlanYearlyDiscount($value): void
    {
        $price = (int) preg_replace('/\D/', '', $this->planYearlyPrice);
        $cleanValue = preg_replace('/\D/', '', (string) $value);
        if (filled($cleanValue) && $price > 0) {
            $discount = (int) $cleanValue;
            if ($discount <= $price) {
                $this->planYearlyFinalPrice = (string) ($price - $discount);
                $this->planYearlyDiscountPercent = (string) round(($discount / $price) * 100);
            } else {
                $this->planYearlyFinalPrice = '';
                $this->planYearlyDiscountPercent = '';
            }
        }
    }

    public function mount(): void
    {
        $this->registrationOpen = SaasSettings::registrationOpen();
        $this->trialDays = (string) SaasSettings::trialDays();
        $this->supportContact = SaasSettings::supportContact() ?? '';
        $this->paymentInstructions = SaasSettings::paymentInstructions() ?? '';
    }

    public function save(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'registrationOpen' => ['boolean'],
            'trialDays' => ['required', 'integer', 'min:1', 'max:90'],
            'supportContact' => ['nullable', 'string', 'max:200'],
            'paymentInstructions' => ['nullable', 'string', 'max:1000'],
        ], [], ['trialDays' => 'lama uji coba', 'supportContact' => 'kontak admin', 'paymentInstructions' => 'cara pembayaran']);

        Setting::put(SaasSettings::REGISTRATION_OPEN, $validated['registrationOpen'] ? '1' : '0');
        Setting::put(SaasSettings::TRIAL_DAYS, (string) (int) $validated['trialDays']);
        Setting::put(SaasSettings::SUPPORT_CONTACT, trim((string) $validated['supportContact']) ?: null);
        Setting::put(SaasSettings::PAYMENT_INSTRUCTIONS, trim((string) $validated['paymentInstructions']) ?: null);

        $this->dispatch('notify', message: 'Pengaturan layanan disimpan.');
    }

    public function openCreatePlan(): void
    {
        $this->authorizeManage();
        $this->resetPlanForm();
        $this->dispatch('open-modal', 'plan-form');
    }

    public function openEditPlan(string $key): void
    {
        $this->authorizeManage();

        $plan = SaasPlans::find($key);
        abort_if($plan === null, 404);

        $this->resetPlanForm();
        $this->editingPlan = $key;
        $this->planKey = $key;
        $this->planLabel = $plan['label'];
        $this->planPrice = (string) ($plan['price'] ?? '');
        $this->planMonthlyDiscount = (string) ($plan['monthly_discount'] ?? '');
        $price = (int) ($plan['price'] ?? 0);
        $monthlyDiscount = (int) ($plan['monthly_discount'] ?? 0);
        if ($monthlyDiscount > 0 && $price > 0) {
            $this->planMonthlyFinalPrice = (string) max(0, $price - $monthlyDiscount);
            $this->planMonthlyDiscountPercent = (string) round(($monthlyDiscount / $price) * 100);
        } else {
            $this->planMonthlyFinalPrice = '';
            $this->planMonthlyDiscountPercent = '';
        }

        $this->planYearlyPrice = (string) ($plan['yearly_price'] ?? '');
        $this->planYearlyDiscount = (string) ($plan['yearly_discount'] ?? '');
        $yearlyPrice = (int) ($plan['yearly_price'] ?? 0);
        $yearlyDiscount = (int) ($plan['yearly_discount'] ?? 0);
        if ($yearlyDiscount > 0 && $yearlyPrice > 0) {
            $this->planYearlyFinalPrice = (string) max(0, $yearlyPrice - $yearlyDiscount);
            $this->planYearlyDiscountPercent = (string) round(($yearlyDiscount / $yearlyPrice) * 100);
        } else {
            $this->planYearlyFinalPrice = '';
            $this->planYearlyDiscountPercent = '';
        }

        $this->planMaxUsers = (string) ($plan['max_users'] ?? '');
        $this->planMaxProducts = (string) ($plan['max_products'] ?? '');
        $this->dispatch('open-modal', 'plan-form');
    }

    public function savePlan(): void
    {
        $this->authorizeManage();

        $this->planKey = strtolower(trim($this->planKey));
        foreach (['planPrice', 'planMonthlyDiscount', 'planMonthlyFinalPrice', 'planYearlyPrice', 'planYearlyDiscount', 'planYearlyFinalPrice', 'planMaxUsers', 'planMaxProducts'] as $field) {
            $this->{$field} = preg_replace('/\D/', '', $this->{$field}) ?? '';
        }

        $monthlyPrice = filled($this->planPrice) ? (int) $this->planPrice : null;
        if ($monthlyPrice !== null) {
            if (filled($this->planMonthlyFinalPrice) && (int) $this->planMonthlyFinalPrice <= $monthlyPrice) {
                $this->planMonthlyDiscount = (string) ($monthlyPrice - (int) $this->planMonthlyFinalPrice);
            } elseif (filled($this->planMonthlyDiscountPercent)) {
                $this->planMonthlyDiscount = (string) (int) round(($monthlyPrice * min(100, (float) $this->planMonthlyDiscountPercent)) / 100);
            }
        }

        $yearlyPrice = filled($this->planYearlyPrice) ? (int) $this->planYearlyPrice : null;
        if ($yearlyPrice !== null) {
            if (filled($this->planYearlyFinalPrice) && (int) $this->planYearlyFinalPrice <= $yearlyPrice) {
                $this->planYearlyDiscount = (string) ($yearlyPrice - (int) $this->planYearlyFinalPrice);
            } elseif (filled($this->planYearlyDiscountPercent)) {
                $this->planYearlyDiscount = (string) (int) round(($yearlyPrice * min(100, (float) $this->planYearlyDiscountPercent)) / 100);
            }
        }

        $plans = SaasPlans::all();
        $validated = $this->validate([
            'planKey' => $this->editingPlan !== null ? [] : ['required', 'string', 'min:2', 'max:20', 'regex:/^[a-z][a-z0-9_]*$/', Rule::notIn(array_keys($plans))],
            'planLabel' => ['required', 'string', 'max:50'],
            'planPrice' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'planMonthlyFinalPrice' => [
                'nullable',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    if (filled($value) && filled($this->planPrice) && (int) $value > (int) $this->planPrice) {
                        $fail('Harga akhir / promo tidak boleh lebih mahal dari harga normal.');
                    }
                },
            ],
            'planMonthlyDiscountPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'planMonthlyDiscount' => [
                'nullable',
                'integer',
                'min:0',
                'max:1000000000',
                function ($attribute, $value, $fail) {
                    if (filled($value) && filled($this->planPrice) && (int) $value > (int) $this->planPrice) {
                        $fail('Diskon bulanan tidak boleh melebihi harga bulanan.');
                    }
                },
            ],
            'planYearlyPrice' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'planYearlyFinalPrice' => [
                'nullable',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    if (filled($value) && filled($this->planYearlyPrice) && (int) $value > (int) $this->planYearlyPrice) {
                        $fail('Harga akhir / promo tidak boleh lebih mahal dari harga normal.');
                    }
                },
            ],
            'planYearlyDiscountPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'planYearlyDiscount' => [
                'nullable',
                'integer',
                'min:0',
                'max:1000000000',
                function ($attribute, $value, $fail) {
                    if (filled($value) && filled($this->planYearlyPrice) && (int) $value > (int) $this->planYearlyPrice) {
                        $fail('Diskon tahunan tidak boleh melebihi harga tahunan.');
                    }
                },
            ],
            'planMaxUsers' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'planMaxProducts' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ], [
            'planKey.regex' => 'Kode paket hanya huruf kecil, angka, dan garis bawah, diawali huruf.',
            'planKey.not_in' => 'Kode paket sudah dipakai.',
        ], [
            'planKey' => 'kode paket',
            'planLabel' => 'nama paket',
            'planPrice' => 'harga bulanan',
            'planMonthlyFinalPrice' => 'nilai akhir bulanan',
            'planMonthlyDiscountPercent' => 'persentase diskon bulanan',
            'planMonthlyDiscount' => 'diskon bulanan',
            'planYearlyPrice' => 'harga tahunan',
            'planYearlyFinalPrice' => 'nilai akhir tahunan',
            'planYearlyDiscountPercent' => 'persentase diskon tahunan',
            'planYearlyDiscount' => 'diskon tahunan',
            'planMaxUsers' => 'batas pengguna',
            'planMaxProducts' => 'batas produk',
        ]);

        $key = $this->editingPlan ?? $validated['planKey'];
        $before = $plans[$key] ?? null;

        $monthlyDiscountVal = filled($this->planMonthlyDiscount) ? (int) $this->planMonthlyDiscount : null;
        $yearlyDiscountVal = filled($this->planYearlyDiscount) ? (int) $this->planYearlyDiscount : null;

        $plans[$key] = [
            'label' => trim($validated['planLabel']),
            'price' => filled($validated['planPrice']) ? (int) $validated['planPrice'] : null,
            'monthly_discount' => ($monthlyDiscountVal && $monthlyDiscountVal > 0) ? $monthlyDiscountVal : null,
            'yearly_price' => filled($validated['planYearlyPrice']) ? (int) $validated['planYearlyPrice'] : null,
            'yearly_discount' => ($yearlyDiscountVal && $yearlyDiscountVal > 0) ? $yearlyDiscountVal : null,
            'max_users' => filled($validated['planMaxUsers']) ? (int) $validated['planMaxUsers'] : null,
            'max_products' => filled($validated['planMaxProducts']) ? (int) $validated['planMaxProducts'] : null,
        ];

        SaasPlans::save($plans);

        activity('platform')->event($before ? 'updated' : 'created')
            ->withProperties(['plan' => $key, 'old' => $before, 'attributes' => $plans[$key]])
            ->log($before ? "Paket {$plans[$key]['label']} diubah." : "Paket {$plans[$key]['label']} ditambahkan.");

        $this->dispatch('close-modal', 'plan-form');
        $this->resetPlanForm();
        $this->dispatch('notify', message: 'Paket disimpan.');
    }

    public function closePlanModal(): void
    {
        $this->resetPlanForm();
        $this->dispatch('close-modal', 'plan-form');
    }

    public function confirmDeletePlan(string $key): void
    {
        $this->authorizeManage();
        $this->deletingPlan = $key;
        $this->dispatch('open-modal', 'confirm-delete');
    }

    public function cancelDelete(): void
    {
        $this->deletingPlan = null;
        $this->dispatch('close-modal', 'confirm-delete');
    }

    public function delete(): void
    {
        $this->authorizeManage();

        $key = (string) $this->deletingPlan;
        $plans = SaasPlans::all();
        $this->cancelDelete();

        if (! isset($plans[$key])) {
            return;
        }

        if (SaasPlans::isUsed($key)) {
            $this->dispatch('notify', message: 'Paket ini masih dipakai toko atau merupakan paket uji coba, jadi tidak bisa dihapus.', type: 'error');

            return;
        }

        $label = $plans[$key]['label'];
        unset($plans[$key]);
        SaasPlans::save($plans);

        activity('platform')->event('deleted')
            ->withProperties(['plan' => $key])
            ->log("Paket {$label} dihapus.");

        $this->dispatch('notify', message: "Paket {$label} dihapus.");
    }

    public function render()
    {
        $usage = Tenant::query()->selectRaw('plan, count(*) as total')->groupBy('plan')->pluck('total', 'plan');

        return view('livewire.platform.service-settings', [
            'plans' => SaasPlans::all(),
            'planUsage' => $usage,
        ]);
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('manage-platform'), 403);
    }

    private function resetPlanForm(): void
    {
        $this->reset([
            'editingPlan',
            'planKey',
            'planLabel',
            'planPrice',
            'planMonthlyDiscount',
            'planMonthlyFinalPrice',
            'planMonthlyDiscountPercent',
            'planYearlyPrice',
            'planYearlyDiscount',
            'planYearlyFinalPrice',
            'planYearlyDiscountPercent',
            'planMaxUsers',
            'planMaxProducts',
        ]);
        $this->resetErrorBag();
    }
}
