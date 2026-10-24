<?php

namespace App\Services;

use App\Models\User;

class UserAccountConflict
{
    /** @return array{message: string, url: ?string, label: ?string}|null */
    public function find(User $actor, mixed $email, ?int $ignoreId = null): ?array
    {
        abort_unless(! $actor->isExternal() && ! $actor->trashed() && ($actor->admin || $actor->role === 0), 403);

        if (! is_string($email) || trim($email) === '' || mb_strlen($email) > 190) {
            return null;
        }

        // Include disabled and external accounts: the database reserves their email addresses too.
        $account = User::withTrashed()->with('externalClient:id,kind')
            ->where('email', trim($email))
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->first(['id', 'email', 'role', 'admin', 'external_client_id', 'deleted_at']);

        if (! $account) {
            return null;
        }

        if ($account->isExternal()) {
            $type = match ($account->externalClient?->kind) {
                'client' => 'client',
                'delegataire' => 'délégataire',
                default => 'externe',
            };

            return [
                'message' => "Cette adresse email est déjà utilisée par un compte {$type}. Ses accès se gèrent dans Clients et délégataires, pas dans Utilisateurs internes.",
                'url' => config('crm.v2_enabled') && $account->externalClient ? route('manager.clients.edit', $account->externalClient) : null,
                'label' => 'Gérer les accès externes',
            ];
        }

        if ($account->admin && ! $actor->admin) {
            return [
                'message' => 'Cette adresse email est déjà utilisée par un compte administrateur. Seul un administrateur peut gérer ce compte.',
                'url' => null,
                'label' => null,
            ];
        }

        return [
            'message' => $account->trashed()
                ? 'Cette adresse email est déjà utilisée par un compte interne désactivé. Retrouvez-le avec le statut « Tous » et restaurez-le si nécessaire, sans créer de doublon.'
                : 'Cette adresse email est déjà utilisée par un compte interne actif. Modifiez le compte existant plutôt que de le recréer.',
            'url' => route($actor->admin ? 'admin.users' : 'manager.users', ['q' => $account->email, 'status' => 'all']),
            'label' => 'Retrouver le compte existant',
        ];
    }
}
