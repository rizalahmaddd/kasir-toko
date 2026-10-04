<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

class SettingPolicy
{
    public function update(User $user, ?Setting $setting = null): bool
    {
        return $user->can('settings.company.manage');
    }
}
