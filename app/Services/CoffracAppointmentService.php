<?php

namespace App\Services;

use App\Events\ExternalApiSyncProgressed;
use App\Models\Appointment;
use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use App\Models\ExternalServiceAlias;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CoffracAppointmentService
{
    public const SOURCE = 'coffrac';
    public const REMOTE_STATUS_ALL = 'all';
    public const REMOTE_STATUS_PENDING = 'pending';
    public const REMOTE_STATUS_PLACED = 'placed';
    public const REMOTE_STATUS_PROBLEM = 'problem';
    public const PROBLEM_TYPE_RENVOI_CLIENT = 'Renvoi client';
    public const PROBLEM_TYPE_CALLBACK = 'Demande de rapl';
    public const PROBLEM_TYPE_DOCUMENT = 'Problème document';

    private const SYNC_MESSAGE_MAX_LENGTH = 240;

    private int $skippedRemoteAppointmentCount = 0;

    public function __construct(
        private readonly MapboxAddressGeocoder $geocoder,
        private readonly ImportedAddressCleaner $addressCleaner,
        private readonly AppointmentDocumentSerializer $documentSerializer,
    ) {
    }

    public function isConfigured(): bool
    {
        return filled(config('services.coffrac.api_url'))
            && filled(config('services.coffrac.api_token'));
    }

    /**
     * @return array<int, array{value:string,label:string,requires_recall:bool}>
     */
    public function problemTypes(): array
    {
        if (! $this->isConfigured()) {
            return $this->fallbackProblemTypes();
        }

        try {
            return Cache::remember('coffrac:problem-types', now()->addHours(6), function (): array {
                $response = $this->request()->get($this->endpoint('problem-types'));

                if ($response->failed()) {
                    $payload = $response->json();

                    throw new RuntimeException($this->responseError(is_array($payload) ? $payload : null, 'Impossible de récupérer les types de problème Coffrac.'));
                }

                $problemTypes = collect($response->json('data', []))
                    ->filter(fn (mixed $problemType): bool => is_array($problemType))
                    ->map(fn (array $problemType): ?array => $this->normalizeProblemTypeOption($problemType))
                    ->filter()
                    ->values()
                    ->all();

                return $problemTypes !== [] ? $problemTypes : $this->fallbackProblemTypes();
            });
        } catch (Throwable $exception) {
            Log::warning('Impossible de récupérer les types de problème Coffrac.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->fallbackProblemTypes();
        }
    }

    /**
     * @return array<int, string>
     */
    public function problemTypeValues(): array
    {
        return collect($this->problemTypes())
            ->pluck('value')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{comment:string,problem_type:string,recall_date:?string,recall_time:?string}
     */
    private function normalizeProblemReportPayload(array|string $problem): array
    {
        $payload = is_array($problem)
            ? $problem
            : [
                'comment' => $problem,
                'problem_type' => self::PROBLEM_TYPE_RENVOI_CLIENT,
            ];

        $comment = trim((string) ($payload['comment'] ?? ''));

        if ($comment === '') {
            throw new RuntimeException('Un commentaire de problème est obligatoire avant de déclarer un problème RDV.');
        }

        $problemType = trim((string) ($payload['problem_type'] ?? ''));

        if ($problemType === '') {
            throw new RuntimeException('Le type de problème RDV est obligatoire.');
        }

        if (! in_array($problemType, $this->problemTypeValues(), true)) {
            throw new RuntimeException('Le type de problème RDV sélectionné n’est pas reconnu par Coffrac.');
        }

        $requiresRecall = $this->problemTypeRequiresRecall($problemType);
        $recallDate = trim((string) ($payload['recall_date'] ?? ''));
        $recallTime = trim((string) ($payload['recall_time'] ?? ''));

        if ($requiresRecall && ($recallDate === '' || $recallTime === '')) {
            throw new RuntimeException('La date et l’heure de rappel sont obligatoires pour une demande de rappel.');
        }

        return [
            'comment' => $comment,
            'problem_type' => $problemType,
            'recall_date' => $requiresRecall ? $recallDate : null,
            'recall_time' => $requiresRecall ? $recallTime : null,
        ];
    }

    private function problemTypeRequiresRecall(string $problemType): bool
    {
        return collect($this->problemTypes())
            ->firstWhere('value', $problemType)['requires_recall']
            ?? $problemType === self::PROBLEM_TYPE_CALLBACK;
    }

    /**
     * @param array<string, mixed> $problemType
     * @return array{value:string,label:string,requires_recall:bool}|null
     */
    private function normalizeProblemTypeOption(array $problemType): ?array
    {
        $rawValue = trim((string) ($problemType['value'] ?? $problemType['name'] ?? $problemType['label'] ?? ''));

        if ($rawValue === '') {
            return null;
        }

        $normalized = $this->normalizeProblemTypeName($rawValue);
        $value = match (true) {
            str_contains($normalized, 'demande') && str_contains($normalized, 'rap') => self::PROBLEM_TYPE_CALLBACK,
            str_contains($normalized, 'document') => self::PROBLEM_TYPE_DOCUMENT,
            str_contains($normalized, 'renvoi') && str_contains($normalized, 'client') => self::PROBLEM_TYPE_RENVOI_CLIENT,
            default => $rawValue,
        };

        $requiresRecall = (bool) ($problemType['requires_recall'] ?? $value === self::PROBLEM_TYPE_CALLBACK);

        return [
            'value' => $value,
            'label' => trim((string) ($problemType['label'] ?? '')) ?: $this->problemTypeLabel($value),
            'requires_recall' => $requiresRecall,
        ];
    }

    private function normalizeProblemTypeName(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }

    private function problemTypeLabel(string $value): string
    {
        return match ($value) {
            self::PROBLEM_TYPE_CALLBACK => 'Demande de rappel',
            self::PROBLEM_TYPE_DOCUMENT => 'Problème document',
            self::PROBLEM_TYPE_RENVOI_CLIENT => 'Renvoi client',
            default => $value,
        };
    }

    /**
     * @return array<int, array{value:string,label:string,requires_recall:bool}>
     */
    private function fallbackProblemTypes(): array
    {
        return [
            [
                'value' => self::PROBLEM_TYPE_RENVOI_CLIENT,
                'label' => 'Renvoi client',
                'requires_recall' => false,
            ],
            [
                'value' => self::PROBLEM_TYPE_CALLBACK,
                'label' => 'Demande de rappel',
                'requires_recall' => true,
            ],
            [
                'value' => self::PROBLEM_TYPE_DOCUMENT,
                'label' => 'Problème document',
                'requires_recall' => false,
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(int $limit = 15, bool $shuffle = false): Collection
    {
        return $this->pendingWithStatus($limit, $shuffle)['appointments'];
    }

    /**
     * @return array{appointments: Collection<int, array<string, mixed>>, status: array<string, mixed>}
     */
    public function pendingWithStatus(int $limit = 300, bool $shuffle = false): array
    {
        if (! $this->isConfigured()) {
            $this->persistSyncState(
                ExternalApiSync::STATE_NOT_CONFIGURED,
                'COFFRAC_API_URL ou COFFRAC_API_TOKEN est absent.',
                ['appointments_count' => 0],
            );

            return [
                'appointments' => collect(),
                'status' => $this->availabilityStatus(
                    'not_configured',
                    'API Coffrac non configurée',
                    'COFFRAC_API_URL ou COFFRAC_API_TOKEN est absent.',
                    0,
                ),
            ];
        }

        $ignoredReferences = $this->ignoredExternalReferences();
        $baseQuery = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('status', ExternalAppointmentRequest::STATUS_PENDING)
            ->whereNull('appointment_id')
            ->when($ignoredReferences->isNotEmpty(), fn ($query) => $query->whereNotIn('external_reference', $ignoredReferences->all()));
        $totalPendingAppointments = (clone $baseQuery)->count();
        $missingCoordinatesCount = (clone $baseQuery)
            ->where(fn ($query) => $query->whereNull('latitude')->orWhereNull('longitude'))
            ->count();

        $query = (clone $baseQuery)
            ->orderByDesc('remote_updated_at')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->limit($limit);

        $appointments = $query->get()
            ->map(fn (ExternalAppointmentRequest $appointment): array => $this->appointmentFromStoredRequest($appointment))
            ->values();

        if ($shuffle) {
            $appointments = $appointments->shuffle()->values();
        }

        return [
            'appointments' => $appointments,
            'status' => $this->statusFromLastSync(
                $totalPendingAppointments,
                $appointments->count(),
                $missingCoordinatesCount,
            ),
        ];
    }

    public function find(string $id): ?array
    {
        if (! str_starts_with($id, self::SOURCE.'-')) {
            return null;
        }

        $externalReference = $this->externalReferenceFromCrmId($id);

        if ($externalReference === null || $this->isIgnoredExternalReference($externalReference)) {
            return null;
        }

        $storedRequest = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->where('status', ExternalAppointmentRequest::STATUS_PENDING)
            ->whereNull('appointment_id')
            ->first();

        return $storedRequest ? $this->appointmentFromStoredRequest($storedRequest) : null;
    }

    /**
     * Met à jour la copie locale d'une demande Coffrac avant placement dans TechCalendar.
     *
     * @param array{service_id?: int|null, address?: string|null, comment?: string|null} $payload
     * @return array<string, mixed>|null
     */
    public function updatePendingAppointment(string $id, array $payload): ?array
    {
        if (! str_starts_with($id, self::SOURCE.'-')) {
            return null;
        }

        $externalReference = $this->externalReferenceFromCrmId($id);

        if ($externalReference === null || $this->isIgnoredExternalReference($externalReference)) {
            return null;
        }

        $storedRequest = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->where('status', ExternalAppointmentRequest::STATUS_PENDING)
            ->whereNull('appointment_id')
            ->first();

        if (! $storedRequest) {
            return null;
        }

        $updates = [
            'fetched_at' => now(),
        ];

        if (array_key_exists('comment', $payload)) {
            $updates['comment'] = trim((string) $payload['comment']) ?: null;
        }

        if (array_key_exists('service_id', $payload)) {
            $service = filled($payload['service_id'])
                ? Service::query()->find((int) $payload['service_id'])
                : null;

            $updates['service_type'] = $service?->type;
            $updates['service_name'] = $service?->name;
        }

        $addressWasSubmitted = array_key_exists('address', $payload);

        if ($addressWasSubmitted) {
            $address = $this->addressCleaner->clean(trim((string) $payload['address']));

            if (! $address) {
                throw new RuntimeException('Adresse obligatoire pour mettre à jour le RDV Coffrac.');
            }

            $geocodedAddress = $this->geocodedAddress($address, $storedRequest);
            $updates = [
                ...$updates,
                ...$geocodedAddress,
            ];
        }

        if ($addressWasSubmitted && $this->addressCorrectionChanged($storedRequest, $updates)) {
            $persistedRequest = $this->pushAddressCorrection($externalReference, [
                'address' => $updates['address'] ?? $storedRequest->address,
                'address_line' => $updates['address_line'] ?? $storedRequest->address_line,
                'postal_code' => $updates['postal_code'] ?? $storedRequest->postal_code,
                'city' => $updates['city'] ?? $storedRequest->city,
                'latitude' => $updates['latitude'] ?? $storedRequest->latitude,
                'longitude' => $updates['longitude'] ?? $storedRequest->longitude,
                'comment' => $updates['comment'] ?? $storedRequest->comment,
                'techcalendar_external_request_id' => $storedRequest->id,
            ]);

            if ($persistedRequest) {
                $storedRequest = $persistedRequest;
            }
        }

        $payloadOverrides = [
            'techcalendar_overrides' => [
                'service_type' => $updates['service_type'] ?? $storedRequest->service_type,
                'service_name' => $updates['service_name'] ?? $storedRequest->service_name,
                'address' => $updates['address'] ?? $storedRequest->address,
                'address_line' => $updates['address_line'] ?? $storedRequest->address_line,
                'postal_code' => $updates['postal_code'] ?? $storedRequest->postal_code,
                'city' => $updates['city'] ?? $storedRequest->city,
                'latitude' => $updates['latitude'] ?? $storedRequest->latitude,
                'longitude' => $updates['longitude'] ?? $storedRequest->longitude,
                'comment' => $updates['comment'] ?? $storedRequest->comment,
                'updated_at' => now()->toIso8601String(),
            ],
        ];

        $storedRequest->update([
            ...$updates,
            'payload' => [
                ...($storedRequest->payload ?? []),
                ...$payloadOverrides,
            ],
        ]);

        return $this->appointmentFromStoredRequest($storedRequest->refresh());
    }

    /**
     * Déclare une demande Coffrac non encore placée en problème, puis met à jour
     * sa copie locale pour la retirer des RDV à placer.
     *
     * @return array<string, mixed>|null
     */
    public function markPendingAppointmentProblem(string $id, array|string $problem): ?array
    {
        if (! str_starts_with($id, self::SOURCE.'-')) {
            return null;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('API Coffrac non configurée, impossible de signaler le problème RDV.');
        }

        $externalReference = $this->externalReferenceFromCrmId($id);

        if ($externalReference === null || $this->isIgnoredExternalReference($externalReference)) {
            return null;
        }

        $storedRequest = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->where('status', ExternalAppointmentRequest::STATUS_PENDING)
            ->whereNull('appointment_id')
            ->first();

        if (! $storedRequest) {
            return null;
        }

        $problemPayload = $this->normalizeProblemReportPayload($problem);

        $response = $this->request()->post($this->endpoint("appointments/{$externalReference}/problem"), [
            ...$problemPayload,
            'techcalendar_external_request_id' => $storedRequest->id,
        ]);

        if ($response->failed()) {
            $payload = $response->json();

            throw new RuntimeException($this->responseError(is_array($payload) ? $payload : null, 'Impossible de signaler le problème RDV dans Coffrac.'));
        }

        $remotePayload = $response->json('data');

        if (is_array($remotePayload)) {
            $persistedRequest = $this->persistRemoteAppointment($remotePayload);

            if ($persistedRequest) {
                $storedRequest = $persistedRequest;
            }
        }

        $storedRequest->update([
            'status' => ExternalAppointmentRequest::STATUS_PROBLEM,
            'comment' => $problemPayload['comment'],
            'payload' => [
                ...(is_array($storedRequest->payload) ? $storedRequest->payload : []),
                'techcalendar_problem' => $problemPayload,
            ],
            'fetched_at' => now(),
        ]);

        return $this->appointmentFromStoredRequest($storedRequest->refresh());
    }

    /**
     * Synchronise les demandes Coffrac locales depuis l'API distante.
     *
     * @return array{available: bool, message: string, count: int, pending_count: int, placed_count: int, problem_count: int}
     */
    public function sync(int $pageSize = 500, bool $incremental = false, string $status = self::REMOTE_STATUS_ALL): array
    {
        $status = $this->normalizeSyncStatus($status);
        $isPendingOnlySync = $status === self::REMOTE_STATUS_PENDING;

        if (! $this->isConfigured()) {
            $message = 'COFFRAC_API_URL ou COFFRAC_API_TOKEN est absent.';
            $this->persistSyncState(ExternalApiSync::STATE_NOT_CONFIGURED, $message, [
                'appointments_count' => 0,
                'progress' => 100,
                'stage' => $message,
            ]);

            return [
                'available' => false,
                'message' => $message,
                'count' => 0,
                'pending_count' => 0,
                'placed_count' => 0,
                'problem_count' => 0,
            ];
        }

        $lock = Cache::lock('external-api-sync:'.self::SOURCE, 1800);

        if (! $lock->get()) {
            $counts = $this->localStatusCounts();

            return [
                'available' => true,
                'message' => 'Synchronisation Coffrac déjà en cours.',
                'count' => $counts['total_count'],
                ...$counts,
            ];
        }

        try {
            $updatedAfter = $incremental ? $this->incrementalUpdatedAfter() : null;
            $isIncrementalSync = $updatedAfter !== null;

            $this->markSyncQueued($isPendingOnlySync
                ? 'Récupération des RDV à placer Coffrac en cours...'
                : ($isIncrementalSync
                    ? 'Synchronisation incrémentale Coffrac en cours...'
                    : 'Synchronisation complète Coffrac en cours...')
            );
            $this->markSyncProgress(5, 'Connexion à Coffrac...');
            $this->skippedRemoteAppointmentCount = 0;

            try {
                $remoteAppointments = $this->fetchRemoteAppointments($status, $pageSize, updatedAfter: $updatedAfter);
                $remoteAppointments = $remoteAppointments
                    ->filter(fn (array $appointment): bool => filled((string) ($appointment['id'] ?? '')))
                    ->reject(fn (array $appointment): bool => $this->isIgnoredExternalReference((string) ($appointment['id'] ?? '')))
                    ->unique(fn (array $appointment): string => (string) $appointment['id'])
                    ->values();
                $this->markSyncProgress(38, sprintf(
                    $isPendingOnlySync
                        ? 'Récupération Coffrac des RDV à placer terminée: %d demande(s) reçue(s).'
                        : ($isIncrementalSync
                            ? 'Récupération Coffrac terminée: %d RDV modifié(s) reçu(s).'
                            : 'Récupération Coffrac terminée: %d RDV reçu(s).'),
                    $remoteAppointments->count(),
                ), [
                    'total' => $remoteAppointments->count(),
                    'processed' => 0,
                    'mode' => $this->syncMode($status, $isIncrementalSync),
                    'updated_after' => $updatedAfter?->toIso8601String(),
                ]);
            } catch (Throwable $exception) {
                report($exception);

                $message = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Coffrac ne répond pas pour le moment.';
                $message = $this->syncMessage($message);

                $this->persistSyncState(ExternalApiSync::STATE_UNAVAILABLE, $message, [
                    'appointments_count' => 0,
                    'progress' => 100,
                    'stage' => 'Synchronisation Coffrac en erreur.',
                ]);

                return [
                    'available' => false,
                    'message' => $message,
                    'count' => 0,
                    'pending_count' => 0,
                    'placed_count' => 0,
                    'problem_count' => 0,
                ];
            }

            $stored = collect();
            $totalRemoteAppointments = max(1, $remoteAppointments->count());

            foreach ($remoteAppointments->values() as $index => $appointment) {
                try {
                    $storedRequest = $this->persistRemoteAppointment($appointment);
                } catch (Throwable $exception) {
                    $this->skippedRemoteAppointmentCount++;
                    Log::warning('RDV Coffrac ignoré pendant la persistance locale.', [
                        'external_reference' => $appointment['id'] ?? null,
                        'remote_status_name' => $appointment['status_name'] ?? null,
                        'message' => $exception->getMessage(),
                    ]);

                    $storedRequest = null;
                }

                if ($storedRequest) {
                    $stored->push($storedRequest);
                }

                $processedAppointments = $index + 1;

                if ($processedAppointments === 1 || $processedAppointments === $totalRemoteAppointments || $processedAppointments % 5 === 0) {
                    $this->markSyncProgress(
                        40 + (int) floor(($processedAppointments / $totalRemoteAppointments) * 52),
                        sprintf('Synchronisation locale Coffrac %d/%d...', $processedAppointments, $remoteAppointments->count()),
                        [
                            'processed' => $processedAppointments,
                            'total' => $remoteAppointments->count(),
                        ],
                    );
                }
            }

            if (! $isIncrementalSync) {
                $remoteReferences = $stored->pluck('external_reference')->filter()->values();

                $this->markSyncProgress(96, $isPendingOnlySync
                    ? 'Suppression des demandes absentes du flux Coffrac à placer...'
                    : 'Archivage des RDV absents du flux Coffrac...', [
                        'processed' => $remoteAppointments->count(),
                        'total' => $remoteAppointments->count(),
                    ]);

                $absentRequestsQuery = ExternalAppointmentRequest::query()
                    ->where('source', self::SOURCE)
                    ->when(
                        $remoteReferences->isNotEmpty(),
                        fn ($query) => $query->whereNotIn('external_reference', $remoteReferences->all()),
                    )
                    ->whereIn('status', $isPendingOnlySync
                        ? [ExternalAppointmentRequest::STATUS_PENDING]
                        : [
                            ExternalAppointmentRequest::STATUS_PENDING,
                            ExternalAppointmentRequest::STATUS_PLACED,
                            ExternalAppointmentRequest::STATUS_PROBLEM,
                        ]);

                if ($isPendingOnlySync) {
                    $absentRequestsQuery->delete();
                } else {
                    $absentRequestsQuery->update([
                        'status' => ExternalAppointmentRequest::STATUS_ARCHIVED,
                        'fetched_at' => now(),
                    ]);
                }
            } else {
                $this->markSyncProgress(96, 'Finalisation de la synchronisation incrémentale Coffrac...', [
                    'processed' => $remoteAppointments->count(),
                    'total' => $remoteAppointments->count(),
                ]);
            }

            $counts = $this->localStatusCounts();
            $message = sprintf(
                '%s: %d demande(s), %d placée(s), %d en problème.%s',
                $isPendingOnlySync
                    ? 'Récupération Coffrac des RDV à placer terminée'
                    : ($isIncrementalSync ? 'Synchronisation incrémentale Coffrac terminée' : 'Synchronisation Coffrac terminée'),
                $counts['pending_count'],
                $counts['placed_count'],
                $counts['problem_count'],
                $this->skippedRemoteAppointmentCount > 0
                    ? sprintf(' %d RDV ignoré(s) car une ligne distante était invalide.', $this->skippedRemoteAppointmentCount)
                    : '',
            );

            $this->persistSyncState(ExternalApiSync::STATE_AVAILABLE, $message, [
                'appointments_count' => $counts['total_count'],
                'progress' => 100,
                'stage' => $isPendingOnlySync ? 'Récupération des RDV à placer Coffrac terminée.' : 'Synchronisation Coffrac terminée.',
                'processed' => $remoteAppointments->count(),
                'total' => $remoteAppointments->count(),
                'mode' => $this->syncMode($status, $isIncrementalSync),
                'updated_after' => $updatedAfter?->toIso8601String(),
                ...$counts,
            ], touchLastSuccessfulAt: ! $isPendingOnlySync);

            return [
                'available' => true,
                'message' => $message,
                'count' => $counts['total_count'],
                ...$counts,
            ];
        } finally {
            $lock->release();
        }
    }

    private function normalizeSyncStatus(string $status): string
    {
        return in_array($status, [
            self::REMOTE_STATUS_ALL,
            self::REMOTE_STATUS_PENDING,
            self::REMOTE_STATUS_PLACED,
            self::REMOTE_STATUS_PROBLEM,
        ], true) ? $status : self::REMOTE_STATUS_ALL;
    }

    private function syncMode(string $status, bool $isIncrementalSync): string
    {
        if ($status !== self::REMOTE_STATUS_ALL) {
            return $status;
        }

        return $isIncrementalSync ? 'incremental' : 'full';
    }

    private function incrementalUpdatedAfter(): ?Carbon
    {
        $lastSuccessfulSync = ExternalApiSync::query()
            ->where('source', self::SOURCE)
            ->value('last_successful_at');

        $fallbackRemoteUpdate = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->whereNotNull('remote_updated_at')
            ->max('remote_updated_at');

        $referenceDate = $lastSuccessfulSync ?: $fallbackRemoteUpdate;

        if (! $referenceDate) {
            return null;
        }

        return Carbon::parse($referenceDate)
            ->subMinutes((int) config('services.coffrac.incremental_overlap_minutes', 10));
    }

    /**
     * @return array{pending_count: int, placed_count: int, problem_count: int, total_count: int}
     */
    private function localStatusCounts(): array
    {
        $ignoredReferences = $this->ignoredExternalReferences();
        $counts = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->when($ignoredReferences->isNotEmpty(), fn ($query) => $query->whereNotIn('external_reference', $ignoredReferences->all()))
            ->whereIn('status', [
                ExternalAppointmentRequest::STATUS_PENDING,
                ExternalAppointmentRequest::STATUS_PLACED,
                ExternalAppointmentRequest::STATUS_PROBLEM,
            ])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pendingCount = (int) ($counts[ExternalAppointmentRequest::STATUS_PENDING] ?? 0);
        $placedCount = (int) ($counts[ExternalAppointmentRequest::STATUS_PLACED] ?? 0);
        $problemCount = (int) ($counts[ExternalAppointmentRequest::STATUS_PROBLEM] ?? 0);

        return [
            'pending_count' => $pendingCount,
            'placed_count' => $placedCount,
            'problem_count' => $problemCount,
            'total_count' => $pendingCount + $placedCount + $problemCount,
        ];
    }

    public function markPlaced(Appointment $appointment, array $crmAppointment): void
    {
        if (($crmAppointment['external_source'] ?? null) !== self::SOURCE) {
            if ($this->shouldCreateRemoteAppointmentFromLot($crmAppointment)) {
                $this->createRemoteAppointmentFromLot($appointment, $crmAppointment);
            }

            return;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('API Coffrac non configurée, impossible de basculer le RDV en attente visite.');
        }

        $externalReference = (string) ($crmAppointment['external_reference'] ?? '');

        if ($externalReference === '') {
            throw new RuntimeException('Référence Coffrac absente sur le rendez-vous.');
        }

        $appointment->loadMissing('technician:id,email,first_name,last_name');

        $response = $this->request()->post($this->endpoint("appointments/{$externalReference}/placed"), [
            'technician_email' => $appointment->technician?->email,
            'technician_name' => $appointment->technician?->full_name,
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'duration_minutes' => $appointment->duration_minutes,
            'comment' => $appointment->comment,
            'techcalendar_appointment_id' => $appointment->id,
        ]);

        if ($response->failed()) {
            $payload = $response->json();

            if ($this->isMissingRemoteTechnicianError(is_array($payload) ? $payload : null)) {
                $this->markStoredRequestAsLocallyPlacedPendingRemote($appointment, $externalReference);

                Log::warning('RDV Coffrac placé localement sans bascule distante: technicien Coffrac introuvable.', [
                    'external_reference' => $externalReference,
                    'appointment_id' => $appointment->id,
                    'technician_email' => $appointment->technician?->email,
                ]);

                return;
            }

            throw new RuntimeException($this->responseError(is_array($payload) ? $payload : null, 'Impossible de basculer le RDV Coffrac en attente visite.'));
        }

        $storedRequest = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->first();

        $remotePayload = $response->json('data');
        $remoteStatusWasReturned = is_array($remotePayload);

        if (is_array($remotePayload)) {
            $storedRequest = $this->persistRemoteAppointment($remotePayload) ?: $storedRequest;
        }

        $remoteIsPlaced = ! $remoteStatusWasReturned
            || $storedRequest?->status === ExternalAppointmentRequest::STATUS_PLACED;

        $storedRequest?->update([
            'status' => $remoteIsPlaced
                ? ExternalAppointmentRequest::STATUS_PLACED
                : $storedRequest->status,
            'appointment_id' => $appointment->id,
            'technician_email' => $appointment->technician?->email,
            'starts_at' => $appointment->starts_at,
            'duration_minutes' => $appointment->duration_minutes,
            'comment' => $appointment->comment,
            'fetched_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $crmAppointment
     */
    private function shouldCreateRemoteAppointmentFromLot(array $crmAppointment): bool
    {
        return (bool) ($crmAppointment['is_lot'] ?? false)
            && data_get($crmAppointment, 'external_payload.source_type') === 'lot';
    }

    /**
     * @param array<string, mixed> $crmAppointment
     */
    private function createRemoteAppointmentFromLot(Appointment $appointment, array $crmAppointment): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Coffrac non configurée, impossible de créer le dossier depuis le lot.');
        }

        $appointment->loadMissing([
            'service:id,type,name',
            'service.externalAliases:id,service_id,source,external_name',
            'technician:id,email,first_name,last_name',
        ]);

        if (! $appointment->service) {
            throw new RuntimeException('Prestation absente, impossible de créer le dossier Coffrac depuis le lot.');
        }

        $requestPayload = $this->remoteLotCreationPayload($appointment, $crmAppointment);
        $response = $this->request()->post($this->endpoint('appointments'), $requestPayload);

        if ($response->failed()) {
            $payload = $response->json();

            Log::warning('Création du dossier Coffrac depuis un lot refusée.', [
                'appointment_id' => $appointment->id,
                'lot_id' => $requestPayload['lot_id'] ?? null,
                'lot_appointment_id' => $requestPayload['lot_appointment_id'] ?? null,
                'service_type' => $requestPayload['service_type'] ?? null,
                'service_name' => $requestPayload['service_name'] ?? null,
                'technician_email' => $requestPayload['technician_email'] ?? null,
                'status' => $response->status(),
                'message' => is_array($payload) ? $this->responseError($payload, 'Erreur Coffrac.') : 'Réponse Coffrac non JSON.',
            ]);

            throw new RuntimeException($this->responseError(is_array($payload) ? $payload : null, 'Impossible de créer le dossier Coffrac depuis le lot.'));
        }

        $remotePayload = $response->json('data');

        if (! is_array($remotePayload)) {
            throw new RuntimeException('Coffrac a créé le dossier, mais la réponse API ne contient pas de dossier exploitable.');
        }

        $externalReference = trim((string) ($remotePayload['id'] ?? ''));

        if ($externalReference === '') {
            throw new RuntimeException('Coffrac a créé le dossier, mais la référence distante est absente.');
        }

        $appointment->update([
            'external_source' => self::SOURCE,
            'external_reference' => $externalReference,
            'external_payload' => $remotePayload,
        ]);

        $storedRequest = $this->persistRemoteAppointment($remotePayload);
        $storedRequest?->update([
            'appointment_id' => $appointment->id,
            'technician_email' => $appointment->technician?->email,
            'starts_at' => $appointment->starts_at,
            'duration_minutes' => $appointment->duration_minutes,
            'comment' => $appointment->comment,
            'fetched_at' => now(),
        ]);

        $this->markLotAppointmentAsRemoteLinked($appointment, $crmAppointment, $externalReference, $remotePayload);
    }

    /**
     * @param array<string, mixed> $crmAppointment
     * @return array<string, mixed>
     */
    private function remoteLotCreationPayload(Appointment $appointment, array $crmAppointment): array
    {
        $externalPayload = is_array($crmAppointment['external_payload'] ?? null) ? $crmAppointment['external_payload'] : [];
        $rawPayload = data_get($externalPayload, 'raw_payload', []);
        $rawPayload = is_array($rawPayload) ? $rawPayload : [];
        $lotAppointmentId = (int) ($crmAppointment['lot_appointment_id'] ?? data_get($externalPayload, 'lot_appointment_id', 0));

        return [
            'service_type' => $appointment->service?->type,
            'service_name' => $this->coffracServiceNameForLotAppointment($appointment, $externalPayload),
            'technician_email' => $appointment->technician?->email,
            'technician_name' => $appointment->technician?->full_name,
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'duration_minutes' => $appointment->duration_minutes,
            'comment' => $appointment->comment,
            'customer_first_name' => $crmAppointment['first_name'] ?? null,
            'customer_last_name' => $crmAppointment['last_name'] ?? null,
            'customer_name' => $crmAppointment['customer_name'] ?? null,
            'company_name' => $crmAppointment['company_name'] ?? data_get($externalPayload, 'company_name'),
            'site_name' => $crmAppointment['site_name'] ?? data_get($externalPayload, 'site_name'),
            'phone' => $crmAppointment['phone'] ?? null,
            'address' => $crmAppointment['address'] ?? null,
            'address_line' => $crmAppointment['address_line'] ?? ($rawPayload['address_line'] ?? null),
            'postal_code' => $crmAppointment['postal_code'] ?? ($rawPayload['postal_code'] ?? null),
            'city' => $crmAppointment['city'] ?? ($rawPayload['city'] ?? null),
            'latitude' => $crmAppointment['latitude'] ?? null,
            'longitude' => $crmAppointment['longitude'] ?? null,
            'delegataire' => data_get($externalPayload, 'lot_delegataire'),
            'lot_id' => data_get($externalPayload, 'lot_id'),
            'lot_name' => data_get($externalPayload, 'lot_name'),
            'lot_type' => data_get($externalPayload, 'lot_type'),
            'lot_appointment_id' => $lotAppointmentId > 0 ? $lotAppointmentId : null,
            'row_number' => data_get($externalPayload, 'row_number'),
            'global_plus' => data_get($externalPayload, 'lot_global_plus', false),
            'techcalendar_appointment_id' => $appointment->id,
        ];
    }

    private function coffracServiceNameForAppointment(Appointment $appointment): ?string
    {
        $service = $appointment->service;

        if (! $service) {
            return null;
        }

        $alias = $service->relationLoaded('externalAliases')
            ? $service->externalAliases
                ->where('source', self::SOURCE)
                ->sortBy('id')
                ->first()
            : $service->externalAliases()->where('source', self::SOURCE)->orderBy('id')->first();

        return filled($alias?->external_name) ? $alias->external_name : $service->name;
    }

    /**
     * @param array<string, mixed> $externalPayload
     */
    private function coffracServiceNameForLotAppointment(Appointment $appointment, array $externalPayload): ?string
    {
        $selectedAlias = trim((string) (
            data_get($externalPayload, 'lot_coffrac_service_alias_name')
            ?? data_get($externalPayload, 'coffrac_service_alias_name')
            ?? ''
        ));

        return $selectedAlias !== ''
            ? $selectedAlias
            : $this->coffracServiceNameForAppointment($appointment);
    }

    /**
     * @param array<string, mixed> $crmAppointment
     * @param array<string, mixed> $remotePayload
     */
    private function markLotAppointmentAsRemoteLinked(Appointment $appointment, array $crmAppointment, string $externalReference, array $remotePayload): void
    {
        $lotAppointmentId = (int) ($crmAppointment['lot_appointment_id'] ?? data_get($crmAppointment, 'external_payload.lot_appointment_id', 0));

        if ($lotAppointmentId <= 0) {
            return;
        }

        $lotAppointment = LotAppointment::query()->find($lotAppointmentId);

        if (! $lotAppointment) {
            return;
        }

        $rawPayload = is_array($lotAppointment->raw_payload) ? $lotAppointment->raw_payload : [];
        $externalPayload = is_array($crmAppointment['external_payload'] ?? null) ? $crmAppointment['external_payload'] : [];

        $lotAppointment->update([
            'appointment_id' => $appointment->id,
            'external_reference' => $externalReference,
            'source' => self::SOURCE,
            'service_id' => $appointment->service_id,
            'service_type' => $appointment->service?->type,
            'service_name' => $appointment->service?->name,
            'status' => LotAppointment::STATUS_PLACED,
            'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL,
            'raw_payload' => [
                ...$rawPayload,
                'coffrac_service_alias_id' => data_get($externalPayload, 'lot_coffrac_service_alias_id'),
                'coffrac_service_alias_name' => data_get($externalPayload, 'lot_coffrac_service_alias_name'),
                'coffrac_created_payload' => $remotePayload,
            ],
        ]);
    }

    private function markStoredRequestAsLocallyPlacedPendingRemote(Appointment $appointment, string $externalReference): void
    {
        ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->update([
                'appointment_id' => $appointment->id,
                'technician_email' => $appointment->technician?->email,
                'starts_at' => $appointment->starts_at,
                'duration_minutes' => $appointment->duration_minutes,
                'comment' => $appointment->comment,
                'fetched_at' => now(),
            ]);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function isMissingRemoteTechnicianError(?array $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $messages = collect();

        if (isset($payload['message']) && is_string($payload['message'])) {
            $messages->push($payload['message']);
        }

        if (isset($payload['errors']) && is_array($payload['errors'])) {
            $messages = $messages->merge(collect($payload['errors'])->flatten());
        }

        $normalizedMessage = Str::lower(Str::ascii($messages
            ->filter(fn (mixed $message): bool => is_string($message))
            ->implode(' ')));

        return str_contains($normalizedMessage, 'aucun technicien coffrac actif')
            && str_contains($normalizedMessage, 'email');
    }

    public function markProblem(Appointment $appointment, array|string $problem): void
    {
        if ($appointment->external_source !== self::SOURCE) {
            return;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('API Coffrac non configurée, impossible de signaler le problème RDV.');
        }

        if (! filled($appointment->external_reference)) {
            throw new RuntimeException('Référence Coffrac absente sur le rendez-vous.');
        }

        $problemPayload = $this->normalizeProblemReportPayload($problem);

        $response = $this->request()->post($this->endpoint("appointments/{$appointment->external_reference}/problem"), [
            ...$problemPayload,
            'techcalendar_appointment_id' => $appointment->id,
        ]);

        if ($response->failed()) {
            $payload = $response->json();

            throw new RuntimeException($this->responseError(is_array($payload) ? $payload : null, 'Impossible de signaler le problème RDV dans Coffrac.'));
        }

        $remotePayload = $response->json('data');
        $storedRequest = null;

        if (is_array($remotePayload)) {
            $storedRequest = $this->persistRemoteAppointment($remotePayload);
        }

        $storedRequest ??= ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', (string) $appointment->external_reference)
            ->first();

        $storedRequest?->update([
            'status' => ExternalAppointmentRequest::STATUS_PROBLEM,
            'appointment_id' => $appointment->id,
            'comment' => $problemPayload['comment'],
            'payload' => [
                ...(is_array($storedRequest->payload) ? $storedRequest->payload : []),
                'techcalendar_problem' => $problemPayload,
            ],
            'fetched_at' => now(),
        ]);
    }

    /**
     * Corrige l’adresse du dossier source Coffrac quand l’adresse d’un RDV déjà placé
     * est modifiée dans TechCalendar.
     *
     * @param array{address?: string|null, latitude?: mixed, longitude?: mixed} $payload
     */
    public function updateAppointmentAddress(Appointment $appointment, array $payload): void
    {
        if ($appointment->external_source !== self::SOURCE) {
            return;
        }

        $externalReference = trim((string) $appointment->external_reference);

        if ($externalReference === '') {
            throw new RuntimeException('Référence Coffrac absente sur le rendez-vous.');
        }

        $address = $this->addressCleaner->clean(trim((string) ($payload['address'] ?? '')));

        if ($address === null || $address === '') {
            throw new RuntimeException('Adresse obligatoire pour corriger le RDV Coffrac.');
        }

        $latitude = $this->coordinate($payload['latitude'] ?? null, -90, 90);
        $longitude = $this->coordinate($payload['longitude'] ?? null, -180, 180);
        $postalCode = $this->normalizePostalCode($this->postalCodeFromAddress($address));
        $updates = [
            'address' => $address,
            'address_line' => $this->addressLineFromAddress($address),
            'postal_code' => $postalCode,
            'city' => $this->cityFromAddress($address, $postalCode),
            'department_code' => $this->normalizeDepartmentCode(null, $postalCode),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if (! $this->addressCorrectionChanged($appointment, $updates)) {
            return;
        }

        $storedRequest = $this->pushAddressCorrection($externalReference, [
            ...$updates,
            'techcalendar_appointment_id' => $appointment->id,
        ]);

        $storedRequest ??= ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->first();

        if ($storedRequest) {
            $this->updateStoredRequestAddressCorrection($storedRequest, $updates);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pushAddressCorrection(string $externalReference, array $payload): ?ExternalAppointmentRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Coffrac non configurée, impossible de corriger l’adresse du RDV.');
        }

        $externalReference = trim($externalReference);

        if ($externalReference === '') {
            throw new RuntimeException('Référence Coffrac absente sur le rendez-vous.');
        }

        $response = $this->request()->patch(
            $this->endpoint("appointments/{$externalReference}/address"),
            $this->filledPayload($payload),
        );

        if ($response->failed()) {
            $responsePayload = $response->json();

            throw new RuntimeException($this->responseError(is_array($responsePayload) ? $responsePayload : null, 'Impossible de corriger l’adresse du RDV dans Coffrac.'));
        }

        $remotePayload = $response->json('data');

        return is_array($remotePayload)
            ? $this->persistRemoteAppointment($remotePayload)
            : null;
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function updateStoredRequestAddressCorrection(ExternalAppointmentRequest $storedRequest, array $updates): void
    {
        $payload = is_array($storedRequest->payload) ? $storedRequest->payload : [];
        $payload['techcalendar_overrides'] = [
            ...(is_array($payload['techcalendar_overrides'] ?? null) ? $payload['techcalendar_overrides'] : []),
            'address' => $updates['address'] ?? $storedRequest->address,
            'address_line' => $updates['address_line'] ?? $storedRequest->address_line,
            'postal_code' => $updates['postal_code'] ?? $storedRequest->postal_code,
            'city' => $updates['city'] ?? $storedRequest->city,
            'latitude' => $updates['latitude'] ?? $storedRequest->latitude,
            'longitude' => $updates['longitude'] ?? $storedRequest->longitude,
            'updated_at' => now()->toIso8601String(),
        ];

        $postalCode = $updates['postal_code'] ?? $storedRequest->postal_code;

        $storedRequest->update([
            'address' => $updates['address'] ?? $storedRequest->address,
            'address_line' => $updates['address_line'] ?? $storedRequest->address_line,
            'postal_code' => $postalCode,
            'city' => $updates['city'] ?? $storedRequest->city,
            'department_code' => $updates['department_code'] ?? $this->normalizeDepartmentCode($storedRequest->department_code, is_string($postalCode) ? $postalCode : null),
            'latitude' => array_key_exists('latitude', $updates) ? $updates['latitude'] : $storedRequest->latitude,
            'longitude' => array_key_exists('longitude', $updates) ? $updates['longitude'] : $storedRequest->longitude,
            'payload' => $payload,
            'fetched_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function filledPayload(array $payload): array
    {
        return array_filter($payload, fn (mixed $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function addressCorrectionChanged(ExternalAppointmentRequest|Appointment $model, array $updates): bool
    {
        return ! $this->sameNormalizedAddress((string) ($updates['address'] ?? ''), (string) $model->address)
            || $this->coordinateChanged($model->latitude, $updates['latitude'] ?? null)
            || $this->coordinateChanged($model->longitude, $updates['longitude'] ?? null);
    }

    private function coordinateChanged(mixed $left, mixed $right): bool
    {
        if (($left === null || $left === '') && ($right === null || $right === '')) {
            return false;
        }

        if ($left === null || $left === '' || $right === null || $right === '') {
            return true;
        }

        if (! is_numeric($left) || ! is_numeric($right)) {
            return (string) $left !== (string) $right;
        }

        return abs((float) $left - (float) $right) > 0.000001;
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadDocument(Appointment $appointment, UploadedFile $file, ?string $name = null, ?string $comment = null, ?User $uploadedBy = null): array
    {
        if ($appointment->external_source !== self::SOURCE) {
            throw new RuntimeException('Ce rendez-vous n’est pas rattaché à Coffrac.');
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('API Coffrac non configurée, impossible d’ajouter le document.');
        }

        $externalReference = trim((string) $appointment->external_reference);

        if ($externalReference === '') {
            throw new RuntimeException('Référence Coffrac absente sur le rendez-vous.');
        }

        $originalName = $file->getClientOriginalName() ?: 'document';
        $stream = fopen($file->getRealPath(), 'r');

        if (! is_resource($stream)) {
            throw new RuntimeException('Impossible de lire le document à envoyer.');
        }

        try {
            $response = $this->uploadRequest()
                ->attach(
                    'document',
                    $stream,
                    $originalName,
                    ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
                )
                ->post($this->endpoint("appointments/{$externalReference}/documents"), [
                    'name' => trim((string) $name) ?: $originalName,
                    'comment' => trim((string) $comment) ?: null,
                    'techcalendar_appointment_id' => $appointment->id,
                    'uploaded_from' => $uploadedBy ? 'techcalendar_mobile' : 'techcalendar',
                    'uploaded_by_techcalendar_user_id' => $uploadedBy?->id,
                    'uploaded_by_name' => $uploadedBy?->full_name,
                    'uploaded_by_email' => $uploadedBy?->email,
                    'uploaded_by_role' => $uploadedBy && (int) $uploadedBy->role === 2 ? 'technician' : null,
                ]);
        } finally {
            fclose($stream);
        }

        if ($response->failed()) {
            $payload = $response->json();

            throw new RuntimeException($this->responseError(is_array($payload) ? $payload : null, 'Impossible d’ajouter le document dans Coffrac.'));
        }

        $remoteDocument = $response->json('data');

        if (! is_array($remoteDocument)) {
            throw new RuntimeException('Coffrac n’a pas retourné le document ajouté.');
        }

        $document = $this->documentSerializer->normalize([$remoteDocument], self::SOURCE)[0] ?? $remoteDocument;

        $this->syncUploadedDocumentLocally($appointment, $externalReference, $remoteDocument);

        return $document;
    }

    /**
     * Recharge un dossier Coffrac précis et renvoie les documents/commentaires fraîchement synchronisés.
     *
     * @return array{documents: array<int, array<string, mixed>>, comments: array<int, array<string, mixed>>, status: string|null, remote_status_name: string|null, fetched_at: string|null}
     */
    public function refreshAppointment(Appointment $appointment): array
    {
        if ($appointment->external_source !== self::SOURCE) {
            throw new RuntimeException('Ce rendez-vous n’est pas rattaché à Coffrac.');
        }

        $externalReference = trim((string) $appointment->external_reference);

        if ($externalReference === '') {
            throw new RuntimeException('Référence Coffrac absente sur le rendez-vous.');
        }

        $storedRequest = $this->refreshStoredRequest($externalReference);

        if (! $storedRequest) {
            throw new RuntimeException('Dossier Coffrac introuvable.');
        }

        $this->syncRemoteDocumentsIntoAppointment($appointment, $storedRequest);

        return [
            'documents' => $this->documentsFromStoredRequest($storedRequest),
            'comments' => $this->commentsFromStoredRequest($storedRequest),
            'status' => $storedRequest->status,
            'remote_status_name' => $storedRequest->remote_status_name,
            'fetched_at' => $storedRequest->fetched_at?->toIso8601String(),
        ];
    }

    /**
     * Recharge une demande Coffrac à placer et renvoie la version locale à afficher.
     *
     * @return array<string, mixed>|null
     */
    public function refreshPendingAppointment(string $id): ?array
    {
        if (! str_starts_with($id, self::SOURCE.'-')) {
            return null;
        }

        $externalReference = $this->externalReferenceFromCrmId($id);

        if ($externalReference === null || $this->isIgnoredExternalReference($externalReference)) {
            return null;
        }

        $storedRequest = $this->refreshStoredRequest($externalReference);

        return $storedRequest ? $this->appointmentFromStoredRequest($storedRequest) : null;
    }

    private function refreshStoredRequest(string $externalReference): ?ExternalAppointmentRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Coffrac non configurée.');
        }

        if ($this->isIgnoredExternalReference($externalReference)) {
            return null;
        }

        $remoteAppointment = $this->fetchRemoteAppointments(
            self::REMOTE_STATUS_ALL,
            1,
            externalReference: $externalReference,
        )->first();

        if (! is_array($remoteAppointment)) {
            return null;
        }

        return $this->persistRemoteAppointment($remoteAppointment);
    }

    private function syncRemoteDocumentsIntoAppointment(Appointment $appointment, ExternalAppointmentRequest $storedRequest): void
    {
        if (! filled($appointment->external_source) || ! filled($appointment->external_reference)) {
            return;
        }

        if ($appointment->external_source !== $storedRequest->source
            || (string) $appointment->external_reference !== (string) $storedRequest->external_reference) {
            return;
        }

        $payload = is_array($appointment->external_payload) ? $appointment->external_payload : [];
        $payload['documents'] = $storedRequest->documents ?: $this->documentSerializer->fromPayload($storedRequest->payload, $storedRequest->source);
        $payload['comments'] = $this->commentsFromStoredRequest($storedRequest);

        $appointment->forceFill([
            'external_payload' => $payload,
        ])->save();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documentsFromStoredRequest(ExternalAppointmentRequest $storedRequest): array
    {
        return $storedRequest->documents
            ?: $this->documentSerializer->fromPayload($storedRequest->payload, $storedRequest->source);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function commentsFromStoredRequest(ExternalAppointmentRequest $storedRequest): array
    {
        return $storedRequest->comments
            ?: $this->normalizeRemoteComments(data_get($storedRequest->payload, 'comments', []));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchRemoteAppointments(
        string $status,
        int $pageSize,
        ?string $externalReference = null,
        ?Carbon $updatedAfter = null
    ): Collection {
        $appointments = collect();
        $offset = 0;
        $safePageSize = max(1, min(500, $pageSize));
        $pageIndex = 0;

        do {
            $page = $this->fetchRemoteAppointmentPage($status, $safePageSize, $offset, $externalReference, $updatedAfter);

            $appointments = $appointments->merge($page['appointments']);
            $offset += $safePageSize;
            $pageIndex++;

            if (! $externalReference) {
                $this->markSyncProgress(
                    min(35, 8 + ($pageIndex * 4)),
                    sprintf('Récupération Coffrac: %d RDV reçu(s)...', $appointments->count()),
                    [
                        'processed' => $appointments->count(),
                        'total' => 0,
                    ],
                );
            }
        } while (! $externalReference && ! $page['reached_end']);

        return $appointments->values();
    }

    /**
     * @param array<string, mixed> $remoteDocument
     */
    private function syncUploadedDocumentLocally(Appointment $appointment, string $externalReference, array $remoteDocument): void
    {
        $storedRequest = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->first();

        $existingDocuments = $storedRequest?->documents
            ?: $this->documentSerializer->fromPayload($storedRequest?->payload ?? $appointment->external_payload, self::SOURCE);

        $documents = collect($existingDocuments)
            ->push($remoteDocument)
            ->filter(fn (mixed $document): bool => is_array($document))
            ->unique(fn (array $document): string => implode('|', [
                (string) ($document['id'] ?? ''),
                (string) ($document['name'] ?? $document['title'] ?? $document['filename'] ?? ''),
                (string) ($document['url'] ?? $document['download_url'] ?? $document['href'] ?? $document['path'] ?? ''),
            ]))
            ->values()
            ->all();

        $normalizedDocuments = $this->documentSerializer->normalize($documents, self::SOURCE);

        if ($storedRequest) {
            $payload = is_array($storedRequest->payload) ? $storedRequest->payload : [];
            $payload['documents'] = $documents;

            $storedRequest->update([
                'documents' => $normalizedDocuments,
                'payload' => $payload,
                'fetched_at' => now(),
            ]);
        }

        $appointmentPayload = is_array($appointment->external_payload) ? $appointment->external_payload : [];
        $appointmentPayload['documents'] = $documents;

        $appointment->forceFill([
            'external_payload' => $appointmentPayload,
        ])->save();
    }

    /**
     * @return array{appointments: Collection<int, array<string, mixed>>, reached_end: bool}
     */
    private function fetchRemoteAppointmentPage(
        string $status,
        int $limit,
        int $offset,
        ?string $externalReference = null,
        ?Carbon $updatedAfter = null
    ): array {
        $response = $this->request()->get($this->endpoint('appointments'), array_filter([
            'status' => $status,
            'limit' => $limit,
            'offset' => $externalReference ? null : $offset,
            'id' => $externalReference,
            'updated_after' => $externalReference ? null : $updatedAfter?->toIso8601String(),
        ], fn ($value): bool => $value !== null && $value !== ''));

        if ($response->failed()) {
            $payload = $response->json();
            $message = $this->responseError(is_array($payload) ? $payload : null, 'Impossible de récupérer les RDV Coffrac.');

            if (! $externalReference && $this->shouldSplitRemoteError($message)) {
                if ($limit === 1) {
                    $this->skippedRemoteAppointmentCount++;
                    Log::warning('RDV Coffrac ignoré pendant la synchronisation.', [
                        'offset' => $offset,
                        'message' => $message,
                    ]);

                    return [
                        'appointments' => collect(),
                        'reached_end' => false,
                    ];
                }

                $firstLimit = max(1, intdiv($limit, 2));
                $secondLimit = $limit - $firstLimit;
                $firstPage = $this->fetchRemoteAppointmentPage($status, $firstLimit, $offset, updatedAfter: $updatedAfter);

                if ($firstPage['reached_end'] || $secondLimit <= 0) {
                    return $firstPage;
                }

                $secondPage = $this->fetchRemoteAppointmentPage($status, $secondLimit, $offset + $firstLimit, updatedAfter: $updatedAfter);

                return [
                    'appointments' => $firstPage['appointments']->merge($secondPage['appointments'])->values(),
                    'reached_end' => $secondPage['reached_end'],
                ];
            }

            throw new RuntimeException($message);
        }

        $appointments = collect($response->json('data', []))
            ->filter(fn ($appointment): bool => is_array($appointment))
            ->values();
        $fetchedCount = (int) ($response->json('fetched_count') ?? $appointments->count());
        $skippedCount = max(0, (int) ($response->json('skipped_count') ?? 0));

        if ($skippedCount > 0) {
            $this->skippedRemoteAppointmentCount += $skippedCount;
            Log::warning('RDV Coffrac ignoré(s) par l’API distante pendant la synchronisation.', [
                'offset' => $offset,
                'limit' => $limit,
                'skipped_count' => $skippedCount,
            ]);
        }

        return [
            'appointments' => $appointments,
            'reached_end' => $externalReference !== null || $fetchedCount < $limit,
        ];
    }

    private function persistRemoteAppointment(array $appointment): ?ExternalAppointmentRequest
    {
        $externalReference = (string) ($appointment['id'] ?? '');

        if ($externalReference === '' || $this->isIgnoredExternalReference($externalReference)) {
            return null;
        }

        $existingRequest = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->first();
        $normalized = $this->normalizeRemoteAppointment($appointment, $existingRequest);

        if ($normalized === null) {
            return null;
        }

        $existingAppointment = Appointment::query()
            ->where('external_source', self::SOURCE)
            ->where('external_reference', $normalized['external_reference'])
            ->first(['id']);

        $storedRequest = ExternalAppointmentRequest::query()->updateOrCreate(
            [
                'source' => self::SOURCE,
                'external_reference' => $normalized['external_reference'],
            ],
            [
                'status' => $normalized['status'],
                'source_label' => $normalized['source_label'],
                'remote_status_name' => $normalized['remote_status_name'],
                'service_type' => $normalized['service_type'],
                'service_name' => $normalized['service_name'],
                'customer_first_name' => $normalized['customer_first_name'],
                'customer_last_name' => $normalized['customer_last_name'],
                'customer_name' => $normalized['customer_name'],
                'company_name' => $normalized['company_name'],
                'phone' => $normalized['phone'],
                'address' => $normalized['address'],
                'address_line' => $normalized['address_line'],
                'postal_code' => $normalized['postal_code'],
                'city' => $normalized['city'],
                'department_code' => $normalized['department_code'],
                'latitude' => $normalized['latitude'],
                'longitude' => $normalized['longitude'],
                'technician_email' => $normalized['technician_email'],
                'starts_at' => $normalized['starts_at'],
                'duration_minutes' => $normalized['duration_minutes'],
                'comment' => $normalized['comment'],
                'comments' => $normalized['comments'],
                'documents' => $normalized['documents'],
                'payload' => $appointment,
                'appointment_id' => $existingAppointment?->id,
                'remote_updated_at' => $normalized['remote_updated_at'],
                'fetched_at' => now(),
            ],
        );

        $this->syncPlacedAppointment($storedRequest);
        $this->syncLotPhysicalSatisfaction($storedRequest->refresh());

        return $storedRequest->refresh();
    }

    private function syncPlacedAppointment(ExternalAppointmentRequest $request): void
    {
        if ($request->status !== ExternalAppointmentRequest::STATUS_PLACED) {
            return;
        }

        if (! $request->technician_email || ! $request->starts_at || ! $request->duration_minutes || $request->latitude === null || $request->longitude === null) {
            return;
        }

        $service = $this->matchingService([
            'service_type' => $request->service_type,
            'service_name' => $request->service_name,
        ]);
        $technician = User::query()
            ->where('role', 2)
            ->where('email', $request->technician_email)
            ->first(['id', 'email']);
        $creatorId = Appointment::query()
            ->where('external_source', self::SOURCE)
            ->where('external_reference', $request->external_reference)
            ->value('created_by')
            ?? User::query()->where('admin', true)->orderBy('id')->value('id')
            ?? User::query()->where('role', 1)->orderBy('id')->value('id')
            ?? $technician?->id;

        if (! $service || ! $technician || ! $creatorId) {
            return;
        }

        $startsAt = Carbon::parse($request->starts_at);
        $durationMinutes = (int) $request->duration_minutes;
        $appointment = Appointment::query()->updateOrCreate(
            [
                'external_source' => self::SOURCE,
                'external_reference' => $request->external_reference,
            ],
            [
                'service_id' => $service['id'],
                'technician_id' => $technician->id,
                'created_by' => $creatorId,
                'customer_first_name' => $request->customer_first_name ?: 'Client',
                'customer_last_name' => $request->customer_last_name ?: 'Coffrac',
                'customer_phone' => $this->phoneString($request->phone) ?: '',
                'address' => $request->address ?: '',
                'latitude' => (float) $request->latitude,
                'longitude' => (float) $request->longitude,
                'starts_at' => $startsAt,
                'duration_minutes' => $durationMinutes,
                'ends_at' => $startsAt->copy()->addMinutes($durationMinutes),
                'comment' => $request->comment,
                'status' => Appointment::STATUS_SCHEDULED,
                'external_payload' => $request->payload,
            ],
        );

        if ((int) $request->appointment_id !== (int) $appointment->id) {
            $request->update(['appointment_id' => $appointment->id]);
        }
    }

    private function syncLotPhysicalSatisfaction(ExternalAppointmentRequest $request): void
    {
        if ($request->status !== ExternalAppointmentRequest::STATUS_PLACED || ! $request->appointment_id) {
            return;
        }

        $satisfaction = $this->physicalSatisfactionFromRemotePayload($request->payload);

        if ($satisfaction === null) {
            return;
        }

        $lotIds = LotAppointment::query()
            ->where('appointment_id', $request->appointment_id)
            ->pluck('lot_id')
            ->filter()
            ->unique()
            ->values();

        LotAppointment::query()
            ->where('appointment_id', $request->appointment_id)
            ->update([
                'physical_satisfaction' => $satisfaction,
                'physical_satisfaction_synced_at' => now(),
            ]);

        if ($lotIds->isNotEmpty()) {
            $statusService = app(LotStatusService::class);

            Lot::query()
                ->whereIn('id', $lotIds)
                ->get()
                ->each(fn (Lot $lot): mixed => $statusService->refresh($lot));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeRemoteAppointment(array $appointment, ?ExternalAppointmentRequest $existingRequest = null): ?array
    {
        $externalReference = (string) ($appointment['id'] ?? '');

        if ($externalReference === '') {
            return null;
        }

        $addressLine = $this->addressCleaner->clean(trim((string) ($appointment['address_line'] ?? '')) ?: null);
        $postalCode = $this->normalizePostalCode(trim((string) ($appointment['postal_code'] ?? '')) ?: null);
        $city = trim((string) ($appointment['city'] ?? '')) ?: null;
        $address = $this->normalizedAddress(
            trim((string) ($appointment['address'] ?? '')) ?: null,
            $addressLine,
            $postalCode,
            $city,
        );
        $coordinates = $this->coordinatesFromRemoteAppointment($appointment, $address, $existingRequest);

        return [
            'external_reference' => $externalReference,
            'status' => $this->normalizeRemoteStatus($appointment),
            'source_label' => trim((string) ($appointment['source'] ?? 'Coffrac')) ?: 'Coffrac',
            'remote_status_name' => trim((string) ($appointment['status_name'] ?? '')) ?: null,
            'service_type' => trim((string) ($appointment['service_type'] ?? Service::TYPE_COFFRAC)) ?: null,
            'service_name' => trim((string) ($appointment['service_name'] ?? '')) ?: null,
            'customer_first_name' => trim((string) ($appointment['customer_first_name'] ?? 'Client')),
            'customer_last_name' => trim((string) ($appointment['customer_last_name'] ?? 'Coffrac')),
            'customer_name' => trim((string) ($appointment['customer_name'] ?? '')) ?: trim((string) (($appointment['customer_first_name'] ?? '').' '.($appointment['customer_last_name'] ?? ''))),
            'company_name' => $this->normalizedCompanyName($appointment),
            'phone' => $this->phoneString($appointment['phone'] ?? null),
            'address' => $address,
            'address_line' => $addressLine,
            'postal_code' => $postalCode,
            'city' => $city,
            'department_code' => $this->normalizeDepartmentCode($appointment['department_code'] ?? null, $postalCode),
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
            'technician_email' => trim((string) ($appointment['technician_email'] ?? '')) ?: null,
            'starts_at' => ! empty($appointment['starts_at']) ? Carbon::parse($appointment['starts_at']) : null,
            'duration_minutes' => ($appointment['duration_minutes'] ?? null) !== null ? (int) $appointment['duration_minutes'] : null,
            'comment' => trim((string) ($appointment['comment'] ?? '')) ?: null,
            'comments' => $this->normalizeRemoteComments($appointment['comments'] ?? []),
            'documents' => $this->documentSerializer->fromPayload($appointment, self::SOURCE),
            'remote_updated_at' => ! empty($appointment['updated_at']) ? Carbon::parse($appointment['updated_at']) : null,
        ];
    }

    private function normalizedAddress(?string $address, ?string $addressLine, ?string $postalCode, ?string $city): ?string
    {
        $cleanAddress = $this->addressCleaner->clean($address);

        if ($cleanAddress) {
            return $cleanAddress;
        }

        $parts = [
            $addressLine,
            trim(implode(' ', array_filter([$postalCode, $city]))),
            'France',
        ];

        return trim(implode(', ', array_filter($parts))) ?: null;
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function normalizedCompanyName(array $appointment): ?string
    {
        foreach ([
            'company_name',
            'customer_company_name',
            'society_name',
            'societe',
            'entreprise_name',
            'business_name',
        ] as $key) {
            $companyName = trim((string) ($appointment[$key] ?? ''));

            if ($companyName !== '') {
                return $companyName;
            }
        }

        return null;
    }

    /**
     * @param mixed $comments
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRemoteComments(mixed $comments): array
    {
        if (! is_array($comments)) {
            return [];
        }

        return collect($comments)
            ->filter(fn (mixed $comment): bool => is_array($comment))
            ->map(function (array $comment): ?array {
                $text = trim((string) ($comment['text'] ?? $comment['comment'] ?? ''));

                if ($text === '') {
                    return null;
                }

                return [
                    'id' => isset($comment['id']) ? (string) $comment['id'] : null,
                    'text' => $text,
                    'author_id' => isset($comment['author_id']) ? (string) $comment['author_id'] : null,
                    'author_name' => trim((string) ($comment['author_name'] ?? '')) ?: null,
                    'is_private' => (bool) ($comment['is_private'] ?? false),
                    'created_at' => trim((string) ($comment['created_at'] ?? '')) ?: null,
                    'updated_at' => trim((string) ($comment['updated_at'] ?? '')) ?: null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{latitude: float|null, longitude: float|null}
     */
    private function coordinatesFromRemoteAppointment(
        array $appointment,
        ?string $address,
        ?ExternalAppointmentRequest $existingRequest = null
    ): array {
        $latitude = $this->coordinate($appointment['latitude'] ?? null, -90, 90);
        $longitude = $this->coordinate($appointment['longitude'] ?? null, -180, 180);

        if ($latitude !== null && $longitude !== null) {
            return [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        if (
            $existingRequest
            && $existingRequest->latitude !== null
            && $existingRequest->longitude !== null
            && $this->sameNormalizedAddress($address, $existingRequest->address)
        ) {
            return [
                'latitude' => (float) $existingRequest->latitude,
                'longitude' => (float) $existingRequest->longitude,
            ];
        }

        if (! filled($address)) {
            Log::info('RDV Coffrac conservé sans géocodage Mapbox: adresse absente.', [
                'external_reference' => $appointment['id'] ?? null,
            ]);

            return [
                'latitude' => null,
                'longitude' => null,
            ];
        }

        try {
            $geocoding = $this->geocoder->geocode($address);
        } catch (Throwable $exception) {
            Log::warning('Géocodage Mapbox ignoré pendant la synchronisation Coffrac.', [
                'external_reference' => $appointment['id'] ?? null,
                'address' => $address,
                'message' => $exception->getMessage(),
            ]);

            return [
                'latitude' => null,
                'longitude' => null,
            ];
        }

        $latitude = $this->coordinate($geocoding['latitude'] ?? null, -90, 90);
        $longitude = $this->coordinate($geocoding['longitude'] ?? null, -180, 180);

        if ($address && ($latitude === null || $longitude === null)) {
            Log::info('RDV Coffrac conservé sans coordonnées Mapbox.', [
                'external_reference' => $appointment['id'] ?? null,
                'address' => $address,
                'warnings' => $geocoding['warnings'] ?? [],
            ]);
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function sameNormalizedAddress(?string $left, ?string $right): bool
    {
        if (! filled($left) || ! filled($right)) {
            return false;
        }

        return ExternalServiceAlias::normalizeValue($left) === ExternalServiceAlias::normalizeValue($right);
    }

    /**
     * @return array{address: string, address_line: string|null, postal_code: string|null, city: string|null, department_code: string|null, latitude: float, longitude: float}
     */
    private function geocodedAddress(string $address, ExternalAppointmentRequest $storedRequest): array
    {
        $geocoding = $this->geocoder->geocode($address);
        $latitude = $this->coordinate($geocoding['latitude'] ?? null, -90, 90);
        $longitude = $this->coordinate($geocoding['longitude'] ?? null, -180, 180);

        if ($latitude === null || $longitude === null) {
            throw new RuntimeException('Adresse introuvable via Mapbox. Vérifie l’adresse puis relance le géocodage.');
        }

        $formattedAddress = $this->addressCleaner->clean(trim((string) ($geocoding['formatted_address'] ?? ''))) ?: $address;
        $postalCode = $this->normalizePostalCode($this->postalCodeFromAddress($formattedAddress) ?? $storedRequest->postal_code);
        $city = $this->cityFromAddress($formattedAddress, $postalCode) ?: $storedRequest->city;

        return [
            'address' => $formattedAddress,
            'address_line' => $this->addressLineFromAddress($formattedAddress) ?? $address,
            'postal_code' => $postalCode,
            'city' => $city,
            'department_code' => $this->normalizeDepartmentCode($storedRequest->department_code, $postalCode),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function postalCodeFromAddress(?string $address): ?string
    {
        if (! $address || ! preg_match('/\b(\d{5})\b/u', $address, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function cityFromAddress(?string $address, ?string $postalCode): ?string
    {
        if (! $address || ! $postalCode) {
            return null;
        }

        $pattern = '/\b'.preg_quote($postalCode, '/').'\s+([^,]+)/u';

        if (! preg_match($pattern, $address, $matches)) {
            return null;
        }

        return trim($matches[1]) ?: null;
    }

    private function addressLineFromAddress(?string $address): ?string
    {
        $address = trim((string) $address);

        if ($address === '') {
            return null;
        }

        $withoutCountry = trim((string) preg_replace('/,\s*France\s*$/iu', '', $address));
        $parts = array_values(array_filter(array_map('trim', explode(',', $withoutCountry))));
        $addressLine = $parts[0] ?? $withoutCountry;
        $postalCode = $this->postalCodeFromAddress($addressLine);

        if ($postalCode) {
            $addressLine = trim((string) preg_replace('/\b'.preg_quote($postalCode, '/').'\b.*$/u', '', $addressLine));
        }

        return $addressLine !== '' ? $addressLine : $address;
    }

    private function coordinate(mixed $value, float $min, float $max): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $value >= $min && $value <= $max ? $value : null;
    }

    private function phoneString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $phone = trim((string) $value);

        return $phone === '' ? null : Str::limit($phone, 255, '');
    }

    private function normalizePostalCode(?string $postalCode): ?string
    {
        if ($postalCode === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $postalCode);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) === 4) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 5 ? $digits : $postalCode;
    }

    private function normalizeDepartmentCode(mixed $departmentCode, ?string $postalCode): ?string
    {
        $departmentCode = strtoupper(trim((string) $departmentCode));

        if ($postalCode !== null && preg_match('/^\d{5}$/', $postalCode)) {
            return str_starts_with($postalCode, '97')
                ? substr($postalCode, 0, 3)
                : substr($postalCode, 0, 2);
        }

        return preg_match('/^\d{2,3}$|^2A$|^2B$/', $departmentCode) ? $departmentCode : null;
    }

    private function normalizeRemoteStatus(array $appointment): string
    {
        $statusName = Str::ascii(Str::lower((string) ($appointment['status_name'] ?? '')));

        if (str_contains($statusName, 'probleme')) {
            return ExternalAppointmentRequest::STATUS_PROBLEM;
        }

        if (str_contains($statusName, 'attente visite')) {
            return ExternalAppointmentRequest::STATUS_PLACED;
        }

        if (str_contains($statusName, 'rapport genius')) {
            return ExternalAppointmentRequest::STATUS_PLACED;
        }

        return ExternalAppointmentRequest::STATUS_PENDING;
    }

    private function physicalSatisfactionFromRemotePayload(mixed $payload): ?bool
    {
        if (! is_array($payload)) {
            return null;
        }

        $candidates = [
            data_get($payload, 'satisfaction.is_satisfactory'),
            data_get($payload, 'is_satisfactory'),
            data_get($payload, 'physical_satisfaction'),
        ];

        foreach ($candidates as $candidate) {
            if (is_bool($candidate)) {
                return $candidate;
            }
        }

        $numericCandidates = [
            data_get($payload, 'satisfaction.value'),
            data_get($payload, 'is_favorable'),
            data_get($payload, 'favorable'),
        ];

        foreach ($numericCandidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if (is_numeric($candidate)) {
                return match ((int) $candidate) {
                    0 => true,
                    1 => false,
                    default => null,
                };
            }
        }

        $labelCandidates = [
            data_get($payload, 'satisfaction.label'),
            data_get($payload, 'satisfaction'),
            data_get($payload, 'rapport_avis'),
            data_get($payload, 'avis'),
        ];

        foreach ($labelCandidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $normalized = Str::lower(Str::ascii($candidate));

            if (str_contains($normalized, 'non satisf')) {
                return false;
            }

            if (str_contains($normalized, 'satisf')) {
                return true;
            }
        }

        return null;
    }

    private function appointmentFromStoredRequest(ExternalAppointmentRequest $request): array
    {
        $comments = $request->comments ?: $this->normalizeRemoteComments(data_get($request->payload, 'comments', []));
        $documents = $request->documents ?: $this->documentSerializer->fromPayload($request->payload, $request->source);
        $service = $this->matchingService([
            'service_type' => $request->service_type,
            'service_name' => $request->service_name,
        ]);
        $rawServiceName = trim((string) $request->service_name);
        $problemPayload = is_array(data_get($request->payload, 'techcalendar_problem'))
            ? data_get($request->payload, 'techcalendar_problem')
            : [];
        $problemType = trim((string) (
            data_get($request->payload, 'problem_type')
            ?: data_get($request->payload, 'sub_statut')
            ?: data_get($problemPayload, 'problem_type')
        )) ?: null;
        $problemComment = trim((string) (
            data_get($request->payload, 'problem_comment')
            ?: data_get($request->payload, 'commentaire_status')
            ?: data_get($problemPayload, 'comment')
        )) ?: null;
        $recallDate = trim((string) (
            data_get($request->payload, 'recall_date')
            ?: data_get($problemPayload, 'recall_date')
        )) ?: null;
        $recallTime = trim((string) (
            data_get($request->payload, 'recall_time')
            ?: data_get($problemPayload, 'recall_time')
        )) ?: null;
        $recallAt = data_get($request->payload, 'recall_at')
            ?: data_get($request->payload, 'date_rappel')
            ?: ($recallDate && $recallTime ? $recallDate.' '.$recallTime : null);

        return [
            'id' => self::SOURCE.'-'.$request->external_reference,
            'external_source' => self::SOURCE,
            'external_reference' => $request->external_reference,
            'external_payload' => $request->payload,
            'status' => $request->status,
            'remote_status_name' => $request->remote_status_name,
            'created_at' => $request->created_at?->toIso8601String(),
            'fetched_at' => $request->fetched_at?->toIso8601String(),
            'source' => $request->source_label ?: 'Coffrac',
            'first_name' => $request->customer_first_name ?: 'Client',
            'last_name' => $request->customer_last_name ?: 'Coffrac',
            'company_name' => $request->company_name ?: trim((string) data_get($request->payload, 'company_name')) ?: null,
            'phone' => $request->phone ?: '',
            'address' => $request->address ?: '',
            'address_line' => $request->address_line,
            'postal_code' => $request->postal_code,
            'city' => $request->city,
            'department_code' => strtoupper((string) $request->department_code),
            'latitude' => $request->latitude !== null ? (float) $request->latitude : null,
            'longitude' => $request->longitude !== null ? (float) $request->longitude : null,
            'preferred_starts_at' => null,
            'is_manual' => false,
            'is_lot' => false,
            'documents' => $documents,
            'comment' => $request->comment,
            'comments' => $comments,
            'problem_type' => $problemType,
            'problem_comment' => $problemComment,
            'recall_date' => $recallDate,
            'recall_time' => $recallTime,
            'recall_at' => $this->optionalIsoDateTime($recallAt),
            'service_type' => $request->service_type,
            'service_name' => $rawServiceName !== '' ? $rawServiceName : null,
            'service_display_name' => $service['name'] ?? ($rawServiceName !== '' ? $rawServiceName : null),
            'service' => $service,
        ];
    }

    /**
     * @return array{id:int,type:string,name:string,average_duration_minutes:int}|null
     */
    private function matchingService(array $appointment): ?array
    {
        $type = trim((string) ($appointment['service_type'] ?? Service::TYPE_COFFRAC));
        $name = trim((string) ($appointment['service_name'] ?? ''));

        if ($type === '' || $name === '') {
            return null;
        }

        $service = $this->serviceFromExternalAlias($type, $name)
            ?? Service::query()
            ->where('type', $type)
            ->where('name', $name)
            ->first(['id', 'type', 'name', 'average_duration_minutes']);

        $service ??= Service::query()
            ->where('type', $type)
            ->get(['id', 'type', 'name', 'average_duration_minutes'])
            ->first(fn (Service $candidate): bool => ExternalServiceAlias::normalizeValue($candidate->name) === ExternalServiceAlias::normalizeValue($name));

        return $service ? [
            'id' => $service->id,
            'type' => $service->type,
            'name' => $service->name,
            'average_duration_minutes' => $service->average_duration_minutes,
        ] : null;
    }

    private function serviceFromExternalAlias(string $type, string $name): ?Service
    {
        $alias = ExternalServiceAlias::query()
            ->with('service:id,type,name,average_duration_minutes')
            ->where('source', self::SOURCE)
            ->where('normalized_external_type', ExternalServiceAlias::normalizeValue($type))
            ->where('normalized_external_name', ExternalServiceAlias::normalizeValue($name))
            ->first();

        return $alias?->service;
    }

    private function optionalIsoDateTime(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('services.coffrac.api_token'))
            ->timeout((int) config('services.coffrac.timeout', 15))
            ->connectTimeout((int) config('services.coffrac.connect_timeout', 5))
            ->retry(2, 250, throw: false);
    }

    private function uploadRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('services.coffrac.api_token'))
            ->timeout((int) config('services.coffrac.upload_timeout', 60))
            ->connectTimeout((int) config('services.coffrac.connect_timeout', 5));
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.coffrac.api_url'), '/').'/techcalendar/'.ltrim($path, '/');
    }

    private function externalReferenceFromCrmId(string $id): ?string
    {
        $reference = substr($id, strlen(self::SOURCE.'-'));

        return $reference !== '' ? $reference : null;
    }

    /**
     * @return Collection<int, string>
     */
    private function ignoredExternalReferences(): Collection
    {
        return collect(config('services.coffrac.ignored_references', []))
            ->map(fn (mixed $reference): string => trim((string) $reference))
            ->filter()
            ->unique()
            ->values();
    }

    private function isIgnoredExternalReference(string $externalReference): bool
    {
        $externalReference = trim($externalReference);

        return $externalReference !== ''
            && $this->ignoredExternalReferences()->contains($externalReference);
    }

    /**
     * @return array{state:string,label:string,detail:string,count:int,progress:int,stage:string}
     */
    private function statusFromLastSync(int $count, ?int $displayedCount = null, int $missingCoordinatesCount = 0): array
    {
        $sync = ExternalApiSync::query()->where('source', self::SOURCE)->first();

        if (! $sync) {
            return $this->availabilityStatus(
                'unavailable',
                'API Coffrac non synchronisée',
                'Aucune synchronisation Coffrac n’a encore été exécutée.',
                $count,
            );
        }

        $isStaleSync = $sync->state === ExternalApiSync::STATE_SYNCING
            && $sync->updated_at !== null
            && $sync->updated_at->lt(now()->subMinutes(10));

        $state = $isStaleSync ? 'unavailable' : match ($sync->state) {
            ExternalApiSync::STATE_AVAILABLE => 'available',
            ExternalApiSync::STATE_SYNCING => 'syncing',
            ExternalApiSync::STATE_NOT_CONFIGURED => 'not_configured',
            default => 'unavailable',
        };
        $label = $isStaleSync ? 'Synchronisation Coffrac interrompue' : match ($sync->state) {
            ExternalApiSync::STATE_AVAILABLE => 'API Coffrac disponible',
            ExternalApiSync::STATE_SYNCING => 'Synchronisation Coffrac en cours',
            ExternalApiSync::STATE_NOT_CONFIGURED => 'API Coffrac non configurée',
            default => 'API Coffrac indisponible',
        };
        $lastSync = $sync->last_successful_at?->format('d/m/Y H:i');
        $metadata = $sync->metadata ?? [];
        $detail = $isStaleSync
            ? 'La synchronisation Coffrac semble bloquée. Vérifie que le worker de queue est lancé puis relance une actualisation.'
            : trim(($sync->message ?: 'Statut Coffrac inconnu.').($lastSync ? " Dernière synchro: {$lastSync}." : ''));

        return $this->availabilityStatus(
            $state,
            $label,
            $detail,
            $count,
            $isStaleSync ? 100 : (int) ($metadata['progress'] ?? ($state === 'syncing' ? 5 : 100)),
            (string) ($metadata['stage'] ?? $sync->message ?? $label),
            $displayedCount,
            $missingCoordinatesCount,
        );
    }

    public function markSyncQueued(string $message = 'Synchronisation Coffrac en cours...'): ExternalApiSync
    {
        $sync = ExternalApiSync::query()->updateOrCreate(
            ['source' => self::SOURCE],
            [
                'state' => ExternalApiSync::STATE_SYNCING,
                'message' => $this->syncMessage($message),
                'last_started_at' => now(),
                'metadata' => [
                    'progress' => 3,
                    'stage' => $message,
                    'processed' => 0,
                    'total' => 0,
                ],
            ],
        );

        $this->broadcastSync($sync->refresh());

        return $sync;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function markSyncProgress(int $progress, string $stage, array $metadata = []): ExternalApiSync
    {
        $sync = ExternalApiSync::query()->firstOrNew(['source' => self::SOURCE]);
        $sync->fill([
            'state' => ExternalApiSync::STATE_SYNCING,
            'message' => $this->syncMessage($stage),
            'metadata' => array_merge($sync->metadata ?? [], $metadata, [
                'progress' => max(0, min(99, $progress)),
                'stage' => $stage,
            ]),
        ]);

        if (! $sync->last_started_at) {
            $sync->last_started_at = now();
        }

        $sync->save();
        $this->broadcastSync($sync->refresh());

        return $sync;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function persistSyncState(
        string $state,
        string $message,
        array $metadata,
        bool $touchLastSuccessfulAt = true,
    ): ExternalApiSync {
        $sync = ExternalApiSync::query()->firstOrNew(['source' => self::SOURCE]);
        $finalMetadata = array_merge($sync->metadata ?? [], $metadata);
        $finalMetadata['progress'] = (int) ($finalMetadata['progress'] ?? 100);
        $finalMetadata['stage'] = (string) ($finalMetadata['stage'] ?? $message);

        $sync->fill([
            'state' => $state,
            'message' => $this->syncMessage($message),
            'last_finished_at' => now(),
            'metadata' => $finalMetadata,
        ]);

        if ($state === ExternalApiSync::STATE_AVAILABLE && $touchLastSuccessfulAt) {
            $sync->last_successful_at = now();
        }

        $sync->save();
        $this->broadcastSync($sync->refresh());

        return $sync;
    }

    public function markSyncFailed(string $message): ExternalApiSync
    {
        return $this->persistSyncState(ExternalApiSync::STATE_UNAVAILABLE, $this->syncMessage($message), [
            'progress' => 100,
            'stage' => 'Synchronisation Coffrac en erreur.',
        ]);
    }

    private function broadcastSync(ExternalApiSync $sync): void
    {
        try {
            broadcast(new ExternalApiSyncProgressed($sync));
        } catch (Throwable $exception) {
            Log::warning('External API sync progress broadcast failed.', [
                'source' => $sync->source,
                'state' => $sync->state,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function syncMessage(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return Str::limit($message !== '' ? $message : 'Statut Coffrac indisponible.', self::SYNC_MESSAGE_MAX_LENGTH - 3, '...');
    }

    private function shouldSplitRemoteError(string $message): bool
    {
        $normalized = Str::lower(Str::ascii($message));

        return str_contains($normalized, 'getkey() on array');
    }

    /**
     * @return array{state:string,label:string,detail:string,count:int,progress:int,stage:string}
     */
    private function availabilityStatus(
        string $state,
        string $label,
        string $detail,
        int $count,
        int $progress = 100,
        string $stage = '',
        ?int $displayedCount = null,
        int $missingCoordinatesCount = 0,
    ): array
    {
        return [
            'state' => $state,
            'label' => $label,
            'detail' => $detail,
            'count' => $count,
            'displayed_count' => $displayedCount ?? $count,
            'missing_coordinates_count' => $missingCoordinatesCount,
            'progress' => max(0, min(100, $progress)),
            'stage' => $stage !== '' ? $stage : $label,
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function responseError(?array $payload, string $fallback): string
    {
        if (is_array($payload)) {
            if (isset($payload['message']) && is_string($payload['message'])) {
                return $payload['message'];
            }

            if (isset($payload['errors']) && is_array($payload['errors'])) {
                $firstError = collect($payload['errors'])->flatten()->first();

                if (is_string($firstError)) {
                    return $firstError;
                }
            }
        }

        return $fallback;
    }
}
