<?php

use App\Support\CurrentOutlet;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Event realtime per toko (App\Events\Concerns\BroadcastsToDashboard): hanya pengguna toko itu yang boleh mendengarkan.
Broadcast::channel('tenant.{tenantId}.dashboard', function ($user, $tenantId) {
    return $user->tenant_id !== null && (int) $user->tenant_id === (int) $tenantId;
});

// Event per outlet (App\Events\Concerns\BroadcastsToOutlet): pengguna terbatas hanya mendengar outlet yang ditugaskan.
Broadcast::channel('tenant.{tenantId}.outlet.{outletId}', function ($user, $tenantId, $outletId) {
    if ($user->tenant_id === null || (int) $user->tenant_id !== (int) $tenantId) {
        return false;
    }

    $outlets = app(CurrentOutlet::class);

    if (! $outlets->hasAccessLoaded()) {
        $outlets->loadAccess($user);
    }

    return $outlets->canAccess((int) $outletId);
});
