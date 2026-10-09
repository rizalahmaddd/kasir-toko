import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

// Opname satu kategori: hitung tiga barang (satu selisih), periksa, beri alasan, selesaikan, lalu
// kartu stok menampilkan mutasi opname bernomor dokumen.

function artisan(command) {
    return execSync(`php artisan ${command}`, { cwd: process.cwd(), encoding: 'utf-8' });
}

async function registerGroceryShop(page) {
    const rand = Math.floor(Math.random() * 900000) + 100000;
    const username = `e2e_${rand}`;

    await page.goto('/daftar');
    await page.fill('#shop_name', `Toko Opname ${rand}`);
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
}

function parseQuantity(text) {
    return Number(text.trim().split(' ')[0].replace(/\./g, '').replace(',', '.'));
}

test.describe('Stok opname', () => {
    test.beforeEach(() => {
        artisan(`tinker --execute="Illuminate\\\\Support\\\\Facades\\\\RateLimiter::clear('register:127.0.0.1');"`);
    });

    test('opname kategori dihitung, diperiksa, dan diselesaikan', async ({ page }) => {
        test.setTimeout(120000);
        await registerGroceryShop(page);

        await page.goto('/stok/opname');
        await page.getByRole('button', { name: 'Mulai Opname' }).first().click();
        await page.getByRole('button', { name: 'Kategori tertentu' }).click();
        await page.locator('input[wire\\:model="categoryIds"]').first().check();
        await page.getByRole('button', { name: 'Mulai Menghitung' }).click();
        await expect(page).toHaveURL(/stok\/opname\/\d+/, { timeout: 20000 });

        const number = (await page.locator('h2.font-mono').innerText()).trim();
        expect(number).toMatch(/^OPN-/);

        const rows = page.locator('tbody tr');
        await expect(rows.first()).toBeVisible();
        const count = Math.min(3, await rows.count());

        for (let index = 0; index < count; index++) {
            const row = rows.nth(index);
            const system = parseQuantity(await row.locator('td').nth(1).innerText());
            const counted = index === 0 ? system + 2 : system;
            await row.getByRole('button', { name: /^Isi hitungan/ }).click();
            await page.fill('#entry-base', String(counted));
            await page.getByRole('button', { name: 'Simpan Hitungan' }).click();
            await expect(page.locator('#entry-base')).toBeHidden({ timeout: 10000 });
        }

        await page.getByRole('button', { name: 'Lanjut ke Periksa' }).click();
        await expect(page.getByText('Barang berubah').first()).toBeVisible({ timeout: 15000 });

        const reason = page.locator('select[aria-label^="Alasan selisih"]').first();
        if (await reason.count()) {
            await reason.selectOption('damaged', { force: true });
        }

        await page.getByRole('button', { name: 'Selesaikan & sesuaikan stok' }).first().click();
        await page.locator('[x-data] button', { hasText: 'Selesaikan & sesuaikan stok' }).last().click();
        await expect(page.getByText('Buat opname koreksi')).toBeVisible({ timeout: 20000 });

        await page.goto('/stok?tab=movements&type=opname');
        await expect(page.getByText(number).first()).toBeVisible({ timeout: 15000 });
    });
});
