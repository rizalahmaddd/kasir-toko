<?php

namespace App\Notifications;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Notifikasi saat Admin Platform menambahkan masa aktif atau bonus paket Pro ke toko.
 */
class SubscriptionBonusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public CarbonInterface|Carbon $accessEndsAt,
        public ?string $note = null,
        public ?int $addedDays = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planLabel = $this->tenant->planLabel();
        $shopName = $this->tenant->name;
        $endsAtFormatted = $this->accessEndsAt->translatedFormat('d F Y');
        $url = url(route('settings.subscription'));

        $mail = (new MailMessage)
            ->subject("Kabar Gembira: Tambahan Masa Aktif untuk Toko {$shopName}!")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Kabar baik! Toko **{$shopName}** telah menerima pembaruan masa aktif langganan.");

        if ($this->addedDays) {
            $mail->line("Tambahan masa aktif: **{$this->addedDays} hari** (Paket {$planLabel}).");
        } else {
            $mail->line("Paket langganan: **{$planLabel}**.");
        }

        $mail->line("Masa aktif toko Anda kini berlaku hingga **{$endsAtFormatted}**.");

        if ($this->note) {
            $mail->line("Catatan: *{$this->note}*");
        }

        return $mail
            ->action('Cek Status Toko', $url)
            ->line('Terima kasih telah menjadi bagian dari Kasir Toko.');
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, tenant_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $endsAtFormatted = $this->accessEndsAt->translatedFormat('d M Y');
        $planLabel = $this->tenant->planLabel();

        $message = $this->addedDays
            ? "Kabar gembira! Toko Anda mendapat tambahan {$this->addedDays} hari paket {$planLabel} (aktif s.d. {$endsAtFormatted})."
            : "Masa aktif toko diperbarui ke paket {$planLabel} (aktif s.d. {$endsAtFormatted}).";

        return [
            'icon' => 'gift',
            'color' => 'indigo',
            'message' => $message,
            'url' => route('settings.subscription'),
            'tenant_id' => $this->tenant->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
