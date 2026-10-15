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
            $locked->fill($attributes)->save();
            if (config('crm.v2_enabled') && (string) $previous !== (string) $locked->delegataire_id) {
                Dossier::query()->where('source', 'lot')
                    ->whereIn('source_reference', $locked->appointments()->select('id'))
                    ->lockForUpdate()->chunkById(100, function ($dossiers) use ($actor, $locked): void {
                        foreach ($dossiers as $dossier) {
                            $dossier->update(['delegataire_id' => $locked->delegataire_id]);
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
