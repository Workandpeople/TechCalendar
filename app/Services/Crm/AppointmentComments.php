<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AppointmentComments
{
    public function forAppointments(Collection $appointments): array
    {
        if ($appointments->isEmpty() || ! Gate::allows('viewAny', Dossier::class)) {
            return [];
        }

        $models = (new Appointment)->newCollection($appointments->all());
        $models->loadMissing(['dossierOperation.dossier.comments' => fn ($query) => $query
            ->with('author')->latest('created_at')->latest('id')->limit(10)]);

        return $models->mapWithKeys(fn ($appointment): array => [
            $appointment->id => $appointment->dossierOperation?->dossier?->comments->map(fn ($comment): array => [
                'id' => $comment->id, 'body' => $comment->body, 'comment' => $comment->body,
                'author_name' => $comment->author?->full_name ?: 'Historique dossier',
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->all() ?? [],
        ])->all();
    }
}
