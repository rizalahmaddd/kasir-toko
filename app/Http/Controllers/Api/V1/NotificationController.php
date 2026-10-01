<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\NotificationResource;
use App\Http\Resources\V1\UnreadCountResource;
use App\Support\Features;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

#[ApiTag('Notifikasi', 'Akun', 'Notifikasi yang sama dengan lonceng di web (mis. pelanggan baru, backup selesai). Notifikasi milik fitur yang dimatikan disembunyikan.')]
class NotificationController extends Controller
{
    /**
     * Daftar notifikasi.
     *
     * `meta.unread_count` berisi jumlah yang belum dibaca.
     */
    #[ApiQuery('unread', 'boolean', 'Hanya yang belum dibaca.')]
    #[ApiResponse(NotificationResource::class, collection: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $request->user()->notifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->limit(100)
            ->get()
            ->filter(fn (DatabaseNotification $notification) => Features::allowsUrl($notification->data['url'] ?? null))
            ->take($this->perPage($request, 30))
            ->values();

        return NotificationResource::collection($notifications)->additional(['meta' => ['unread_count' => $this->countUnread($request)]]);
    }

    /**
     * Jumlah notifikasi belum dibaca.
     *
     * Ringan, cocok untuk badge yang di-polling.
     */
    public function unreadCount(Request $request): UnreadCountResource
    {
        return new UnreadCountResource(['unread_count' => $this->countUnread($request)]);
    }

    /**
     * Tandai satu notifikasi dibaca.
     */
    public function markAsRead(Request $request, string $notification): NotificationResource
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return new NotificationResource($record);
    }

    /**
     * Tandai semua notifikasi dibaca.
     */
    public function markAllAsRead(Request $request): Response
    {
        $request->user()->unreadNotifications()->get()
            ->filter(fn (DatabaseNotification $notification) => Features::allowsUrl($notification->data['url'] ?? null))
            ->markAsRead();

        return response()->noContent();
    }

    private function countUnread(Request $request): int
    {
        return $request->user()->unreadNotifications()->get()
            ->filter(fn (DatabaseNotification $notification) => Features::allowsUrl($notification->data['url'] ?? null))
            ->count();
    }
}
