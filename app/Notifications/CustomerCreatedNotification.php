<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Di-queue per channel supaya Reverb yang mati tidak ikut menggagalkan notifikasi lonceng
 * maupun penyimpanan pelanggannya.
 */
class CustomerCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Customer $customer, public ?string $createdBy = null) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'user-plus',
            'color' => 'sky',
            'message' => "Pelanggan baru {$this->customer->name} ({$this->customer->code})"
                .($this->createdBy ? " ditambahkan oleh {$this->createdBy}." : ' ditambahkan.'),
            'url' => route('master-data.customers.show', $this->customer),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
