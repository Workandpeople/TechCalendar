<?php

namespace App\Services;

use App\Models\User;

class UserBillingPermission
{
    public static function resolve(array $payload, ?User $user = null): bool
    {
        if ((int) $payload['role'] !== 1 || $user?->isExternal()) {
            return false;
        }

        // Preserve only an explicit Planning grant, never an implicit role privilege.
        return (bool) ($payload['can_manage_billing']
            ?? ($user?->role === 1 && $user->can_manage_billing));
    }
}
