<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi sambutan saat pemilik berhasil mendaftarkan toko baru ke SaaS.
 */
class WelcomeTenantNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public int $trialDays = 14
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shopName = $this->tenant->name;
        $url = url(route('onboarding'));

        return (new MailMessage)
            ->subject("Selamat Datang di Kasir Toko - Toko {$shopName} Siap Digunakan!")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Selamat! Pendaftaran toko **{$shopName}** telah berhasil.")
            ->line("Akun Anda telah aktif dengan masa coba gratis selama **{$this->trialDays} hari**.")
            ->line('Mulai persiapkan tokomu dengan mengatur produk, kasir, dan mulai melayani penjualan pertama!')
            ->action('Mulai Persiapan Toko', $url)
            ->line('Jika butuh bantuan, tim dukungan kami selalu siap membantu.');
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, tenant_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'sparkles',
            'color' => 'sky',
            'message' => "Selamat datang! Toko {$this->tenant->name} berhasil dibuat dengan trial {$this->trialDays} hari.",
            'url' => route('onboarding'),
            'tenant_id' => $this->tenant->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
