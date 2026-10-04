<?php

namespace App\Notifications;

use App\Models\CashShift;
use App\Models\User;
use App\Support\NumberFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi saat shift kasir ditutup, dengan penanda khusus jika ada selisih kas.
 */
class CashShiftClosedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CashShift $shift,
        public User $closer
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, shift_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $diff = (int) $this->shift->cash_difference;
        $cashierName = $this->shift->user?->name ?? 'Kasir';
        $shiftNumber = $this->shift->number ?? "#{$this->shift->id}";

        if ($diff !== 0) {
            $diffFormatted = NumberFormatter::currency(abs($diff));
            $diffType = $diff > 0 ? "lebih {$diffFormatted}" : "kurang {$diffFormatted}";
            $message = "Perhatian: Shift {$shiftNumber} ({$cashierName}) ditutup oleh {$this->closer->name} dengan selisih kas ({$diffType})!";
            $color = 'rose';
            $icon = 'alert-triangle';
        } else {
            $countedFormatted = NumberFormatter::currency((int) $this->shift->counted_cash);
            $message = "Shift {$shiftNumber} ({$cashierName}) telah ditutup oleh {$this->closer->name}. Kas fisik: {$countedFormatted} (sesuai).";
            $color = 'emerald';
            $icon = 'wallet';
        }

        return [
            'icon' => $icon,
            'color' => $color,
            'message' => $message,
            'url' => route('shifts.show', $this->shift),
            'shift_id' => $this->shift->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
