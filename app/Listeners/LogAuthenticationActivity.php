<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Arr;

class LogAuthenticationActivity
{
    public const LOG_NAME = 'auth';

    public function handleLogin(Login $event): void
    {
        activity(self::LOG_NAME)
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('login')
            ->withProperties(['remember' => $event->remember])
            ->log($event->remember ? 'Login lewat sesi "ingat saya".' : 'Login berhasil.');
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        activity(self::LOG_NAME)
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('logout')
            ->log('Logout.');
    }

    public function handleFailed(Failed $event): void
    {
        // Password tidak boleh ikut tersimpan, hanya identitas yang dicoba.
        $attempted = Arr::except($event->credentials, ['password']);

        activity(self::LOG_NAME)
            ->causedByAnonymous()
            ->when($event->user, fn ($logger) => $logger->performedOn($event->user))
            ->event('login_failed')
            ->withProperties(['credentials' => $attempted])
            ->log('Login gagal untuk "'.implode(', ', $attempted).'".');
    }

    public function handleLockout(Lockout $event): void
    {
        activity(self::LOG_NAME)
            ->causedByAnonymous()
            ->event('lockout')
            ->log('Login diblokir sementara karena terlalu banyak percobaan gagal.');
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        activity(self::LOG_NAME)
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('password_reset')
            ->log('Password direset lewat tautan lupa password.');
    }
}
