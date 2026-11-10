<?php

namespace App\Services\Tech;

use App\Models\TechnicianIncident;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TechnicianIncidentInbox
{
    public function counts(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', TechnicianIncident::class);

        return [
            'pending' => TechnicianIncident::where('planning_status', 'pending')->count(),
            'billing' => Gate::forUser($actor)->allows('reviewBilling', TechnicianIncident::class)
                ? TechnicianIncident::whereIn('billing_status', ['review', 'billable'])->count() : null,
            'latest_id' => (int) TechnicianIncident::max('id'),
        ];
    }

    public function search(User $actor, array $filters): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewAny', TechnicianIncident::class);
        $query = TechnicianIncident::query()->with(['dossier.client:id,name', 'dossier.delegataire:id,name',
            'technician:id,first_name,last_name', 'document', 'handler:id,first_name,last_name',
            'billingReviewer:id,first_name,last_name']);
        if (filled($filters['source'] ?? null)) {
            $query->where('source', $filters['source']);
        }
        if (isset($filters['dossier_id'])) {
            $query->where('dossier_id', $filters['dossier_id']);
        }
        if (($filters['status'] ?? 'pending') !== 'all') {
            $query->where('planning_status', $filters['status'] ?? 'pending');
        }
        if (filled($filters['billing'] ?? null)) {
            Gate::forUser($actor)->authorize('reviewBilling', TechnicianIncident::class);
            $query->where('billing_status', $filters['billing']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (filled($filters[$key] ?? null)) {
                $query->where('reported_at', $operator, $filters[$key].($key === 'from' ? ' 00:00:00' : ' 23:59:59'));
            }
        }
        if (filled($filters['reason'] ?? null)) {
            $query->where('reason', $filters['reason']);
        }
        if (filled($filters['q'] ?? null)) {
            $terms = array_map(fn ($word) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%',
                preg_split('/\s+/u', trim($filters['q']), -1, PREG_SPLIT_NO_EMPTY));
            $query->where(function ($query) use ($terms): void {
                $query->whereHas('dossier', function ($dossiers) use ($terms): void {
                    foreach ($terms as $term) {
                        $dossiers->where(function ($dossiers) use ($term): void {
                            foreach (['reference', 'company_name', 'first_name', 'last_name', 'address', 'postal_code', 'city'] as $column) {
                                $dossiers->orWhereRaw("$column LIKE ? ESCAPE '!'", [$term]);
                            }
                        });
                    }
                })->orWhereHas('technician', function ($users) use ($terms): void {
                    foreach ($terms as $term) {
                        $users->where(fn ($users) => $users->whereRaw("first_name LIKE ? ESCAPE '!'", [$term])
                            ->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$term]));
                    }
                })->orWhere(function ($remote) use ($terms): void {
                    $remote->where('source', 'mymar');
                    $grammar = DB::connection()->getQueryGrammar();
                    foreach ($terms as $term) {
                        $remote->where(fn ($remote) => $remote->whereRaw($grammar->wrap('source_context->customer')." LIKE ? ESCAPE '!'", [$term])
                            ->orWhereRaw($grammar->wrap('source_context->address')." LIKE ? ESCAPE '!'", [$term]));
                    }
                });
            });
        }

        $results = $query->orderByDesc('reported_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $results->getCollection()->where('source', 'mymar')->load('sourceRequest:id,status');

        return $results;
    }

    public function update(User $actor, TechnicianIncident $incident, array $data): void
    {
        DB::transaction(function () use ($actor, $incident, $data): void {
            $locked = TechnicianIncident::query()->lockForUpdate()->findOrFail($incident->id);
            $actor = User::query()->lockForUpdate()->find($actor->id);
            abort_unless($actor, 403);
            Gate::forUser($actor)->authorize('update', $locked);
            $billing = $data['section'] === 'billing';
            if ($billing) {
                Gate::forUser($actor)->authorize('reviewBilling', TechnicianIncident::class);
            }
            if ($locked->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Ce signalement a été modifié par un collègue. Rechargez la page avant de réessayer.']);
            }
            $prefix = $billing ? 'billing' : 'planning';
            $before = $locked->only([$prefix.'_status', $prefix.'_note']);
            $after = [$prefix.'_status' => $data['status'], $prefix.'_note' => trim($data['note'])];
            if ($before === $after) {
                return;
            }
            $locked->forceFill([...$after, ...($billing
                ? ['billing_reviewed_by' => $actor->id, 'billing_reviewed_at' => now()]
                : ['handled_by' => $actor->id, 'handled_at' => now()]), 'version' => $locked->version + 1])->save();
            if ($locked->source === 'mymar') {
                DB::table('technician_incident_reviews')->insert(['incident_id' => $locked->id, 'author_id' => $actor->id,
                    'section' => $data['section'], 'before' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return;
            }
            $locked->dossier->activities()->create([
                'author_id' => $actor->id, 'kind' => 'on_site_reviewed',
                'body' => 'Signalement technicien #'.$locked->id.' · '.($billing ? 'Comptabilité : ' : 'Planning : ')
                    .($billing ? TechnicianIncident::BILLING_STATUSES : TechnicianIncident::PLANNING_STATUSES)[$data['status']],
                'changed_fields' => ['incident_id' => $locked->id, 'before' => $before, 'after' => $after],
                'client_visible' => false, 'delegataire_visible' => false,
            ]);
        }, 3);
    }
}
