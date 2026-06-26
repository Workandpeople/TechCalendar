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
        $documents = data_get($appointment->external_payload, 'documents', []);

        if (! is_array($documents) || $documents === []) {
            $documents = $fallbackDocuments;
        }

        return $this->normalize($documents);
    }

    /**
     * @param  mixed  $documents
     * @return array<int, array<string, mixed>>
     */
    public function normalize(mixed $documents): array
    {
        if (! is_array($documents)) {
            return [];
        }

        return collect($documents)
            ->filter(fn (mixed $document): bool => is_array($document))
            ->map(function (array $document): array {
                $name = trim((string) ($document['name'] ?? $document['title'] ?? $document['filename'] ?? $document['original_name'] ?? ''));
                $comment = trim((string) ($document['comment'] ?? ''));
                $scope = trim((string) ($document['scope'] ?? $document['type'] ?? ''));
                $url = trim((string) ($document['url'] ?? $document['download_url'] ?? $document['href'] ?? ''));

                return [
                    'id' => $document['id'] ?? null,
                    'scope' => $scope !== '' ? $scope : null,
                    'name' => $name !== '' ? $name : 'Document',
                    'comment' => $comment !== '' ? $comment : null,
                    'url' => $url !== '' ? $url : null,
                    'is_private' => (bool) ($document['is_private'] ?? false),
                    'is_delegataire' => (bool) ($document['is_delegataire'] ?? false),
                ];
            })
            ->values()
            ->all();
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
                $request->source.'|'.$request->external_reference => $this->normalize($request->documents),
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
}
