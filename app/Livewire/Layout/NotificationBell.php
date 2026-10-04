<?php

namespace App\Livewire\Layout;

use App\Support\Features;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Lonceng notifikasi: dengar channel privat notifikasi Laravel milik user yang sedang login lewat
 * Echo, jadi badge & daftar update sendiri begitu ada notifikasi baru tanpa reload halaman.
 */
class NotificationBell extends Component
{
    public function getListeners(): array
    {
        return [
            'echo-notification:App.Models.User.'.Auth::id() => '$refresh',
        ];
    }

    /**
     * Klik notifikasi: tandai dibaca DAN pindah ke halaman yang dimaksud notifikasi itu
     * (disimpan sebagai 'url' di data notifikasi, lihat App\Notifications\*), bukan cuma
     * menandainya dibaca tanpa aksi lanjutan.
     */
    public function open(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->where('id', $notificationId)->first();

        if (! $notification || ! $this->isVisible($notification)) {
            return;
        }

        $notification->markAsRead();

        if ($url = $notification->data['url'] ?? null) {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '/';
            if (! str_starts_with($path, '/')) {
                $path = '/'.$path;
            }
            if (! empty($parsed['query'])) {
                $path .= '?'.$parsed['query'];
            }
            if (! empty($parsed['fragment'])) {
                $path .= '#'.$parsed['fragment'];
            }

            $this->redirect($path, navigate: true);
        }
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->filter(fn (DatabaseNotification $notification) => $this->isVisible($notification))->markAsRead();
    }

    public function render()
    {
        return view('livewire.layout.notification-bell', [
            'notifications' => Auth::user()->notifications()->latest()->take(50)->get()->filter(fn (DatabaseNotification $notification) => $this->isVisible($notification))->take(10),
            'unreadCount' => Auth::user()->unreadNotifications->filter(fn (DatabaseNotification $notification) => $this->isVisible($notification))->count(),
        ]);
    }

    /**
     * Notifikasi yang menunjuk ke fitur yang dimatikan di Pengaturan Fitur tidak ditampilkan.
     */
    protected function isVisible(DatabaseNotification $notification): bool
    {
        return Features::allowsUrl($notification->data['url'] ?? null);
    }
}
