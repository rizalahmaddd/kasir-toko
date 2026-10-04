<?php

namespace App\Notifications;

use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Notifikasi saat pembayaran invoice langganan via QRIS berhasil diverifikasi.
 */
class SubscriptionPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SubscriptionInvoice $invoice,
        public Tenant $tenant,
        public CarbonInterface|Carbon|null $accessEndsAt = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planLabel = $this->invoice->planLabel();
        $shopName = $this->tenant->name;
        $amountFormatted = 'Rp '.number_format($this->invoice->amount, 0, ',', '.');
        $url = url(route('settings.subscription'));

        $mail = (new MailMessage)
            ->subject("Pembayaran Berhasil: Paket {$planLabel} untuk {$shopName} Telah Aktif")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Terima kasih! Pembayaran invoice **{$this->invoice->invoice_number}** sebesar **{$amountFormatted}** telah kami terima.");

        if ($this->accessEndsAt) {
            $endsAtFormatted = $this->accessEndsAt->translatedFormat('d F Y');
            $mail->line("Paket **{$planLabel}** untuk toko **{$shopName}** telah aktif dan masa berlaku diperpanjang hingga **{$endsAtFormatted}**.");
        } else {
            $mail->line("Paket **{$planLabel}** untuk toko **{$shopName}** telah aktif permanen (tanpa batas waktu).");
        }

        return $mail
            ->action('Lihat Detail Langganan', $url)
            ->line('Terima kasih telah mempercayakan bisnis Anda bersama Kasir Toko.');
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, invoice_id: int, tenant_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $planLabel = $this->invoice->planLabel();

        if ($this->accessEndsAt) {
            $endsAtFormatted = $this->accessEndsAt->translatedFormat('d M Y');
            $message = "Pembayaran berhasil! Paket {$planLabel} aktif s.d. {$endsAtFormatted} (Inv: {$this->invoice->invoice_number}).";
        } else {
            $message = "Pembayaran berhasil! Paket {$planLabel} aktif permanen selamanya (Inv: {$this->invoice->invoice_number}).";
        }

        return [
            'icon' => 'badge-check',
            'color' => 'emerald',
            'message' => $message,
            'url' => route('settings.subscription'),
            'invoice_id' => $this->invoice->id,
            'tenant_id' => $this->tenant->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
