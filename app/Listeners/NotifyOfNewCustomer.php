<?php

namespace App\Listeners;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreatedNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Kabari pengelola master data lain (bukan si pembuat) saat ada pelanggan baru.
 */
class NotifyOfNewCustomer
{
    public function handle(Customer $customer): void
    {
        $recipients = User::query()
            ->where(fn ($query) => $query
                ->whereHas('roles.permissions', fn ($query) => $query->where('name', 'master-data.manage'))
                ->orWhereHas('permissions', fn ($query) => $query->where('name', 'master-data.manage')))
            ->when(Auth::id(), fn ($query, $id) => $query->whereKeyNot($id))
            ->get();

        Notification::send($recipients, new CustomerCreatedNotification($customer, Auth::user()?->name));
    }
}
