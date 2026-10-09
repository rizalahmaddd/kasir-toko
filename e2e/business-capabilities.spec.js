import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

// Alur kapabilitas usaha dari sisi pengguna: toko baru memilih preset, lalu kasir memakai fitur khas usahanya.

function artisan(command) {
    return execSync(`php artisan ${command}`, { cwd: process.cwd(), encoding: 'utf-8' });
}

async function registerShop(page, presetLabel) {
    const rand = Math.floor(Math.random() * 900000) + 100000;
    const username = `e2e_${rand}`;

    await page.goto('/daftar');
    await page.fill('#shop_name', `Toko E2E ${rand}`);
    await page.fill('#name', 'Pemilik E2E');
    await page.fill('#username', username);
    await page.fill('#email', `${username}@kasirtoko.test`);
    await page.fill('#password', 'password123');
    await page.fill('#password_confirmation', 'password123');
    await page.check('#agree_terms');
    await page.click('button[type="submit"]');

    await expect(page).toHaveURL(/persiapan-toko/, { timeout: 15000 });
    await page.waitForLoadState('networkidle');
    await page.locator('button', { hasText: presetLabel }).first().click({ timeout: 20000 });
    await page.getByRole('button', { name: 'Terapkan Preset' }).click();
    await expect(page).toHaveURL(/dashboard/, { timeout: 30000 });

    return `Toko E2E ${rand}`;
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

async function addTile(page, name) {
    await page.locator('[data-product]', { hasText: name }).first().click();
}

async function payExact(page) {
    await page.getByRole('button', { name: /^Bayar/ }).first().click();
    await page.getByRole('button', { name: 'Uang Pas' }).click();
    await page.getByRole('button', { name: 'Selesaikan Pembayaran' }).click();
    await expect(page.getByText('Transaksi tersimpan')).toBeVisible({ timeout: 15000 });
}

test.describe('Kapabilitas usaha', () => {
    test.beforeEach(() => {
        artisan(`tinker --execute="Illuminate\\\\Support\\\\Facades\\\\RateLimiter::clear('register:127.0.0.1');"`);
    });

    test('kafe: pilihan tambahan, open bill per meja, layar dapur, lalu pelunasan', async ({ page }) => {
        await registerShop(page, 'Kafe / Coffee Shop');
        await openCashier(page);

        await addTile(page, 'Cafe Latte');
        await expect(page.getByText('Pilih varian pesanan')).toBeVisible();
        await page.getByRole('button', { name: /^Large/ }).click();
        await page.getByRole('button', { name: /^Tambah \(\+/ }).click();
        await expect(page.getByText('Large', { exact: false }).first()).toBeVisible();

        await page.getByPlaceholder('Meja', { exact: true }).fill('7');
        await page.getByRole('button', { name: 'Tunda', exact: true }).click();
        await page.getByRole('button', { name: 'Tunda Transaksi' }).click();
        await expect(page.getByText('Transaksi ditunda', { exact: false }).or(page.getByText('open bill', { exact: false })).first()).toBeVisible({ timeout: 10000 });

        await page.goto('/dapur');
        await expect(page.getByText('Meja 7').first()).toBeVisible();
        await expect(page.getByText('Cafe Latte').first()).toBeVisible();
        await page.getByRole('button', { name: 'Tandai Selesai' }).first().click();
        await expect(page.getByText('Tidak ada pesanan menunggu')).toBeVisible({ timeout: 10000 });

        await openCashier(page);
        await page.getByTitle('Transaksi tertunda').click();
        await page.getByText('Meja 7').first().click();
        await expect(page.locator('aside[aria-label="Keranjang"]').getByText('Cafe Latte')).toBeVisible({ timeout: 10000 });
        await payExact(page);
    });

    test('apotek: stok masuk per batch, obat keras dengan resep, lalu struk', async ({ page }) => {
        await registerShop(page, 'Apotek / Toko Obat');

        await page.goto('/stok?search=Amoxicillin');
        await page.getByRole('button', { name: 'Masuk', exact: true }).first().click();
        await page.fill('#adjustQuantity', '100');
        await page.fill('#adjustBatchNumber', 'E2E-AMX');
        await page.getByRole('button', { name: 'Simpan Mutasi Stok' }).click();
        await expect(page.locator('#adjustQuantity')).toBeHidden({ timeout: 10000 });

        await openCashier(page);
        await addTile(page, 'Amoxicillin');
        await page.getByRole('button', { name: /^Bayar/ }).first().click();
        await expect(page.getByText('Tautkan resepnya dulu', { exact: false }).first()).toBeVisible();

        await page.getByRole('button', { name: 'Isi langsung' }).click();
        await page.fill('#rx-doctor', 'Sari');
        await page.fill('#rx-patient', 'Budi');
        await page.getByRole('button', { name: 'Pakai Resep Ini' }).click();
        await payExact(page);
    });

    test('multi-outlet: toko Pro membuat outlet kedua, transfer stok, lalu menjual di outlet itu', async ({ page }) => {
        const shop = await registerShop(page, 'Minimarket');
        artisan(`tinker --execute="App\\\\Models\\\\Tenant::where('name', '${shop}')->update(['plan' => 'pro', 'subscription_ends_at' => now()->addMonth()]);"`);

        await page.goto('/stok?search=Teh+Botol');
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'Masuk', exact: true }).first().click();
        await page.fill('#adjustQuantity', '24');
        await page.getByRole('button', { name: 'Simpan Mutasi Stok' }).click();
        await expect(page.locator('#adjustQuantity')).toBeHidden({ timeout: 10000 });

        await page.goto('/pengaturan/outlet');
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'Tambah Outlet' }).first().click();
        await page.fill('#wizard-outlet-name', 'Cabang E2E');
        await page.fill('#wizard-outlet-code', 'CBE');
        await page.getByRole('button', { name: 'Lanjut: Pengaturan' }).click();
        await page.getByRole('button', { name: 'Lanjut: Akses Kasir' }).click();
        await page.getByRole('button', { name: 'Lanjut: Ringkasan' }).click();
        await page.getByRole('button', { name: 'Simpan & Buka Outlet' }).click();
        await expect(page.getByText('Outlet ditambahkan.')).toBeVisible({ timeout: 30000 });

        await page.goto('/stok/transfer');
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'Transfer Baru' }).click();
        await page.locator('#transfer-to').selectOption({ label: 'Cabang E2E' });
        await page.fill('#transfer-search', 'Teh Botol');
        await page.locator('button', { hasText: 'Teh Botol 350ml' }).first().click();
        await page.getByLabel('Jumlah Teh Botol 350ml').fill('5');
        await page.getByRole('button', { name: 'Pindahkan Stok' }).click();
        await expect(page.getByText('TRF', { exact: false }).first()).toBeVisible({ timeout: 10000 });

        await page.getByRole('button', { name: 'Ganti outlet' }).click();
        await page.getByRole('option', { name: /Cabang E2E/ }).click();
        await expect(page.getByRole('button', { name: 'Ganti outlet' })).toContainText('Cabang E2E', { timeout: 10000 });

        await openCashier(page);
        await addTile(page, 'Teh Botol 350ml');
        await payExact(page);

        await page.goto('/stok?search=Teh+Botol');
        await expect(page.getByText('4 btl').first()).toBeVisible({ timeout: 10000 });
    });
});
