<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LotClientAssignment
{
    public function update(User $actor, Lot $lot, array $attributes): void
    {
        abort_unless(! $actor->isExternal() && ! $actor->trashed() && ! $actor->must_change_password
            && ($actor->admin || $actor->role === 0), 403);
        DB::transaction(function () use ($actor, $lot, $attributes): void {
            $locked = Lot::query()->lockForUpdate()->findOrFail($lot->id);
            $previous = $locked->delegataire_id;
            $previousKind = $locked->client?->kind;
            $locked->fill($attributes)->save();
            $locked->unsetRelation('client');
            if (config('crm.v2_enabled') && (string) $previous !== (string) $locked->delegataire_id) {
                Dossier::query()->where('source', 'lot')
                    ->whereIn('source_reference', $locked->appointments()->select('id'))
                    ->lockForUpdate()->chunkById(100, function ($dossiers) use ($actor, $locked, $previous, $previousKind): void {
                        $owner = $locked->client;
                        $field = ($owner?->kind ?? $previousKind) === 'client' ? 'client_id' : 'delegataire_id';
                        foreach ($dossiers as $dossier) {
                            $changes = [$field => $locked->delegataire_id];
                            $other = $field === 'client_id' ? 'delegataire_id' : 'client_id';
                            if ($previous !== null && $previous === $dossier->$other) {
                                $changes[$other] = null;
                            }
                            $dossier->update(DossierContacts::fillMissingSnapshots($changes));
                            $dossier->increment('version');
                            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'client_assignment',
                                'body' => 'Client mis à jour depuis le lot '.$locked->name.'.']);
                        }
                    });
            }
            $lot->refresh();
        });
    }
}
