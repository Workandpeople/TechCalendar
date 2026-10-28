<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalAppointmentRequest;
use App\Models\LotAppointmentDocument;
use App\Services\Crm\CollectedDossierDocuments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AppointmentDocumentSerializer
{
    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function forAppointments(Collection $appointments): array
    {
        if (config('crm.v2_enabled')) {
            return $this->nativeDocumentsByAppointment($appointments);
        }

        $fallbackDocuments = $this->fallbackDocumentsByExternalReference($appointments);

        return $appointments
            ->mapWithKeys(function (Appointment $appointment) use ($fallbackDocuments): array {
                $fallbackKey = $this->appointmentExternalKey($appointment);

                return [
                    $appointment->id => $this->forAppointment(
                        $appointment,
                        $fallbackKey ? ($fallbackDocuments[$fallbackKey] ?? []) : [],
                    ),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $fallbackDocuments
     * @return array<int, array<string, mixed>>
     */
    public function forAppointment(Appointment $appointment, array $fallbackDocuments = []): array
    {
        if (config('crm.v2_enabled')) {
            return $this->nativeDocumentsByAppointment(collect([$appointment]))[$appointment->id] ?? [];
        }

        $documents = $this->documentsFromPayload($appointment->external_payload);

        if (! is_array($documents) || $documents === []) {
            $documents = $fallbackDocuments;
        }

        return $this->normalize($documents, $appointment->external_source);
    }

    /** Load shared dossier files once per calendar, never from the retired Cofrac connector. */
    private function nativeDocumentsByAppointment(Collection $appointments): array
    {
        if ($appointments->isEmpty() || ! Gate::allows('viewAny', Dossier::class)) {
            return [];
        }

        $models = (new Appointment)->newCollection($appointments->all());
        $models->loadMissing('dossierOperation.dossier.documents');
        $dossiers = $models->map(fn ($appointment) => $appointment->dossierOperation?->dossier)->filter()->unique('id');
        $byDossier = $dossiers->mapWithKeys(fn (Dossier $dossier): array => [
            $dossier->id => $dossier->documents->map(fn ($document): array => [
                'id' => 'dossier-'.$document->id, 'name' => $document->name,
                'url' => route('v2.documents.download', $document),
                'scope' => 'Dossier', 'is_private' => true, 'is_delegataire' => false,
            ])->all(),
        ])->all();

        if ($dossiers->isNotEmpty()) {
            foreach (app(CollectedDossierDocuments::class)->forDossiers(auth()->user(), $dossiers) as $dossierId => $documents) {
                $byDossier[$dossierId] = array_merge($byDossier[$dossierId] ?? [], $documents);
            }
            $dossierIds = $dossiers->pluck('id')->all();
            $linked = LotAppointmentDocument::query()
                ->where(fn ($query) => $query
                    ->whereHas('appointment', fn ($appointment) => $appointment->withTrashed()
                        ->whereHas('dossierOperation', fn ($operation) => $operation->whereIn('dossier_id', $dossierIds)))
                    ->orWhereHas('lotAppointment.appointment', fn ($appointment) => $appointment->withTrashed()
                        ->whereHas('dossierOperation', fn ($operation) => $operation->whereIn('dossier_id', $dossierIds))))
                ->with([
                    'appointment' => fn ($query) => $query->withTrashed()->with('dossierOperation'),
                    'lotAppointment.appointment' => fn ($query) => $query->withTrashed()->with('dossierOperation'),
                ])->get();

            foreach ($linked as $document) {
                $owners = array_unique(array_filter([
                    $document->appointment?->dossierOperation?->dossier_id,
                    $document->lotAppointment?->appointment?->dossierOperation?->dossier_id,
                ]));
                foreach ($owners as $dossierId) {
                    if (array_key_exists($dossierId, $byDossier)) {
                        $byDossier[$dossierId][] = [
                            'id' => 'lot-'.$document->id, 'name' => $document->name,
                            'url' => route('v2.dossiers.lot-documents.download', [$dossierId, $document->id]),
                            'scope' => 'Lot', 'is_private' => $document->is_private, 'is_delegataire' => false,
                        ];
                    }
                }
            }
        }

        return $models->mapWithKeys(fn ($appointment): array => [
            $appointment->id => $byDossier[$appointment->dossierOperation?->dossier_id] ?? [],
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalize(mixed $documents, ?string $source = null): array
    {
        if (! is_array($documents)) {
            return [];
        }

        return collect($documents)
            ->filter(fn (mixed $document): bool => is_array($document))
            ->map(function (array $document) use ($source): ?array {
                $name = trim((string) ($document['name'] ?? $document['title'] ?? $document['filename'] ?? $document['original_name'] ?? ''));
                $comment = trim((string) ($document['comment'] ?? ''));
                $scope = trim((string) ($document['scope'] ?? $document['type'] ?? ''));
                $path = trim((string) ($document['path'] ?? ''));
                $url = $this->documentUrl($document, $source);

                if (($document['id'] ?? null) === null && $name === '' && $path === '' && $url === null) {
                    return null;
                }

                return [
                    'id' => $document['id'] ?? null,
                    'scope' => $scope !== '' ? $scope : null,
                    'name' => $name !== '' ? $name : 'Document',
                    'comment' => $comment !== '' ? $comment : null,
                    'path' => $path !== '' ? $path : null,
                    'url' => $url,
                    'is_private' => (bool) ($document['is_private'] ?? false),
                    'is_delegataire' => (bool) ($document['is_delegataire'] ?? false),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fromPayload(mixed $payload, ?string $source = null): array
    {
        return $this->normalize($this->documentsFromPayload($payload), $source);
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fallbackDocumentsByExternalReference(Collection $appointments): array
    {
        $referencesBySource = $appointments
            ->filter(fn (Appointment $appointment): bool => filled($appointment->external_source) && filled($appointment->external_reference))
            ->groupBy('external_source')
            ->map(fn (Collection $sourceAppointments): array => $sourceAppointments
                ->pluck('external_reference')
                ->map(fn (mixed $reference): string => (string) $reference)
                ->unique()
                ->values()
                ->all());

        if ($referencesBySource->isEmpty()) {
            return [];
        }

        $requests = ExternalAppointmentRequest::query()
            ->where(function ($query) use ($referencesBySource): void {
                $referencesBySource->each(function (array $references, string $source) use ($query): void {
                    $query->orWhere(function ($sourceQuery) use ($source, $references): void {
                        $sourceQuery
                            ->where('source', $source)
                            ->whereIn('external_reference', $references);
                    });
                });
            })
            ->get(['source', 'external_reference', 'documents']);

        return $requests
            ->mapWithKeys(fn (ExternalAppointmentRequest $request): array => [
                $request->source.'|'.$request->external_reference => $this->normalize($request->documents, $request->source),
            ])
            ->all();
    }

    private function appointmentExternalKey(Appointment $appointment): ?string
    {
        if (! filled($appointment->external_source) || ! filled($appointment->external_reference)) {
            return null;
        }

        return $appointment->external_source.'|'.$appointment->external_reference;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documentsFromPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $this->normalizeDocumentList($payload);
        }

        $documents = collect([
            'documents',
            'document',
            'data.documents',
            'appointment.documents',
            'dossier.documents',
            'dossier_documents',
            'files',
            'fichiers',
            'attachments',
            'pieces_jointes',
            'piecesJointes',
        ])
            ->flatMap(fn (string $path): array => $this->normalizeDocumentList(data_get($payload, $path)))
            ->values()
            ->all();

        return collect($documents)
            ->unique(fn (array $document): string => implode('|', [
                (string) ($document['id'] ?? ''),
                (string) ($document['name'] ?? $document['title'] ?? $document['filename'] ?? ''),
                (string) ($document['url'] ?? $document['download_url'] ?? $document['href'] ?? $document['path'] ?? ''),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDocumentList(mixed $documents): array
    {
        if (! is_array($documents) || $documents === []) {
            return [];
        }

        if (! array_is_list($documents)) {
            return [array_filter($documents, fn ($value): bool => $value !== null)];
        }

        return collect($documents)
            ->filter(fn (mixed $document): bool => is_array($document))
            ->values()
            ->all();
    }

    private function documentUrl(array $document, ?string $source): ?string
    {
        $url = trim((string) ($document['url'] ?? $document['download_url'] ?? $document['href'] ?? ''));

        if ($url !== '') {
            return $url;
        }

        $path = trim((string) ($document['path'] ?? ''));

        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if ($source !== 'coffrac') {
            return null;
        }

        $coffracBaseUrl = $this->coffracPublicBaseUrl();

        return $coffracBaseUrl ? $coffracBaseUrl.'/documents/'.ltrim($path, '/') : null;
    }

    private function coffracPublicBaseUrl(): ?string
    {
        $baseUrl = trim((string) config('services.coffrac.api_url'));

        if ($baseUrl === '') {
            return null;
        }

        return rtrim(preg_replace('#/api/?$#', '', $baseUrl) ?: $baseUrl, '/');
    }
}
