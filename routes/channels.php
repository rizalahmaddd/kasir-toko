<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Channel bersama untuk event realtime (App\Events\Concerns\BroadcastsToDashboard): aplikasi
// single-tenant, jadi siapa pun yang sudah login boleh mendengarkan.
Broadcast::channel('dashboard', function ($user) {
    return $user !== null;
});
