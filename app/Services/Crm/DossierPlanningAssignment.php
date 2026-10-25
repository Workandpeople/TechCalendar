<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DossierPlanningAssignment
{
    private function eligibleUsers(): Builder
    {
        return User::query()->internal()->where('role', 0);
    }

    public function users(): Collection
    {
        return $this->eligibleUsers()->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get(['id', 'first_name', 'last_name']);
    }

    public function assign(User $actor, Dossier $dossier, ?int $userId, int $version): Dossier
    {
        Gate::forUser($actor)->authorize('update', $dossier);

        return DB::transaction(function () use ($actor, $dossier, $userId, $version): Dossier {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            abort_if($locked->version !== $version, 409, 'Ce dossier a changé entre-temps. Actualise le tableau avant de réessayer.');

            $operations = $locked->operations()->lockForUpdate()->get(['id', 'legacy_status']);
            if (! $operations->contains(fn ($operation) => in_array($operation->legacy_status, DossierStatusCatalog::STATUSES['visited']['values'], true))) {
                throw ValidationException::withMessages(['planning_user_id' => 'L’affectation est possible uniquement pour un dossier avec une prestation au statut RDV visité.']);
            }

            $user = $userId === null ? null : $this->eligibleUsers()->lockForUpdate()->find($userId);
            if ($userId !== null && ! $user) {
                throw ValidationException::withMessages(['planning_user_id' => 'Choisis un gérant interne actif.']);
            }
            if ($locked->planning_user_id === $userId) {
                return $locked;
            }

            $previousId = $locked->planning_user_id;
            $locked->forceFill(['planning_user_id' => $userId, 'version' => $locked->version + 1])->save();
            $locked->activities()->create([
                'author_id' => $actor->id,
                'kind' => 'planning_assigned',
                'body' => $user ? 'Suivi planning attribué à '.$user->full_name.'.' : 'Affectation du suivi planning retirée.',
                'changed_fields' => ['previous_planning_user_id' => $previousId, 'planning_user_id' => $userId],
                'client_visible' => false,
            ]);

            return $locked;
        }, 3);
    }
}
