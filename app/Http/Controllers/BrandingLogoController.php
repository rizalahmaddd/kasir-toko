<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logo aplikasi dari disk privat. Sengaja tanpa login: logo juga tampil di halaman login dan
 * sebagai favicon. URL-nya berganti setiap logo diunggah ulang (Branding::logoUrl()), jadi
 * aman di-cache lama oleh browser.
 */
class BrandingLogoController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $path = Branding::logoPath();

        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
