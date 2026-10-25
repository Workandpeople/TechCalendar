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
            if (CapceeDelivery::whereHas('report.operation', fn ($query) => $query->where('dossier_id', $locked->id))
                ->whereIn('status', ['queued', 'processing', 'review'])->exists()
                || DossierSms::where('dossier_id', $locked->id)->whereIn('status', ['queued', 'sending'])->exists()) {
                throw ValidationException::withMessages(['dossier' => 'Un envoi est en cours ou à vérifier. Traite cet envoi avant de supprimer le dossier.']);
            }
            $locked->forceFill(['deleted_by' => $actor->id, 'deletion_source' => 'native', 'version' => $locked->version + 1])->save();
            $locked->delete();
            $locked->activities()->create(['author_id' => $actor->id, 'kind' => 'dossier_deleted',
                'body' => 'Dossier supprimé. Données et facturation conservées.'.(filled($reason) ? ' Motif : '.trim($reason) : ''),
                'changed_fields' => ['deleted' => true], 'client_visible' => false]);
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
}
