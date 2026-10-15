<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DossierRegistry
{
    public function search(User $actor, array $filters): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);

        $query = $this->filteredQuery($filters);
        if (filled($filters['status'] ?? null)) {
            $query->where(function ($query) use ($filters): void {
                $query->whereHas('operations', function ($operations) use ($filters): void {
                    $operations->when($filters['service_id'] ?? null, fn ($query, $id) => $query->where('service_id', $id));
                    DossierStatusCatalog::filter($operations, $filters['status']);
                });
                if ($filters['status'] === 'unknown' && empty($filters['service_id'])) {
                    $query->orWhereDoesntHave('operations');
                }
            });
        }

        return app(DossierTable::class)->apply($query, $filters)->with([
            'delegataire', 'operations.service', 'planningUser:id,first_name,last_name,deleted_at',
            'latestActivity',
            'listingAppointment.technician' => fn ($query) => $query->withTrashed()->with('departments'),
            'comments' => fn ($query) => $query->with('author:id,first_name,last_name')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(3),
        ])->withCount(['appointments', 'comments'])
            ->paginate($filters['per_page'] ?? 25)->withQueryString();
    }

    public function categories(User $actor, array $filters): array
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);

        $query = $this->filteredQuery($filters);
        [$categorySql, $bindings] = DossierStatusCatalog::categorySql();
        $counts = DB::query()->fromSub((clone $query)->select('dossiers.id'), 'matching_dossiers')
            ->leftJoin('dossier_operations', 'dossier_operations.dossier_id', '=', 'matching_dossiers.id')
            ->when($filters['service_id'] ?? null, fn ($query, $id) => $query->where('dossier_operations.service_id', $id))
            ->selectRaw($categorySql.' AS category', $bindings)
            ->selectRaw('COUNT(DISTINCT matching_dossiers.id) AS total')
            ->groupBy('category')->pluck('total', 'category');

        $categories = ['all' => ['label' => 'Tous les dossiers', 'tone' => 'slate', 'count' => $query->count()]];
        foreach (DossierStatusCatalog::STATUSES as $key => $status) {
            $categories[$key] = [...$status, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $categories;
    }

    private function filteredQuery(array $filters): Builder
    {
        return Dossier::query()
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                // Escape LIKE wildcards so a reference is searched literally.
                $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
                $query->where(function ($query) use ($search): void {
                    foreach (['reference', 'legacy_reference', 'legacy_label', 'legacy_lot_name', 'company_name', 'first_name', 'last_name', 'site_name', 'email', 'phone', 'address', 'postal_code', 'city', 'installer_name', 'installer_siren'] as $field) {
                        $query->orWhereRaw("{$field} LIKE ? ESCAPE '!'", [$search]);
                    }
                });
            })
            ->when($filters['delegataire_id'] ?? null, fn ($query, $id) => $query->where('delegataire_id', $id))
            ->when($filters['service_id'] ?? null, fn ($query, $id) => $query->whereHas('operations', fn ($operations) => $operations->where('service_id', $id)))
            ->when(($filters['planning'] ?? null) === 'unlinked', fn ($query) => $query->whereDoesntHave('appointments'))
            ->when(($filters['planning'] ?? null) === 'upcoming', fn ($query) => $query->whereHas('appointments', fn ($rdv) => $rdv->where('status', Appointment::STATUS_SCHEDULED)->where('ends_at', '>', now())))
            ->when(($filters['planning'] ?? null) === 'problem', fn ($query) => $query->whereHas('appointments', fn ($rdv) => $rdv->where('status', Appointment::STATUS_PROBLEM)));
    }

    public function references(): array
    {
        return [
            'delegataires' => ExternalDelegataire::query()->orderBy('name')->get(['id', 'name', 'company_name', 'is_active']),
            'services' => Service::query()->catalog()->orderBy('name')->get(['id', 'name', 'type']),
        ];
    }

    public function create(User $actor, array $data): Dossier
    {
        Gate::forUser($actor)->authorize('create', Dossier::class);

        try {
            return DB::transaction(function () use ($actor, $data): Dossier {
                $dossier = Dossier::query()->create([
                    ...Arr::except($data, ['service_ids']),
                    'source' => 'native', 'source_reference' => null, 'created_by' => $actor->id,
                ]);
                foreach ($data['service_ids'] as $serviceId) {
                    $operation = $dossier->operations()->create(['service_id' => $serviceId]);
                    $operation->forceFill(['legacy_status' => DossierStatusCatalog::STATUSES['pending']['values'][0]])->save();
                }
                $dossier->activities()->create([
                    'author_id' => $actor->id, 'kind' => 'created', 'body' => 'Dossier créé dans TechCalendar.',
                ]);

                return $dossier;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->reportDuplicateReference($data['reference'], $exception);
        }
    }

    public function update(User $actor, Dossier $dossier, array $data): Dossier
    {
        Gate::forUser($actor)->authorize('update', $dossier);

        try {
            return DB::transaction(function () use ($actor, $dossier, $data): Dossier {
                $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
                if ($locked->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'Ce dossier a été modifié entre-temps. Recharge la fiche avant de réessayer.']);
                }
                $locked->fill(Arr::except($data, ['version', 'service_ids']));
                $changed = array_keys($locked->getDirty());
                if ($changed !== []) {
                    $locked->version++;
                    $locked->save();
                    $locked->activities()->create([
                        'author_id' => $actor->id, 'kind' => 'updated',
                        'body' => 'Informations du dossier mises à jour.', 'changed_fields' => $changed,
                    ]);
                }

                return $locked;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->reportDuplicateReference($data['reference'], $exception);
        }
    }

    public function addComment(User $actor, Dossier $dossier, string $body, bool $clientVisible = false): void
    {
        Gate::forUser($actor)->authorize('comment', $dossier);
        $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => $body,
            'client_visible' => $actor->isExternal() || $clientVisible]);
    }

    public function addDocument(User $actor, Dossier $dossier, UploadedFile $file, string $name, bool $clientVisible = false): DossierDocument
    {
        Gate::forUser($actor)->authorize('uploadDocument', $dossier);
        $path = $file->store('crm/dossiers/'.$dossier->id, 'local');
        if (! is_string($path)) {
            throw new RuntimeException('Le document n’a pas pu être enregistré. Réessaie après vérification du stockage.');
        }
        try {
            return DB::transaction(function () use ($actor, $dossier, $file, $name, $path, $clientVisible): DossierDocument {
                $document = $dossier->documents()->create([
                    'author_id' => $actor->id, 'name' => $name, 'path' => $path,
                    'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                    'client_visible' => $actor->isExternal() || $clientVisible,
                ]);
                $dossier->activities()->create([
                    'author_id' => $actor->id, 'kind' => 'document', 'body' => 'Document ajouté : '.$name,
                ]);

                return $document;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function download(User $actor, DossierDocument $document): StreamedResponse
    {
        Gate::forUser($actor)->authorize('view', $document->dossier);
        abort_if($actor->isExternal() && (! $document->client_visible
            || ! app(ClientAccess::class)->allows($actor, 'documents')), 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        $name = preg_replace('/[^\pL\pN ._-]/u', '_', $document->name);
        $extension = pathinfo($document->path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($document->path, $name.'.'.$extension, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function shareDocument(User $actor, DossierDocument $document, bool $visible): void
    {
        Gate::forUser($actor)->authorize('update', $document->dossier);
        DB::transaction(function () use ($actor, $document, $visible): void {
            $document->update(['client_visible' => $visible]);
            $document->dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_visibility',
                'body' => ($visible ? 'Partage client activé : ' : 'Partage client retiré : ').$document->name]);
        });
    }

    public function shareComment(User $actor, Dossier $dossier, int $id, bool $visible): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $id, $visible): void {
            $comment = $dossier->comments()->lockForUpdate()->findOrFail($id);
            $comment->update(['client_visible' => $visible, 'visibility_reviewed_at' => now()]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment_visibility',
                'body' => ($visible ? 'Partage client activé' : 'Partage client retiré').' pour le commentaire #'.$id,
                'changed_fields' => ['comment_id' => $id, 'client_visible' => $visible]]);
        });
    }

    private function reportDuplicateReference(string $reference, UniqueConstraintViolationException $exception): never
    {
        if (Dossier::query()->where('reference', $reference)->exists()) {
            throw ValidationException::withMessages(['reference' => 'Cette référence est déjà utilisée. Le dossier n’a pas été créé en double.']);
        }
        throw $exception;
    }
}
