<?php

namespace App\Policies;

use App\Models\User;

class TechnicianAbsencePolicy
{
    public function createForManager(User $user): bool
    {
        return ! $user->trashed() && ! $user->isExternal() && ($user->admin || $user->role === 0);
    }

    public function create(User $user): bool
    {
        return config('crm.v2_enabled') && ! $user->trashed() && ! $user->isExternal()
            && ! $user->must_change_password && ($user->admin || in_array($user->role, [0, 1], true));
    }
}
