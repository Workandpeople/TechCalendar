<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ExternalAppointmentRequest;
use Illuminate\Support\Collection;

class AppointmentDocumentSerializer
{
    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function forAppointments(Collection $appointments): array
    {
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
        $documents = $this->documentsFromPayload($appointment->external_payload);

        if (! is_array($documents) || $documents === []) {
            $documents = $fallbackDocuments;
        }

        return $this->normalize($documents, $appointment->external_source);
    }

    /**
     * @param  mixed  $documents
     * @return array<int, array<string, mixed>>
     */
    public function normalize(mixed $documents, ?string $source = null): array
    {
        if (! is_array($documents)) {
            return [];
        }

        return collect($documents)
            ->filter(fn (mixed $document): bool => is_array($document))
            ->map(function (array $document) use ($source): array {
                $name = trim((string) ($document['name'] ?? $document['title'] ?? $document['filename'] ?? $document['original_name'] ?? ''));
                $comment = trim((string) ($document['comment'] ?? ''));
                $scope = trim((string) ($document['scope'] ?? $document['type'] ?? ''));
                $path = trim((string) ($document['path'] ?? ''));
                $url = $this->documentUrl($document, $source);

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
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $payload
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
     * @param  mixed  $payload
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
            'fiche.documents',
            'dossier_documents',
            'fiche_documents',
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
     * @param  mixed  $documents
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
