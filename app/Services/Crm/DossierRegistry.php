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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DossierRegistry
{
    public function search(User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->matchingQuery($actor, $filters);

        return app(DossierTable::class)->apply($query, $filters)->with([
            'client', 'delegataire', 'operations.service', 'operations.billing', 'planningUser:id,first_name,last_name,deleted_at',
            'latestStatusChange',
            'listingAppointment.technician' => fn ($query) => $query->withTrashed()->with('departments'),
            'comments' => fn ($query) => $query->with('author:id,first_name,last_name')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(3),
        ])->withCount(['appointments', 'comments'])
            ->paginate($filters['per_page'] ?? 25)->withQueryString();
    }

    public function matchingQuery(User $actor, array $filters): Builder
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);

        $query = $this->filteredQuery($filters);
        if (filled($filters['status'] ?? null)) {
            $query->where(function ($query) use ($filters): void {
                $query->whereHas('operations', function ($operations) use ($filters): void {
                    $operations->when($filters['service_id'] ?? null, fn ($query, $id) => $query->where('service_id', $id));
                    DossierStatusCatalog::filter($operations, $filters['status']);
                });
                if ($filters['status'] === 'invoiced') {
                    $query->orWhereHas('operations', fn ($operations) => $operations
                        ->when($filters['service_id'] ?? null, fn ($query, $id) => $query->where('service_id', $id))->whereHas('billing'));
                }
                if ($filters['status'] === 'unknown' && empty($filters['service_id'])) {
                    $query->orWhereDoesntHave('operations');
                }
            });
        }

        return $query;
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
            if ($key === 'invoiced') {
                continue;
            }
            $categories[$key] = [...$status, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $categories;
    }

    private function filteredQuery(array $filters): Builder
    {
        return Dossier::query()
            ->when($filters['billing'] ?? null, function ($query, $billing): void {
                if ($billing === 'review') {
                    $query->where('billing_review_required', true);

                    return;
                }
                $query->where('billing_review_required', false);
                match ($billing) {
                    'unbilled' => $query->whereDoesntHave('billings')->whereDoesntHave('operations', fn ($operations) => $operations->whereIn('legacy_status', DossierStatusCatalog::STATUSES['invoiced']['values'])),
                    // Old bookmarked partial filters now mean billed at dossier level.
                    'partial', 'invoiced' => $query->where(fn ($billed) => $billed->whereHas('billings')->orWhereHas('operations', fn ($operations) => $operations->whereIn('legacy_status', DossierStatusCatalog::STATUSES['invoiced']['values']))),
                    default => null,
                };
            })
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                // Escape LIKE wildcards so a reference is searched literally.
                $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
                $query->where(function ($query) use ($search): void {
                    foreach (['reference', 'legacy_reference', 'legacy_label', 'legacy_lot_name', 'company_name', 'first_name', 'last_name', 'site_name', 'email', 'phone', 'address', 'postal_code', 'city', 'installer_name', 'installer_siren'] as $field) {
                        $query->orWhereRaw("{$field} LIKE ? ESCAPE '!'", [$search]);
                    }
                });
            })
            ->when($filters['delegataire_id'] ?? null, fn ($query, $id) => $query->where(fn ($party) => $party->where('delegataire_id', $id)->orWhere('client_id', $id)))
            ->when($filters['service_id'] ?? null, fn ($query, $id) => $query->whereHas('operations', fn ($operations) => $operations->where('service_id', $id)))
            ->when(($filters['planning'] ?? null) === 'unlinked', fn ($query) => $query->whereDoesntHave('appointments'))
            ->when(($filters['planning'] ?? null) === 'upcoming', fn ($query) => $query->whereHas('appointments', fn ($rdv) => $rdv->where('status', Appointment::STATUS_SCHEDULED)->where('ends_at', '>', now())))
            ->when(($filters['planning'] ?? null) === 'problem', fn ($query) => $query->whereHas('appointments', fn ($rdv) => $rdv->where('status', Appointment::STATUS_PROBLEM)));
    }

    public function references(): array
    {
        return [
            'delegataires' => ExternalDelegataire::query()->orderBy('name')->get(),
            'services' => Service::query()->catalog()->orderBy('name')->get(['id', 'name', 'type']),
        ];
    }

    public function create(User $actor, array $data): Dossier
    {
        Gate::forUser($actor)->authorize('create', Dossier::class);
        $data = DossierContacts::fillMissingSnapshots($data);
        $data['reference'] ??= 'DOS-'.Str::upper((string) Str::ulid());

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
        $data = DossierContacts::fillMissingSnapshots($data);

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
            $this->reportDuplicateReference($data['reference'] ?? $dossier->reference, $exception);
        }
    }

    public function addComment(User $actor, Dossier $dossier, string $body, bool $clientVisible = false, ?bool $delegataireVisible = null): void
    {
        Gate::forUser($actor)->authorize('comment', $dossier);
        $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => $body,
            'client_visible' => $actor->isExternal() || $clientVisible,
            'delegataire_visible' => $actor->isExternal() ? $actor->externalClient->kind === 'delegataire' : ($clientVisible && ($delegataireVisible ?? $clientVisible))]);
    }

    public function addDocument(User $actor, Dossier $dossier, UploadedFile $file, string $name, bool $clientVisible = false, ?bool $delegataireVisible = null, array $metadata = []): DossierDocument
    {
        Gate::forUser($actor)->authorize('uploadDocument', $dossier);
        $path = $file->store('crm/dossiers/'.$dossier->id, 'local');
        if (! is_string($path)) {
            throw new RuntimeException('Le document n’a pas pu être enregistré. Réessaie après vérification du stockage.');
        }
        try {
            return DB::transaction(function () use ($actor, $dossier, $file, $name, $path, $clientVisible, $delegataireVisible, $metadata): DossierDocument {
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
                // Recheck assignment and correction state after any concurrent dossier update.
                Gate::forUser($actor)->authorize('uploadDocument', $dossier);
                $document = $dossier->documents()->create([
                    'author_id' => $actor->id, 'name' => $name, 'path' => $path,
                    'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                    'client_visible' => $actor->isExternal() || $clientVisible,
                    'delegataire_visible' => $actor->isExternal() ? $actor->externalClient->kind === 'delegataire' : ($clientVisible && ($delegataireVisible ?? $clientVisible)),
                    ...Arr::only($metadata, ['description', 'document_type', 'dossier_operation_id', 'control_reference', 'inspected_on', 'issued_on', 'control_outcome']),
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

    public function download(User $actor, DossierDocument $document, bool $preview = false): StreamedResponse
    {
        Gate::forUser($actor)->authorize('view', $document->dossier);
        abort_if($actor->isExternal() && (! $document->{app(ClientAccess::class)->audienceColumn($actor)}
            || ! app(ClientAccess::class)->allows($actor, 'documents')), 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        $name = preg_replace('/[^\pL\pN ._-]/u', '_', $document->name) ?: 'Document';
        $extension = pathinfo($document->path, PATHINFO_EXTENSION);
        if ($extension !== '' && ! str_ends_with(strtolower($name), '.'.strtolower($extension))) {
            $name .= '.'.$extension;
        }

        $mime = $preview ? (new \finfo(FILEINFO_MIME_TYPE))->file(Storage::disk('local')->path($document->path)) : 'application/octet-stream';
        $inline = $preview && DocumentResponseHeaders::previewable($mime);

        return Storage::disk('local')->response($document->path, $name,
            DocumentResponseHeaders::forFile($mime, $inline), $inline ? 'inline' : 'attachment');
    }

    public function shareDocument(User $actor, DossierDocument $document, bool $visible, ?bool $delegataireVisible = null): void
    {
        Gate::forUser($actor)->authorize('update', $document->dossier);
        DB::transaction(function () use ($actor, $document, $visible, $delegataireVisible): void {
            $document->update(['client_visible' => $visible, 'delegataire_visible' => $visible && ($delegataireVisible ?? $visible)]);
            $document->dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_visibility',
                'body' => 'Accès « '.ContentAudience::LABELS[ContentAudience::key($document->client_visible, $document->delegataire_visible)].' » : '.$document->name,
                'changed_fields' => ['document_id' => $document->id, 'client_visible' => $document->client_visible, 'delegataire_visible' => $document->delegataire_visible]]);
        });
    }

    public function shareComment(User $actor, Dossier $dossier, int $id, bool $visible, ?bool $delegataireVisible = null): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $id, $visible, $delegataireVisible): void {
            $comment = $dossier->comments()->lockForUpdate()->findOrFail($id);
            $comment->update(['client_visible' => $visible, 'delegataire_visible' => $visible && ($delegataireVisible ?? $visible), 'visibility_reviewed_at' => now()]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment_visibility',
                'body' => 'Accès « '.ContentAudience::LABELS[ContentAudience::key($comment->client_visible, $comment->delegataire_visible)].' » pour le commentaire #'.$id,
                'changed_fields' => ['comment_id' => $id, 'client_visible' => $comment->client_visible, 'delegataire_visible' => $comment->delegataire_visible]]);
        });
    }

    public function removeComment(User $actor, Dossier $dossier, int $id): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $id): void {
            $comment = $dossier->comments()->whereIn('kind', ['comment', 'legacy_comment'])->lockForUpdate()->findOrFail($id);
            $comment->update(['hidden_at' => now(), 'client_visible' => false, 'delegataire_visible' => false]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment_removed',
                'body' => 'Commentaire #'.$id.' retiré de la fiche. Trace conservée.', 'changed_fields' => ['comment_id' => $id]]);
        });
    }

    public function removeDocument(User $actor, DossierDocument $document): void
    {
        Gate::forUser($actor)->authorize('update', $document->dossier);
        DB::transaction(function () use ($actor, $document): void {
            // Same parent lock as the report workflow: a validated PDF cannot disappear mid-review.
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($document->dossier_id);
            $document = $dossier->documents()->lockForUpdate()->findOrFail($document->id);
            if ($dossier->operations()->where('report_document_id', $document->id)->exists()
                || ($document->document_type === 'visit' && $dossier->activities()->where('changed_fields->visit_document_id', $document->id)->exists())) {
                throw ValidationException::withMessages(['document' => 'Ce document justifie une visite ou le dernier avis du contrôle. Il est conservé dans l’historique et ne peut pas être supprimé.']);
            }
            $document->delete();
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_removed',
                'body' => 'Document retiré : '.$document->name, 'changed_fields' => ['document_id' => $document->id]]);
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
