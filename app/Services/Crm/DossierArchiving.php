<?php

namespace App\Services\Crm;

use App\Models\CapceeDelivery;
use App\Models\Dossier;
use App\Models\DossierSms;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DossierArchiving
{
    public const MAX_BATCH_SIZE = 100;

    /** Only explicitly selected, still-problematic dossiers; never a filter-wide cleanup. */
    public function batch(User $actor, array $selection, string $action, ?string $reason = null): int
    {
        Gate::forUser($actor)->authorize('manageArchiving', Dossier::class);
        $ids = array_column($selection, 'id');
        if ($selection === [] || count($selection) > self::MAX_BATCH_SIZE || count(array_unique($ids)) !== count($selection)
            || ! in_array($action, ['cancel', 'delete'], true)) {
            throw ValidationException::withMessages(['dossiers' => 'Sélectionne entre 1 et '.self::MAX_BATCH_SIZE.' dossiers distincts et une action valide.']);
        }

        return DB::transaction(function () use ($actor, $selection, $ids, $action, $reason): int {
            // Stable lock order prevents two overlapping batches from locking in reverse order.
            $dossiers = Dossier::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($dossiers->count() !== count($selection)) {
                throw ValidationException::withMessages(['dossiers' => 'Un dossier sélectionné est introuvable ou déjà supprimé. Actualise le tableau.']);
            }
            $versions = array_column($selection, 'version', 'id');
            foreach ($dossiers as $dossier) {
                Gate::forUser($actor)->authorize('delete', $dossier);
                $this->assertVersion($dossier, (int) $versions[$dossier->id]);
                $operations = $dossier->operations()->orderBy('id')->lockForUpdate()->get();
                $problems = $operations->filter(fn ($operation) => DossierStatusCatalog::describe($operation->legacy_status)['key'] === 'problem');
                if ($problems->isEmpty()) {
                    throw ValidationException::withMessages(['dossiers' => 'Le dossier '.$dossier->reference.' n’est plus en problème RDV. Aucun lot partiel : actualise la sélection.']);
                }
                if ($action === 'delete') {
                    if ($operations->contains(fn ($operation) => ! in_array(DossierStatusCatalog::describe($operation->legacy_status)['key'], ['problem', 'cancelled'], true))) {
                        throw ValidationException::withMessages(['dossiers' => 'Le dossier '.$dossier->reference.' contient des prestations dans d’autres états. Utilise la suppression individuelle explicite après vérification de son historique. Aucun dossier du lot n’a été supprimé.']);
                    }
                    $this->delete($actor, $dossier, $dossier->version, $reason);

                    continue;
                }
                $this->assertNoPendingDelivery($dossier);
                $version = $dossier->version;
                foreach ($problems as $operation) {
                    app(DossierWorkflow::class)->changeStatus($actor, $dossier, $operation, ['version' => $version++, 'status' => 'cancelled']);
                }
                if (filled($reason)) {
                    $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'dossier_cancelled',
                        'body' => 'Prestations en problème RDV annulées en sélection groupée. Motif : '.trim($reason),
                        'changed_fields' => ['action' => 'batch_cancel', 'operation_ids' => $problems->modelKeys(), 'reason' => trim($reason)],
                        'client_visible' => false, 'delegataire_visible' => false]);
                }
            }

            return $dossiers->count();
        }, 3);
    }

    /** For fresh imports only; preserve the raw source and all existing billing evidence. */
    public function classifyLegacyDeleted(): int
    {
        return Dossier::query()->where(function ($query): void {
            $query->whereDoesntHave('operations')
                ->orWhereHas('operations', fn ($operations) => DossierStatusCatalog::filter($operations, 'unknown'));
        })->update(['deleted_at' => now(), 'deletion_source' => 'legacy_status']);
    }

    public function delete(User $actor, Dossier $dossier, int $version, ?string $reason): void
    {
        Gate::forUser($actor)->authorize('delete', $dossier);
        DB::transaction(function () use ($actor, $dossier, $version, $reason): void {
            $locked = Dossier::withTrashed()->lockForUpdate()->findOrFail($dossier->id);
            $this->assertVersion($locked, $version);
            Gate::forUser($actor)->authorize('delete', $locked);
            $this->assertNoPendingDelivery($locked);
            $locked->forceFill(['deleted_by' => $actor->id, 'deletion_source' => 'native', 'version' => $locked->version + 1])->save();
            $locked->delete();
            $locked->activities()->create(['author_id' => $actor->id, 'kind' => 'dossier_deleted',
                'body' => 'Dossier supprimé. Données et facturation conservées.'.(filled($reason) ? ' Motif : '.trim($reason) : ''),
                'changed_fields' => ['deleted' => true], 'client_visible' => false, 'delegataire_visible' => false]);
        }, 3);
    }

    public function restore(User $actor, Dossier $dossier, int $version, ?int $serviceId): void
    {
        Gate::forUser($actor)->authorize('restore', $dossier);
        DB::transaction(function () use ($actor, $dossier, $version, $serviceId): void {
            $locked = Dossier::withTrashed()->lockForUpdate()->findOrFail($dossier->id);
            $this->assertVersion($locked, $version);
            Gate::forUser($actor)->authorize('restore', $locked);
            $operations = $locked->operations()->lockForUpdate()->get();
            $changes = [];
            if ($operations->isEmpty()) {
                $service = $serviceId ? Service::query()->catalog()->find($serviceId) : null;
                if (! $service) {
                    throw ValidationException::withMessages(['service_id' => 'Choisis une prestation pour restaurer ce dossier sans fiche.']);
                }
                $operation = $locked->operations()->create(['service_id' => $service->id]);
                $operations->push($operation);
            }
            foreach ($operations as $operation) {
                if (DossierStatusCatalog::describe($operation->legacy_status)['key'] === 'unknown') {
                    $changes[] = ['operation_id' => $operation->id, 'previous_status' => $operation->legacy_status, 'status' => 'PRISE DE RDV'];
                    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
                }
            }
            $locked->forceFill(['deleted_by' => null, 'version' => $locked->version + 1])->save();
            $locked->restore();
            $locked->activities()->create(['author_id' => $actor->id, 'kind' => 'dossier_restored',
                'body' => 'Dossier restauré. Facturation et historique conservés.',
                'changed_fields' => ['deleted' => false, 'operations' => $changes], 'client_visible' => false]);
        }, 3);
    }

    private function assertVersion(Dossier $dossier, int $version): void
    {
        abort_if($dossier->version !== $version, 409, 'Ce dossier a changé. Actualise la fiche avant de réessayer.');
    }

    private function assertNoPendingDelivery(Dossier $dossier): void
    {
        if (CapceeDelivery::whereHas('report.operation', fn ($query) => $query->where('dossier_id', $dossier->id))
            ->whereIn('status', ['queued', 'processing', 'review'])->exists()
            || DossierSms::where('dossier_id', $dossier->id)->whereIn('status', ['queued', 'sending'])->exists()) {
            throw ValidationException::withMessages(['dossier' => 'Un envoi est en cours ou à vérifier. Traite cet envoi avant de supprimer ou annuler le dossier.']);
        }
    }
}
