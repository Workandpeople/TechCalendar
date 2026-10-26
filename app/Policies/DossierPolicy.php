<?php

namespace App\Policies;

use App\Models\Dossier;
use App\Models\User;
use App\Services\Crm\ClientAccess;

class DossierPolicy
{
    public function viewAny(User $user): bool
    {
        return config('crm.v2_enabled') && ! $user->trashed()
            && ! $user->isExternal() && ! $user->must_change_password && ($user->admin || in_array($user->role, [0, 1], true));
    }

    public function view(User $user, Dossier $dossier): bool
    {
        return $user->isExternal()
            ? app(ClientAccess::class)->viewDossier($user, $dossier)
            : $this->viewAny($user);
    }

    public function comment(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && $this->view($user, $dossier) && (! $user->isExternal()
            || app(ClientAccess::class)->addComment($user, $dossier));
    }

    public function uploadDocument(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && ($user->isExternal()
            ? app(ClientAccess::class)->uploadDocument($user, $dossier)
            : $this->view($user, $dossier));
    }

    public function respondToNonConformity(User $user, Dossier $dossier): bool
    {
        return $user->isExternal() && $this->view($user, $dossier);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && $this->viewAny($user);
    }

    public function sendSms(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && $this->viewAny($user);
    }

    public function transmitReport(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && $this->view($user, $dossier) && ($user->admin || $user->role === 0);
    }

    public function reviewControl(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && $this->view($user, $dossier) && ($user->admin || $user->role === 0);
    }

    public function manageBilling(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && $this->viewAny($user) && ($user->admin || $user->role === 0
            || ($user->role === 1 && $user->can_manage_billing));
    }

    public function delete(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && $this->viewAny($user) && ($user->admin || $user->role === 0);
    }

    public function restore(User $user, Dossier $dossier): bool
    {
        return $dossier->trashed() && $this->viewAny($user) && ($user->admin || $user->role === 0);
    }
}
