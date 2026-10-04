import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

function runArtisan(command) {
  return execSync(`php artisan ${command}`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}

test.describe('SaaS Full Lifecycle: Register -> Onboarding -> Pro Gating -> Consecutive Upgrades & Accumulation', () => {
  test.beforeEach(async () => {
    runArtisan(`tinker --execute="Illuminate\\\\Support\\\\Facades\\\\RateLimiter::clear('register:127.0.0.1');"`);
  });

  test.afterAll(async () => {
    // Restore default demo tenant to pro for regular local usage
    runArtisan(`tinker --execute="App\\\\Models\\\\Tenant::find(1)->update(['plan' => 'pro', 'subscription_ends_at' => now()->addYear()]);"`);
  });

  test('Full SaaS Lifecycle E2E: Register New Store -> Onboarding -> Pro Trial -> Free Gating -> 1st Yearly Upgrade -> 2nd Consecutive Yearly Upgrade (accumulated 2 yrs) -> Monthly Upgrade (accumulated 2 yrs 1 mo)', async ({ page, request }) => {
    const rand = Math.floor(Math.random() * 90000) + 10000;
    const shopName = `Toko E2E ${rand}`;
    const username = `owner_${rand}`;
    const email = `owner_${rand}@kasirtoko.test`;
    const password = 'password123';

    // ==========================================
    // 1. REGISTER NEW STORE & OWNER
    // ==========================================
    await page.goto('/daftar');
    await expect(page).toHaveTitle(/Daftar Toko Baru|Kasir Toko/);

    await page.fill('#shop_name', shopName);
    await page.fill('#name', 'Pemilik Toko E2E');
    await page.fill('#username', username);
    await page.fill('#email', email);
    await page.fill('#password', password);
    await page.fill('#password_confirmation', password);
    await page.check('#agree_terms');
    await page.click('button[type="submit"]');

    // ==========================================
    // 2. ONBOARDING WIZARD
    // ==========================================
    await expect(page).toHaveURL(/.*persiapan-toko/, { timeout: 15000 });
    await expect(page.locator('body')).toContainText('Persiapan Toko');

    // Click "Lewati, mulai dari kosong"
    const skipBtn = page.locator('button:has-text("Lewati, mulai dari kosong")');
    await skipBtn.scrollIntoViewIfNeeded();
    await skipBtn.click({ force: true });
    await expect(page).toHaveURL(/.*dashboard/, { timeout: 15000 });
    await expect(page.locator('body')).toContainText(shopName);

    // ==========================================
    // 3. VERIFY INITIAL TRIAL & SIMULATE FREE TIER
    // ==========================================
    await page.goto('/pengaturan/langganan');
    await expect(page.locator('body')).toContainText('Masa uji coba gratis berakhir');

    // Simulate expired trial -> tenant drops to Free Tier
    runArtisan(`tinker --execute="App\\\\Models\\\\Tenant::where('name', '${shopName}')->first()->update(['plan' => 'free', 'trial_ends_at' => now()->subDay(), 'subscription_ends_at' => null]);"`);

    // Verify Pro Feature Gating on Free Tier
    await page.goto('/piutang');
    await expect(page.locator('body')).toContainText('Pencatatan Piutang & Kasbon Khusus Pro');
    await expect(page.locator('body')).toContainText('Eksklusif Paket Pro');
    await expect(page.locator('a:has-text("Upgrade ke Pro Sekarang")')).toBeVisible();

    // Verify Essential POS Cashier remains 100% accessible
    await page.goto('/kasir');
    await expect(page.locator('body')).not.toContainText('Eksklusif Paket Pro');
    await expect(page.locator('input[placeholder*="Ketik nama produk"]').first()).toBeVisible();

    // ==========================================
    // 4. ORDER 1: UPGRADE TAHUNAN (12 BULAN / 1 TAHUN)
    // ==========================================
    await page.goto('/pengaturan/langganan');
    await page.click('button:has-text("Tahunan")');
    await expect(page.locator('body')).toContainText('Rp199.000');

    // Click Upgrade
    await page.click('button:has-text("Upgrade ke Pro Sekarang (QRIS)")');
    await page.waitForTimeout(2500);

    // Fetch created invoice for order 1
    const inv1Json = runArtisan(`tinker --execute="echo json_encode(App\\\\Models\\\\SubscriptionInvoice::where('tenant_id', App\\\\Models\\\\Tenant::where('name', '${shopName}')->value('id'))->latest('id')->first());"`);
    const inv1 = JSON.parse(inv1Json.trim());
    expect(inv1.period_months).toBe(12);
    expect(inv1.amount).toBe(199000);

    // Simulate payment completion via SumoPod Webhook
    const webhookRes1 = await request.post('/api/webhooks/sumopod', {
      data: {
        event_type: 'payment.completed',
        data: {
          order_id: inv1.invoice_number,
          amount: inv1.amount,
          payment_id: 'pay-e2e-order-1',
          paid_at: new Date().toISOString(),
        },
      },
    });
    expect(webhookRes1.ok()).toBeTruthy();

    // Verify active period is now 12 months (1 year)
    const diffMonths1 = runArtisan(`tinker --execute="echo round(now()->diffInMonths(App\\\\Models\\\\Tenant::where('name', '${shopName}')->value('subscription_ends_at')));"`);
    expect(parseInt(diffMonths1.trim())).toBe(12);

    // Refresh subscription page & check status
    await page.goto('/pengaturan/langganan');
    await expect(page.locator('body')).toContainText('Paket Pro');
    await expect(page.locator('body')).toContainText('Langganan aktif sampai');
    await expect(page.locator('body')).toContainText(inv1.invoice_number);
    await expect(page.locator('body')).toContainText('Lunas');

    // Verify Pro page is now unlocked
    await page.goto('/piutang');
    await expect(page.locator('body')).not.toContainText('Eksklusif Paket Pro');
    await expect(page.locator('body')).toContainText('Total kasbon belum lunas');

    // ==========================================
    // 5. ORDER 2: ORDER TAHUNAN LAGI (+1 TAHUN AKUMULATIF)
    // ==========================================
    await page.goto('/pengaturan/langganan');
    await page.click('button:has-text("Tahunan")');

    // When already Pro, button says "Perpanjang Pro (QRIS)"
    await page.click('button:has-text("Perpanjang Pro (QRIS)")');
    await page.waitForTimeout(2500);

    // Fetch created invoice for order 2
    const inv2Json = runArtisan(`tinker --execute="echo json_encode(App\\\\Models\\\\SubscriptionInvoice::where('tenant_id', App\\\\Models\\\\Tenant::where('name', '${shopName}')->value('id'))->latest('id')->first());"`);
    const inv2 = JSON.parse(inv2Json.trim());
    expect(inv2.id).not.toBe(inv1.id);
    expect(inv2.period_months).toBe(12);
    expect(inv2.amount).toBe(199000);

    // Simulate payment completion for Order 2
    const webhookRes2 = await request.post('/api/webhooks/sumopod', {
      data: {
        event_type: 'payment.completed',
        data: {
          order_id: inv2.invoice_number,
          amount: inv2.amount,
          payment_id: 'pay-e2e-order-2',
          paid_at: new Date().toISOString(),
        },
      },
    });
    expect(webhookRes2.ok()).toBeTruthy();

    // Verify active period is accumulated by another 12 months (Total = 24 months / 2 years)
    const diffMonths2 = runArtisan(`tinker --execute="echo round(now()->diffInMonths(App\\\\Models\\\\Tenant::where('name', '${shopName}')->value('subscription_ends_at')));"`);
    expect(parseInt(diffMonths2.trim())).toBe(24);

    // ==========================================
    // 6. ORDER 3: ORDER BULANAN LAGI (+1 BULAN AKUMULATIF)
    // ==========================================
    await page.goto('/pengaturan/langganan');
    await page.click('button:has-text("Bulanan")');
    await expect(page.locator('body')).toContainText('Rp20.000');

    await page.click('button:has-text("Perpanjang Pro (QRIS)")');
    await page.waitForTimeout(2500);

    // Fetch created invoice for order 3
    const inv3Json = runArtisan(`tinker --execute="echo json_encode(App\\\\Models\\\\SubscriptionInvoice::where('tenant_id', App\\\\Models\\\\Tenant::where('name', '${shopName}')->value('id'))->latest('id')->first());"`);
    const inv3 = JSON.parse(inv3Json.trim());
    expect(inv3.id).not.toBe(inv2.id);
    expect(inv3.period_months).toBe(1);
    expect(inv3.amount).toBe(20000);

    // Simulate payment completion for Order 3
    const webhookRes3 = await request.post('/api/webhooks/sumopod', {
      data: {
        event_type: 'payment.completed',
        data: {
          order_id: inv3.invoice_number,
          amount: inv3.amount,
          payment_id: 'pay-e2e-order-3',
          paid_at: new Date().toISOString(),
        },
      },
    });
    expect(webhookRes3.ok()).toBeTruthy();

    // Verify active period is accumulated by another 1 month (Total = 25 months / 2 years 1 month)
    const diffMonths3 = runArtisan(`tinker --execute="echo round(now()->diffInMonths(App\\\\Models\\\\Tenant::where('name', '${shopName}')->value('subscription_ends_at')));"`);
    expect(parseInt(diffMonths3.trim())).toBe(25);

    // Check that all 3 invoices are recorded and marked as paid in UI
    await page.goto('/pengaturan/langganan');
    await expect(page.locator('body')).toContainText(inv1.invoice_number);
    await expect(page.locator('body')).toContainText(inv2.invoice_number);
    await expect(page.locator('body')).toContainText(inv3.invoice_number);
  });

  test('E2E Free Tier: shows Pro badges, blocks Pro pages with Paywall, but keeps POS cashier 100% active', async ({ page }) => {
    runArtisan(`tinker --execute="App\\\\Models\\\\Tenant::find(1)->update(['plan' => 'free', 'trial_ends_at' => null, 'subscription_ends_at' => null]);"`);

    await page.goto('/login');
    await page.fill('#login', 'owner');
    await page.fill('#password', 'password');
    await page.click('button[type="submit"]');

    await expect(page).toHaveURL(/.*dashboard/);
    await expect(page.locator('body')).toContainText('Dashboard');

    // Sidebar contains PRO badges
    await page.click('button:has-text("Penjualan")');
    const proBadges = page.locator('aside span:has-text("PRO")');
    await expect(proBadges.first()).toBeVisible();

    // Pro Feature: Piutang -> Shows Paywall
    await page.goto('/piutang');
    await expect(page.locator('body')).toContainText('Pencatatan Piutang & Kasbon Khusus Pro');
    await expect(page.locator('body')).toContainText('Eksklusif Paket Pro');
    await expect(page.locator('a:has-text("Upgrade ke Pro Sekarang")')).toBeVisible();

    // Pro Feature: Laporan Penjualan -> Shows Paywall
    await page.goto('/laporan/penjualan');
    await expect(page.locator('body')).toContainText('Laporan Analisis Penjualan & Laba Rugi Khusus Pro');

    // Pro Feature: Ekspor Data Toko -> Shows Paywall
    await page.goto('/pengaturan/ekspor-data');
    await expect(page.locator('body')).toContainText('Ekspor Data Toko Khusus Pro');

    // POS Cashier -> 100% operational
    await page.goto('/kasir');
    await expect(page).toHaveURL(/.*kasir/);
    await expect(page.locator('body')).not.toContainText('Eksklusif Paket Pro');
  });

  test('E2E Pro Tier: unlocks all Pro pages when store is upgraded to Pro', async ({ page }) => {
    runArtisan(`tinker --execute="App\\\\Models\\\\Tenant::find(1)->update(['plan' => 'pro', 'subscription_ends_at' => now()->addMonth()]);"`);

    await page.context().clearCookies();
    await page.goto('/login');
    await page.fill('#login', 'owner');
    await page.fill('#password', 'password');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/.*dashboard/);

    // Piutang
    await page.goto('/piutang');
    await expect(page.locator('body')).not.toContainText('Eksklusif Paket Pro');
    await expect(page.locator('body')).toContainText('Total kasbon belum lunas');

    // Laporan Penjualan
    await page.goto('/laporan/penjualan');
    await expect(page.locator('body')).not.toContainText('Eksklusif Paket Pro');
    await expect(page.locator('body')).toContainText('Hari Ini');

    // Ekspor Data Toko
    await page.goto('/pengaturan/ekspor-data');
    await expect(page.locator('body')).not.toContainText('Eksklusif Paket Pro');
    await expect(page.locator('body')).toContainText('Unduh Data (ZIP)');
  });
});
