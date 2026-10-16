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
        return $this->view($user, $dossier) && (! $user->isExternal()
            || app(ClientAccess::class)->allows($user, 'add_comments'));
    }

    public function uploadDocument(User $user, Dossier $dossier): bool
    {
        return $this->view($user, $dossier) && (! $user->isExternal()
            || app(ClientAccess::class)->allows($user, 'upload_documents'));
    }

    public function respondToNonConformity(User $user, Dossier $dossier): bool
    {
        return $user->isExternal() && $this->view($user, $dossier)
            && ($this->comment($user, $dossier) || $this->uploadDocument($user, $dossier));
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Dossier $dossier): bool
    {
        return $this->viewAny($user);
    }

    public function sendSms(User $user, Dossier $dossier): bool
    {
        return $this->viewAny($user);
    }

    public function transmitReport(User $user, Dossier $dossier): bool
    {
        return $this->view($user, $dossier) && ($user->admin || $user->role === 0);
    }

    public function reviewControl(User $user, Dossier $dossier): bool
    {
        return $this->view($user, $dossier) && ($user->admin || $user->role === 0);
    }
}
