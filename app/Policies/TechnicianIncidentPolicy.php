<?php

namespace App\Policies;

use App\Models\TechnicianIncident;
use App\Models\User;

class TechnicianIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) config('crm.v2_enabled') && ! $user->trashed() && ! $user->isExternal()
            && ! $user->must_change_password && ($user->admin || in_array($user->role, [0, 1], true));
    }

    public function view(User $user, TechnicianIncident $incident): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, TechnicianIncident $incident): bool
    {
        return $this->viewAny($user);
    }

    public function reviewBilling(User $user): bool
    {
        return $this->viewAny($user) && ($user->admin || $user->role === 0 || $user->can_manage_billing);
    }
}
