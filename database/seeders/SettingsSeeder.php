<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

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

        $this->seedLogo();
    }

    /**
     * Publikasikan logo demo dari repo ke local storage agar langsung muncul di Pengaturan
     * Perusahaan saat fresh install. Logo ini bisa diganti kapan saja lewat UI.
     */
    private function seedLogo(): void
    {
        if (Setting::get(Branding::LOGO_KEY)) {
            return;
        }

        $source = database_path('seeders/images/branding/logo.png');

        if (! is_file($source)) {
            return;
        }

        $path = 'branding/logo-demo.png';
        Storage::disk('local')->put($path, file_get_contents($source));
        Setting::put(Branding::LOGO_KEY, $path);
    }
}
