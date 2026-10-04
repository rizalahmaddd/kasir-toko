<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public string $stage // 'd7', 'd3', 'd1', 'd0', 'grace'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planLabel = $this->tenant->planLabel();
        $shopName = $this->tenant->name;
        $url = url(route('settings.subscription'));

        $subject = match ($this->stage) {
            'd7' => "Pemberitahuan: Masa aktif toko {$shopName} berakhir dalam 7 hari",
            'd3' => "Peringatan: Masa aktif toko {$shopName} berakhir dalam 3 hari",
            'd1' => "PENTING: Masa aktif toko {$shopName} berakhir besok!",
            'd0' => "Masa aktif toko {$shopName} telah berakhir hari ini",
            'grace' => "Perhatian: Masa tenggang toko {$shopName} akan segera habis",
            default => "Informasi masa aktif toko {$shopName}",
        };

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Masa aktif untuk toko **{$shopName}** (Paket {$planLabel}) memerlukan perhatian Anda.");

        if ($this->stage === 'd0' || $this->stage === 'grace') {
            $message->line('Operasional kasir toko Anda dapat terhenti jika langganan tidak segera diperpanjang.')
                ->line('Anda dapat memperpanjang paket langganan secara instan menggunakan QRIS.');
        } else {
            $days = match ($this->stage) {
                'd7' => 7,
                'd3' => 3,
                'd1' => 1,
                default => 3,
            };
            $message->line("Masa aktif toko Anda tersisa **{$days} hari lagi**.")
                ->line('Perpanjang sekarang untuk memastikan kasir Anda tetap dapat melayani transaksi pelanggan tanpa gangguan.');
        }

        return $message
            ->action('Perpanjang via QRIS Sekarang', $url)
            ->line('Terima kasih telah mempercayakan operasional bisnis Anda kepada kami.');
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, tenant_id: int, tenant_name: string, stage: string, plan: ?string, ends_at: ?string}
     */
    public function toArray(object $notifiable): array
    {
        $days = match ($this->stage) {
            'd7' => 7,
            'd3' => 3,
            'd1' => 1,
            'd0' => 0,
            default => null,
        };

        $message = match ($this->stage) {
            'd0' => "Masa aktif toko {$this->tenant->name} telah berakhir hari ini. Segera perpanjang agar kasir tetap aktif.",
            'grace' => "Perhatian: Masa tenggang toko {$this->tenant->name} segera habis. Kasir akan segera terkunci.",
            default => "Masa aktif toko {$this->tenant->name} tersisa {$days} hari lagi. Perpanjang paket sekarang.",
        };

        return [
            'icon' => in_array($this->stage, ['d0', 'grace'], true) ? 'alert-triangle' : 'clock',
            'color' => in_array($this->stage, ['d0', 'grace'], true) ? 'rose' : 'amber',
            'message' => $message,
            'url' => route('settings.subscription'),
            'tenant_id' => $this->tenant->id,
            'tenant_name' => $this->tenant->name,
            'stage' => $this->stage,
            'plan' => $this->tenant->plan,
            'ends_at' => $this->tenant->accessEndsAt()?->toDateTimeString(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
