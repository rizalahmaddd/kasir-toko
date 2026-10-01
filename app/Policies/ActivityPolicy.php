<?php

namespace App\Policies;

use App\Models\User;

/**
 * Log aktivitas berisi jejak audit sensitif (siapa mengubah data apa, login, ekspor). Ringkasan
 * di dashboard memakai ability yang sama supaya tautan "Lihat semua" konsisten.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reports.activity.view');
    }
}
