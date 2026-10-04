<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mengeluarkan akun dari semua perangkat: sesi web, cookie "ingat saya", dan token API mobile.
 */
class UserSessionRevoker
{
    /**
     * @param  list<int>  $userIds
     */
    public function revoke(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->whereIn('user_id', $userIds)
                ->delete();
        }

        User::query()->withoutGlobalScopes()->whereIn('id', $userIds)->each(function (User $user) {
            $user->tokens()->delete();
            // Cookie "ingat saya" berisi remember_token, jadi menggantinya membatalkan cookie lama.
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        });
    }
}
