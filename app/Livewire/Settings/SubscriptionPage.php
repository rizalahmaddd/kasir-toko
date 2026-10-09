<?php

namespace App\Livewire\Settings;

use App\Models\Product;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SumoPodPaymentService;
use App\Support\CurrentTenant;
use App\Support\PlanLimits;
use App\Support\SaasPlans;
use App\Support\SaasSettings;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['heading' => 'Paket & Langganan'])]
#[Title('Paket & Langganan')]
class SubscriptionPage extends Component
{
    use WithPagination;

    public string $billingCycle = 'monthly'; // 'monthly' | 'yearly'

    public ?int $checkoutInvoiceId = null;

    public ?string $checkoutUrl = null;

    public ?string $checkoutError = null;

    public ?int $pendingInvoiceId = null;

    public ?int $confirmingCancelId = null;

    public ?string $attemptedPlanKey = null;

    #[Computed]
    public function pendingInvoice(): ?SubscriptionInvoice
    {
        if (! $this->pendingInvoiceId) {
            return null;
        }

        return SubscriptionInvoice::query()
            ->where('tenant_id', $this->tenant()?->id)
            ->find($this->pendingInvoiceId);
    }

    #[Computed]
    public function invoiceToCancel(): ?SubscriptionInvoice
    {
        if (! $this->confirmingCancelId) {
            return null;
        }

        return SubscriptionInvoice::query()
            ->where('tenant_id', $this->tenant()?->id)
            ->find($this->confirmingCancelId);
    }

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403, 'Hanya pemilik toko yang dapat mengelola langganan.');
    }

    #[Computed]
    public function tenant(): ?Tenant
    {
        return app(CurrentTenant::class)->get();
    }

    #[Computed]
    public function availablePlans(): array
    {
        $all = SaasPlans::all();
        $freePlan = $all['free'] ?? ['price' => 0, 'yearly_price' => 0, 'monthly_discount' => 0, 'yearly_discount' => 0];
        $proPlan = $all['pro'] ?? ['price' => 20000, 'yearly_price' => 199000, 'monthly_discount' => 0, 'yearly_discount' => 0];

        $rawFreeMonthly = (int) ($freePlan['price'] ?? 0);
        $freeMonthlyDiscount = (int) ($freePlan['monthly_discount'] ?? 0);
        $rawFreeYearly = (int) ($freePlan['yearly_price'] ?? 0);
        $freeYearlyDiscount = (int) ($freePlan['yearly_discount'] ?? 0);

        $rawProMonthly = (int) ($proPlan['price'] ?? 20000);
        $proMonthlyDiscount = (int) ($proPlan['monthly_discount'] ?? 0);
        $rawProYearly = (int) ($proPlan['yearly_price'] ?? 199000);
        $proYearlyDiscount = (int) ($proPlan['yearly_discount'] ?? 0);

        $lifetimePlan = $all['lifetime'] ?? ['price' => 499000, 'yearly_price' => null, 'monthly_discount' => 0, 'yearly_discount' => 0];
        $rawLifetime = (int) ($lifetimePlan['price'] ?? 499000);
        $lifetimeDiscount = (int) ($lifetimePlan['monthly_discount'] ?? 0);

        return [
            [
                'key' => 'free',
                'label' => $freePlan['label'] ?? 'Gratis (Esensial)',
                'raw_monthly_price' => $rawFreeMonthly,
                'monthly_discount' => $freeMonthlyDiscount,
                'monthly_price' => max(0, $rawFreeMonthly - $freeMonthlyDiscount),
                'raw_yearly_price' => $rawFreeYearly,
                'yearly_discount' => $freeYearlyDiscount,
                'yearly_price' => max(0, $rawFreeYearly - $freeYearlyDiscount),
                'max_users' => $freePlan['max_users'] ?? null,
                'max_products' => $freePlan['max_products'] ?? null,
                'features' => [
                    self::outletFeature($freePlan),
                    'Kasir POS & Transaksi Penjualan',
                    'Cetak Struk Bluetooth Thermal',
                    'Shift Kasir & Buka/Tutup Laci Uang',
                    'Katalog Produk & Kategori',
                    'Aplikasi Kasir Mobile (Android & iOS)',
                ],
            ],
            [
                'key' => 'pro',
                'label' => $proPlan['label'] ?? 'Pro (Semua Fitur)',
                'raw_monthly_price' => $rawProMonthly,
                'monthly_discount' => $proMonthlyDiscount,
                'monthly_price' => max(0, $rawProMonthly - $proMonthlyDiscount),
                'raw_yearly_price' => $rawProYearly,
                'yearly_discount' => $proYearlyDiscount,
                'yearly_price' => max(0, $rawProYearly - $proYearlyDiscount),
                'max_users' => $proPlan['max_users'] ?? null,
                'max_products' => $proPlan['max_products'] ?? null,
                'features' => [
                    'Semua fitur paket Gratis',
                    self::outletFeature($proPlan, 'Hingga %d outlet dalam satu langganan'),
                    'Customer Display (Layar Pelanggan QRIS Dinamis)',
                    'Piutang & Catatan Kasbon Pelanggan',
                    'Laporan Penjualan & Analisis Laba Rugi',
                    'Ekspor Salinan Data Toko ke Excel/CSV',
                    'Log Aktivitas & Audit Trail Keamanan',
                ],
            ],
            [
                'key' => 'lifetime',
                'label' => $lifetimePlan['label'] ?? 'Lifetime (Permanen)',
                'raw_monthly_price' => $rawLifetime,
                'monthly_discount' => $lifetimeDiscount,
                'monthly_price' => max(0, $rawLifetime - $lifetimeDiscount),
                'raw_yearly_price' => $rawLifetime,
                'yearly_discount' => $lifetimeDiscount,
                'yearly_price' => max(0, $rawLifetime - $lifetimeDiscount),
                'max_users' => $lifetimePlan['max_users'] ?? null,
                'max_products' => $lifetimePlan['max_products'] ?? null,
                'features' => [
                    'Semua fitur paket Pro selamanya',
                    self::outletFeature($lifetimePlan, 'Hingga %d outlet tanpa biaya tambahan'),
                    'Sekali bayar tanpa tagihan perpanjangan',
                    'Akses permanen tanpa masa kedaluwarsa',
                    'Customer Display & Layar QRIS Dinamis',
                    'Piutang & Kasbon Pelanggan Tanpa Batas',
                    'Prioritas Bantuan & Pembaruan Sistem',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private static function outletFeature(array $plan, string $multiple = 'Hingga %d outlet'): string
    {
        $max = (int) ($plan['max_outlets'] ?? 1);

        return $max > 1 ? sprintf($multiple, $max) : '1 outlet';
    }

    #[Computed]
    public function usage(): array
    {
        $tenant = $this->tenant();
        $userLimit = $tenant?->limit('users');
        $productLimit = $tenant?->limit('products');

        $userCount = User::query()->count();
        $productCount = Product::query()->count();
        $outletLimit = $tenant?->maxOutlets() ?? 1;
        $outletCount = PlanLimits::count('outlets');

        return [
            'users' => [
                'current' => $userCount,
                'limit' => $userLimit,
                'percent' => $userLimit ? min(100, (int) round(($userCount / $userLimit) * 100)) : null,
            ],
            'products' => [
                'current' => $productCount,
                'limit' => $productLimit,
                'percent' => $productLimit ? min(100, (int) round(($productCount / $productLimit) * 100)) : null,
            ],
            'outlets' => [
                'current' => $outletCount,
                'limit' => $outletLimit,
                'percent' => min(100, (int) round(($outletCount / $outletLimit) * 100)),
            ],
        ];
    }

    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        SubscriptionInvoice::autoCancelStale();

        return SubscriptionInvoice::query()
            ->latest('id')
            ->paginate(5);
    }

    public function checkout(string $planKey, SumoPodPaymentService $sumoPod): void
    {
        $this->checkoutError = null;
        $tenant = $this->tenant();

        if (! $tenant) {
            return;
        }

        // Otomatis batalkan tagihan yang sudah lewat batas waktu (3x24 jam)
        SubscriptionInvoice::autoCancelStale();

        $plan = SaasPlans::find($planKey);

        if (! $plan) {
            $this->checkoutError = 'Paket yang dipilih tidak valid.';

            return;
        }

        if ($planKey === 'lifetime') {
            $periodMonths = 0;
            $rawPrice = (int) ($plan['price'] ?? 499000);
            $discount = (int) ($plan['monthly_discount'] ?? 0);
            $amount = max(0, $rawPrice - $discount);
        } else {
            $isYearly = $this->billingCycle === 'yearly';
            $periodMonths = $isYearly ? 12 : 1;

            $rawMonthly = (int) ($plan['price'] ?? 20000);
            $monthlyDiscount = (int) ($plan['monthly_discount'] ?? 0);
            $effectiveMonthly = max(0, $rawMonthly - $monthlyDiscount);

            $rawYearly = (int) ($plan['yearly_price'] ?? ($rawMonthly * 12));
            $yearlyDiscount = (int) ($plan['yearly_discount'] ?? 0);
            $effectiveYearly = max(0, $rawYearly - $yearlyDiscount);

            $amount = $isYearly ? $effectiveYearly : $effectiveMonthly;
        }

        // Cek apakah sudah ada tagihan pending yang masih aktif untuk paket ini
        $existingInvoice = SubscriptionInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('plan', $planKey)
            ->where('status', SubscriptionInvoice::STATUS_PENDING)
            ->latest('id')
            ->first();

        if ($existingInvoice) {
            $this->pendingInvoiceId = $existingInvoice->id;
            $this->attemptedPlanKey = $planKey;
            $this->dispatch('open-modal', 'pending-invoice-exists-modal');

            return;
        }

        // Cek jika payment gateway aktif
        if (! $sumoPod->isConfigured()) {
            $contact = SaasSettings::supportContact() ?? 'Admin Layanan';
            $this->checkoutError = "Pembayaran otomatis via QRIS sedang dalam pemeliharaan. Silakan hubungi {$contact} untuk perpanjangan manual.";
            $this->dispatch('open-modal', 'checkout-error-modal');

            return;
        }

        $invoice = SubscriptionInvoice::create([
            'tenant_id' => $tenant->id,
            'invoice_number' => SubscriptionInvoice::generateNumber(),
            'plan' => $planKey,
            'period_months' => $periodMonths,
            'amount' => $amount,
            'status' => SubscriptionInvoice::STATUS_PENDING,
            'payment_method' => 'qris',
            'expires_at' => now()->addHours(72),
        ]);

        try {
            $response = $sumoPod->createPayment($invoice);
            $this->checkoutInvoiceId = $invoice->id;
            $this->checkoutUrl = $response['payment_link_url'] ?? null;

            if ($this->checkoutUrl) {
                // Arahkan langsung ke halaman pembayaran QRIS SumoPod
                $this->redirect($this->checkoutUrl);
            }
        } catch (\Throwable $e) {
            $invoice->update(['status' => SubscriptionInvoice::STATUS_FAILED]);
            $this->checkoutError = 'Terjadi kendala saat menghubungkan ke gateway pembayaran: '.$e->getMessage();
            $this->dispatch('open-modal', 'checkout-error-modal');
        }
    }

    public function payInvoice(int $invoiceId, SumoPodPaymentService $sumoPod): void
    {
        $tenant = $this->tenant();
        if (! $tenant) {
            return;
        }

        $invoice = SubscriptionInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($invoiceId);

        if ($invoice->isPaid()) {
            return;
        }

        if ($invoice->payment_url && (! $invoice->expires_at || $invoice->expires_at->isFuture())) {
            $this->redirect($invoice->payment_url);

            return;
        }

        // Jika payment link kadaluwarsa, generate ulang link baru di SumoPod
        try {
            $response = $sumoPod->createPayment($invoice);
            if (isset($response['payment_link_url'])) {
                $this->redirect($response['payment_link_url']);
            }
        } catch (\Throwable $e) {
            $this->checkoutError = 'Gagal memperbarui tautan pembayaran: '.$e->getMessage();
            $this->dispatch('open-modal', 'checkout-error-modal');
        }
    }

    public function cancelInvoice(int $invoiceId): void
    {
        $tenant = $this->tenant();
        if (! $tenant) {
            return;
        }

        $invoice = SubscriptionInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($invoiceId);

        if (! $invoice->isPending()) {
            return;
        }

        $invoice->update([
            'status' => SubscriptionInvoice::STATUS_CANCELLED,
        ]);

        $this->dispatch('notify', message: "Tagihan {$invoice->invoice_number} berhasil dibatalkan.", type: 'success');
    }

    public function confirmCancel(int $invoiceId): void
    {
        $this->confirmingCancelId = $invoiceId;
        $this->dispatch('open-modal', 'confirm-cancel-invoice-modal');
    }

    public function cancelConfirmedInvoice(): void
    {
        if (! $this->confirmingCancelId) {
            return;
        }

        $this->cancelInvoice($this->confirmingCancelId);
        $this->confirmingCancelId = null;
        $this->dispatch('close-modal', 'confirm-cancel-invoice-modal');
    }

    public function cancelInvoiceFromPendingModal(): void
    {
        if ($this->pendingInvoiceId) {
            $this->cancelInvoice($this->pendingInvoiceId);
            $this->pendingInvoiceId = null;
        }

        $this->dispatch('close-modal', 'pending-invoice-exists-modal');
    }

    public function cancelPendingInvoiceAndProceed(SumoPodPaymentService $sumoPod): void
    {
        $planKey = $this->attemptedPlanKey;

        if ($this->pendingInvoiceId) {
            $this->cancelInvoice($this->pendingInvoiceId);
            $this->pendingInvoiceId = null;
        }

        $this->dispatch('close-modal', 'pending-invoice-exists-modal');

        if ($planKey) {
            $this->checkout($planKey, $sumoPod);
        }
    }

    public function render()
    {
        return view('livewire.settings.subscription-page', [
            'tenant' => $this->tenant(),
            'plans' => $this->availablePlans(),
            'usage' => $this->usage(),
            'invoices' => $this->invoices(),
            'supportContact' => SaasSettings::supportContact(),
        ]);
    }
}
