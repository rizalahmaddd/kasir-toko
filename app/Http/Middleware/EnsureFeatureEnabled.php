<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup route milik fitur yang dimatikan di Pengaturan Fitur. Didaftarkan juga sebagai
 * persistent middleware Livewire, supaya aksi di halaman yang sudah terbuka ikut ditolak.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        // Tamu dibiarkan lewat supaya middleware auth di route yang mengarahkannya ke login.
        abort_unless(
            $request->user() === null || Features::allowsRoute($request->route()?->getName()),
            403,
            __('Fitur ini sedang dinonaktifkan.'),
        );

        return $next($request);
    }
}
