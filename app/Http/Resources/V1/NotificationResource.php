<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Notifikasi aplikasi. `target` menunjuk data yang perlu dibuka di aplikasi (mis. data pelanggan
 * yang berubah); null bila tidak ada padanannya di API.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * Web page route => API resource type, for deep links.
     */
    private const TARGETS = [
        'master-data.customers.show' => 'customer',
        'sales.show' => 'sale',
        'shifts.show' => 'shift',
        'inventory.stock' => 'inventory',
        'master-data.products' => 'products',
        'receivables.index' => 'receivables',
        'settings.subscription' => 'subscription',
    ];

    /**
     * @return array{id: string, type: string, message: string|null, icon: string|null, color: string|null, target: array{type: string, id: int|null}|null, read_at: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => class_basename($this->type),
            'message' => $this->data['message'] ?? null,
            'icon' => $this->data['icon'] ?? null,
            'color' => $this->data['color'] ?? null,
            'target' => self::targetOf($this->data['url'] ?? null),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array{type: string, id: int|null}|null
     */
    public static function targetOf(?string $url): ?array
    {
        if (! $url) {
            return null;
        }

        try {
            $route = Route::getRoutes()->match(Request::create((string) parse_url($url, PHP_URL_PATH)));
        } catch (HttpException) {
            return null;
        }

        $type = self::TARGETS[$route->getName()] ?? null;
        $id = collect($route->parameters())->first();

        return $type ? ['type' => $type, 'id' => is_numeric($id) ? (int) $id : null] : null;
    }
}
