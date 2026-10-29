<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\CapceeAutomaticSubmission;
use App\Models\Department;
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
use Illuminate\Support\Carbon;
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

        $query = app(DossierTable::class)->apply($query, $filters)->with([
            'client', 'delegataire', 'operations.service', 'operations.billing', 'planningUser:id,first_name,last_name,deleted_at',
            'latestStatusChange',
            'listingAppointment.technician' => fn ($query) => $query->withTrashed()->with('departments'),
            'comments' => fn ($query) => $query->with('author:id,first_name,last_name')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(3),
        ])->withCount(['appointments', 'comments']);
        $dossiers = $query->paginate($filters['per_page'] ?? 10);
        if ($dossiers->currentPage() > $dossiers->lastPage()) {
            // A deletion can remove the last page; reuse the count and keep the active filters.
            $dossiers = $query->paginate($dossiers->perPage(), ['*'], 'page', $dossiers->lastPage(), $dossiers->total());
        }

        return $dossiers->withQueryString();
    }

    public function matchingQuery(User $actor, array $filters): Builder
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);

        $query = $this->filteredQuery($filters);
        $serviceIds = DossierServiceFilter::ids($filters);
        if (($filters['status'] ?? null) === 'unknown') {
            return $query->withTrashed()->where(function ($deleted) use ($serviceIds): void {
                $deleted->whereNotNull('dossiers.deleted_at')->orWhere(function ($legacy) use ($serviceIds): void {
                    $legacy->whereHas('operations', function ($operations) use ($serviceIds): void {
                        $operations->when($serviceIds !== [], fn ($query) => $query->whereIn('service_id', $serviceIds));
                        DossierStatusCatalog::filter($operations, 'unknown');
                    });
                    if ($serviceIds === []) {
                        $legacy->orWhereDoesntHave('operations');
                    }
                });
            });
        }
        if (filled($filters['status'] ?? null)) {
            $query->where(function ($query) use ($filters, $serviceIds): void {
                $query->whereHas('operations', function ($operations) use ($filters, $serviceIds): void {
                    $operations->when($serviceIds !== [], fn ($query) => $query->whereIn('service_id', $serviceIds));
                    DossierStatusCatalog::filter($operations, $filters['status']);
                });
                if ($filters['status'] === 'invoiced') {
                    $query->orWhereHas('operations', fn ($operations) => $operations
                        ->when($serviceIds !== [], fn ($query) => $query->whereIn('service_id', $serviceIds))->whereHas('billing'));
                }
            });
        }

        return $query;
    }

    public function categories(User $actor, array $filters): array
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);

        // Other categories drop the visited-only assignment filter when opened.
        $query = $this->filteredQuery(Arr::except($filters, ['planning_user_ids']))->withTrashed();
        $serviceIds = DossierServiceFilter::ids($filters);
        [$categorySql, $bindings] = DossierStatusCatalog::categorySql();
        $categorySql = "CASE WHEN matching_dossiers.deleted_at IS NOT NULL THEN 'unknown' ELSE $categorySql END";
        $counts = DB::query()->fromSub((clone $query)->select('dossiers.id', 'dossiers.deleted_at'), 'matching_dossiers')
            ->leftJoin('dossier_operations', 'dossier_operations.dossier_id', '=', 'matching_dossiers.id')
            ->when($serviceIds !== [], fn ($query) => $query->whereIn('dossier_operations.service_id', $serviceIds))
            ->selectRaw($categorySql.' AS category', $bindings)
            ->selectRaw('COUNT(DISTINCT matching_dossiers.id) AS total')
            ->groupBy('category')->pluck('total', 'category');

        $categories = ['all' => ['label' => 'Tous les dossiers actifs', 'tone' => 'slate', 'count' => (clone $query)->whereNull('dossiers.deleted_at')->count()]];
        foreach (DossierStatusCatalog::STATUSES as $key => $status) {
            if ($key === 'invoiced') {
                continue;
            }
            $categories[$key] = [...$status, 'count' => (int) ($counts[$key] ?? 0)];
        }

        if (($filters['status'] ?? null) === 'visited' && ! empty($filters['planning_user_ids'])) {
            $categories['visited']['count'] = $this->matchingQuery($actor, $filters)->count();
        }

        return $categories;
    }

    private function filteredQuery(array $filters): Builder
    {
        $serviceIds = DossierServiceFilter::ids($filters);
        $companyIds = DossierCompanyFilter::ids($filters);

        return Dossier::query()
            ->when(($filters['status'] ?? null) === 'visited' && ! empty($filters['planning_user_ids']),
                fn ($query) => $query->whereIn('dossiers.planning_user_id', $filters['planning_user_ids']))
            ->when($filters['department'] ?? null, fn ($query, $code) => DossierSections::department($query, $code))
            ->when($filters['section'] ?? null, fn ($query, $section) => DossierSections::filter($query, $section))
            ->when(filled($filters['date_from'] ?? null) || filled($filters['date_to'] ?? null), function ($query) use ($filters, $serviceIds): void {
                $range = function ($query, string $column) use ($filters): void {
                    if (filled($filters['date_from'] ?? null)) {
                        $query->where($column, '>=', $filters['date_from'].' 00:00:00');
                    }
                    if (filled($filters['date_to'] ?? null)) {
                        $query->where($column, '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
                    }
                };
                if (($filters['date_field'] ?? 'created') === 'appointment') {
                    $query->whereHas('appointments', function ($appointments) use ($range, $filters, $serviceIds): void {
                        $appointments->withTrashed()->where(fn ($q) => $q->whereNull('appointments.deleted_at')->orWhere('appointments.external_source', 'coffrac_archive'));
                        $range($appointments, 'appointments.starts_at');
                        $appointments->whereHas('dossierOperation', function ($operation) use ($filters, $serviceIds): void {
                            $operation->when($serviceIds !== [], fn ($query) => $query->whereIn('service_id', $serviceIds));
                            if (filled($filters['status'] ?? null) && $filters['status'] !== 'unknown') {
                                $operation->where(function ($q) use ($filters): void {
                                    DossierStatusCatalog::filter($q, $filters['status']);
                                    if ($filters['status'] === 'invoiced') {
                                        $q->orWhereHas('billing');
                                    }
                                });
                            }
                        });
                    });
                } else {
                    $range($query, 'dossiers.created_at');
                }
            })
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
                DossierSearch::apply($query, $filters['q']);
            })
            ->when($companyIds !== [], fn ($query) => $query->where(fn ($party) => $party
                ->whereIn('delegataire_id', $companyIds)->orWhereIn('client_id', $companyIds)))
            ->when($serviceIds !== [], fn ($query) => $query->whereHas('operations', fn ($operations) => $operations->whereIn('service_id', $serviceIds)))
            ->when(($filters['planning'] ?? null) === 'unlinked', fn ($query) => $query->whereDoesntHave('appointments'))
            ->when(($filters['planning'] ?? null) === 'upcoming', fn ($query) => $query->whereHas('appointments', fn ($rdv) => $rdv->where('status', Appointment::STATUS_SCHEDULED)->where('ends_at', '>', now())))
            ->when(($filters['planning'] ?? null) === 'problem', fn ($query) => $query->whereHas('appointments', fn ($rdv) => $rdv->where('status', Appointment::STATUS_PROBLEM)));
    }

    public function references(): array
    {
        return [
            'delegataires' => ExternalDelegataire::query()->orderBy('name')->get(),
            'services' => Service::query()->catalog()->orderBy('name')->get(['id', 'name', 'type']),
            'departments' => Department::query()->orderBy('code')->get(['code', 'name']),
        ];
    }

    public function create(User $actor, array $data): Dossier
    {
        Gate::forUser($actor)->authorize('create', Dossier::class);

        return $this->persistCreation($actor, $data);
    }

    public function createForClient(User $actor, array $data): Dossier
    {
        Gate::forUser($actor)->authorize('createFromPortal', Dossier::class);
        $information = Arr::only($data, [
            'company_name', 'first_name', 'last_name', 'email', 'phone', 'site_name',
            'address', 'postal_code', 'city', 'beneficiary_address', 'beneficiary_postal_code',
            'beneficiary_city', 'installer_name', 'installer_siren', 'service_ids',
        ]);

        return $this->persistCreation($actor, [
            ...$information,
            // A stable, organisation-scoped reference prevents duplicate form submissions.
            'reference' => 'DOS-CLIENT-'.$actor->external_client_id.'-'.Str::upper($data['submission_id']),
            'client_id' => $actor->external_client_id,
            'client_contact' => DossierContacts::details($actor->externalClient),
            'delegataire_id' => null, 'delegataire_contact' => [],
        ]);
    }

    private function persistCreation(User $actor, array $data): Dossier
    {
        if (! is_array($data['service_ids'] ?? null) || count($data['service_ids']) !== 1) {
            throw ValidationException::withMessages(['service_ids' => 'Sélectionnez une seule fiche technique pour ce dossier.']);
        }
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
        DB::transaction(function () use ($actor, $dossier, $body, $clientVisible, $delegataireVisible): void {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('comment', $dossier);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => $body,
                'client_visible' => $actor->isExternal() || $clientVisible,
                'delegataire_visible' => $actor->isExternal() ? $actor->externalClient->kind === 'delegataire' : ($clientVisible && ($delegataireVisible ?? $clientVisible))]);
        });
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
                    ...($actor->isExternal() ? ['document_type' => 'free'] : Arr::only($metadata, ['description', 'document_type', 'dossier_operation_id', 'control_reference', 'inspected_on', 'issued_on', 'control_outcome',
                        'capcee_emmy_reference', 'capcee_object_type', 'capcee_inspection_type'])),
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
        Gate::forUser($actor)->authorize('viewDocuments', $document->dossier);
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
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($document->dossier_id);
            Gate::forUser($actor)->authorize('update', $dossier);
            $document = $dossier->documents()->lockForUpdate()->findOrFail($document->id);
            $document->update(['client_visible' => $visible, 'delegataire_visible' => $visible && ($delegataireVisible ?? $visible)]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_visibility',
                'body' => 'Accès « '.ContentAudience::LABELS[ContentAudience::key($document->client_visible, $document->delegataire_visible)].' » : '.$document->name,
                'changed_fields' => ['document_id' => $document->id, 'client_visible' => $document->client_visible, 'delegataire_visible' => $document->delegataire_visible]]);
        });
    }

    public function shareComment(User $actor, Dossier $dossier, int $id, bool $visible, ?bool $delegataireVisible = null): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $id, $visible, $delegataireVisible): void {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('update', $dossier);
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
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('update', $dossier);
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
            // Serialize withdrawal with control validation and CAPCEE preparation.
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($document->dossier_id);
            Gate::forUser($actor)->authorize('update', $dossier);
            $document = $dossier->documents()->lockForUpdate()->findOrFail($document->id);
            if (CapceeAutomaticSubmission::where('dossier_document_id', $document->id)->exists()) {
                throw ValidationException::withMessages(['document' => 'Ce rapport possède un suivi CAPCEE. Il est conservé pour garantir la traçabilité des transmissions.']);
            }
            $controlEvidence = $dossier->operations()->where('report_document_id', $document->id)->exists()
                || ($document->document_type === 'visit' && $dossier->activities()->where('changed_fields->visit_document_id', $document->id)->exists());
            if ($controlEvidence) {
                Gate::forUser($actor)->authorize('reviewControl', $dossier);
            }
            // Soft deletion hides the attachment, not the control result or its retained evidence.
            $document->forceFill(['client_visible' => false, 'delegataire_visible' => false])->save();
            $document->delete();
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_removed',
                'body' => 'Document retiré : '.$document->name.'. Fichier et trace conservés ; état, avis et facturation inchangés.',
                'changed_fields' => ['document_id' => $document->id, 'document_type' => $document->document_type,
                    'control_evidence_retained' => $controlEvidence, 'control_reference' => $document->control_reference]]);
        });
    }

    private function reportDuplicateReference(string $reference, UniqueConstraintViolationException $exception): never
    {
        if (Dossier::withTrashed()->where('reference', $reference)->exists()) {
            throw ValidationException::withMessages(['reference' => 'Cette référence est déjà utilisée. Le dossier n’a pas été créé en double.']);
        }
        throw $exception;
    }
}
