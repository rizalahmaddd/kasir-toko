<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\DashboardResource;
use App\Models\Customer;
use App\Support\Features;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

#[ApiTag('Beranda', 'Akun')]
class DashboardController extends Controller
{
    /**
     * Beranda: ringkasan angka.
     *
     * Hanya angka yang boleh dilihat akun ini dan fiturnya aktif yang dikirim; `target` menyebut
     * daftar yang dibuka saat kartu diketuk.
     */
    public function __invoke(Request $request): DashboardResource
    {
        $user = $request->user();
        $stats = [];

        if (Features::enabled('master-data.customers') && $user->can('view-master-data')) {
            $stats[] = [
                'key' => 'customers_active',
                'label' => 'Pelanggan aktif',
                'count' => Customer::where('is_active', true)->count(),
                'target' => ['endpoint' => '/api/v1/master-data/customers', 'query' => ['is_active' => '1']],
            ];
        }

        return new DashboardResource([
            'date' => today()->toDateString(),
            'unread_notifications' => $user->unreadNotifications()->get()
                ->filter(fn (DatabaseNotification $notification) => Features::allowsUrl($notification->data['url'] ?? null))
                ->count(),
            'stats' => $stats,
        ]);
    }
}
