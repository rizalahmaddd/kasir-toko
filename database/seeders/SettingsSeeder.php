<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Nilai awal branding aplikasi dan kop surat dokumen cetak. Ini data awal yang bisa diubah lewat
 * halaman Pengaturan Perusahaan, bukan teks di kode aplikasi.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::putMany([
            'app_name' => config('app.name'),
            'app_tagline' => null,
            'company_name' => config('app.name'),
            'company_tagline' => null,
            'company_address' => null,
            'company_phone' => null,
        ]);
    }
}
