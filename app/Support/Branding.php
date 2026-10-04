<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Satu-satunya sumber identitas aplikasi yang tampil di layar (judul tab, sidebar, halaman login,
 * logo, favicon, kop dokumen). Semua nilainya diatur di Pengaturan Perusahaan; view dan
 * komponen tidak boleh menulis nama aplikasi/perusahaan sendiri.
 */
class Branding
{
    public const APP_NAME_KEY = 'app_name';

    public const APP_TAGLINE_KEY = 'app_tagline';

    public const LOGO_KEY = 'app_logo';

    /**
     * Nama aplikasi di sidebar, halaman login, dan judul tab. Belum diatur: pakai nama perusahaan,
     * lalu APP_NAME di .env sebagai jaring terakhir untuk instalasi yang settings-nya masih kosong.
     */
    public static function appName(): string
    {
        return Setting::get(self::APP_NAME_KEY) ?: Setting::get('company_name') ?: (string) config('app.name');
    }

    public static function tagline(): ?string
    {
        return Setting::get(self::APP_TAGLINE_KEY) ?: null;
    }

    /**
     * Nama resmi untuk kop dokumen cetak dan ekspor.
     */
    public static function companyName(): string
    {
        return Setting::get('company_name') ?: self::appName();
    }

    public static function logoPath(): ?string
    {
        return Setting::get(self::LOGO_KEY) ?: null;
    }

    /**
     * Nama file logo selalu unik per unggahan, jadi URL-nya otomatis berganti saat logo diganti
     * dan browser tidak menampilkan logo lama dari cache.
     */
    public static function logoUrl(): ?string
    {
        $path = self::logoPath();

        return $path ? route('branding.logo', ['v' => basename($path)]) : null;
    }

    public static function pageTitle(?string $title = null): string
    {
        return filled($title) ? $title.' - '.self::appName() : self::appName();
    }
}
