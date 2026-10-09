import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

// Toko Kelontong menambah outlet Apotek: obat dan menu Resep hanya muncul di kasir outlet Apotek.

function artisan(command) {
    return execSync(`php artisan ${command}`, { cwd: process.cwd(), encoding: 'utf-8' });
}

async function registerGroceryShop(page) {
    const rand = Math.floor(Math.random() * 900000) + 100000;
    const username = `e2e_${rand}`;
    const shopName = `Toko E2E ${rand}`;

    await page.goto('/daftar');
    await page.fill('#shop_name', shopName);
    await page.fill('#name', 'Pemilik E2E');
    await page.fill('#username', username);
    await page.fill('#email', `${username}@kasirtoko.test`);
    await page.fill('#password', 'password123');
    await page.fill('#password_confirmation', 'password123');
    await page.check('#agree_terms');
    await page.click('button[type="submit"]');

    await expect(page).toHaveURL(/persiapan-toko/, { timeout: 15000 });
    await page.waitForLoadState('networkidle');
    await page.locator('button', { hasText: 'Warung / Kelontong' }).first().click({ timeout: 20000 });
    await page.getByRole('button', { name: 'Terapkan Preset' }).click();
    await expect(page).toHaveURL(/dashboard/, { timeout: 30000 });

    artisan(`tinker --execute="App\\\\Models\\\\Tenant::where('name', '${shopName}')->first()->update(['plan' => 'pro', 'subscription_ends_at' => now()->addYear()]);"`);
}

async function switchOutlet(page, name) {
    await page.goto('/dashboard');
    await page.getByRole('button', { name: 'Ganti outlet' }).filter({ visible: true }).first().click();
    await page.getByRole('option', { name: new RegExp(name) }).filter({ visible: true }).first().click();
    await page.waitForLoadState('networkidle');
}

async function openCashier(page) {
    await page.goto('/kasir');
    await page.waitForLoadState('networkidle');
    const shiftInput = page.locator('#openingCash');
    if (await shiftInput.isVisible({ timeout: 5000 }).catch(() => false)) {
        await shiftInput.fill('0');
        await page.getByRole('button', { name: 'Buka Shift' }).click();
        await expect(shiftInput).toBeHidden({ timeout: 10000 });
    }
}

test.describe('Jenis usaha per outlet', () => {
    test.beforeEach(() => {
        artisan(`tinker --execute="Illuminate\\\\Support\\\\Facades\\\\RateLimiter::clear('register:127.0.0.1');"`);
    });

    test('outlet apotek di toko kelontong punya katalog dan menu resep sendiri', async ({ page }) => {
        await registerGroceryShop(page);

        await page.goto('/pengaturan/outlet');
        await page.getByRole('button', { name: 'Tambah Outlet' }).first().click();
        await page.fill('#wizard-outlet-name', 'Apotek Sehat');
        await page.fill('#wizard-outlet-code', 'APT');
        await page.locator('select[wire\\:model\\.live="storeType"]').selectOption('apotek', { force: true });
        await expect(page.getByText('dibuat khusus untuk outlet ini')).toBeVisible();

        await page.getByRole('button', { name: 'Lanjut: Pengaturan' }).click();
        await page.getByRole('button', { name: 'Lanjut: Akses Kasir' }).click();
        await page.getByRole('button', { name: 'Lanjut: Ringkasan' }).click();
        await expect(page.getByText('fitur khusus, dengan produk contoh')).toBeVisible();
        await page.getByRole('button', { name: 'Simpan & Buka Outlet' }).click();
        await expect(page.getByText('Outlet ditambahkan.')).toBeVisible({ timeout: 30000 });

        await switchOutlet(page, 'Apotek Sehat');
        await page.goto('/dashboard');
        await page.getByRole('button', { name: 'Penjualan', exact: true }).click();
        await expect(page.getByRole('link', { name: 'Resep', exact: true }).first()).toBeVisible();
        await openCashier(page);
        await expect(page.locator('[data-product]', { hasText: 'Paracetamol 500mg Tablet' }).first()).toBeVisible({ timeout: 15000 });

        await switchOutlet(page, 'Toko E2E');
        await page.goto('/dashboard');
        await page.getByRole('button', { name: 'Penjualan', exact: true }).click();
        await expect(page.getByRole('link', { name: 'Riwayat Transaksi' }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Resep', exact: true })).toHaveCount(0);
        await openCashier(page);
        await expect(page.locator('[data-product]').first()).toBeVisible({ timeout: 15000 });
        await expect(page.locator('[data-product]', { hasText: 'Paracetamol 500mg Tablet' })).toHaveCount(0);
    });
});
