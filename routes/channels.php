<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Event realtime per toko (App\Events\Concerns\BroadcastsToDashboard): hanya pengguna toko itu yang boleh mendengarkan.
Broadcast::channel('tenant.{tenantId}.dashboard', function ($user, $tenantId) {
    return $user->tenant_id !== null && (int) $user->tenant_id === (int) $tenantId;
});
