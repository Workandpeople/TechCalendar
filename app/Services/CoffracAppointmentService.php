<?php

namespace App\Services;

use App\Events\ExternalApiSyncProgressed;
use App\Models\Appointment;
use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use App\Models\ExternalServiceAlias;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
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

        $baseQuery = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('status', ExternalAppointmentRequest::STATUS_PENDING)
            ->whereNull('appointment_id');
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

        if ($externalReference === null) {
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

        if ($externalReference === null) {
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
            'comment' => trim((string) ($payload['comment'] ?? '')) ?: null,
            'fetched_at' => now(),
        ];

        if (array_key_exists('service_id', $payload)) {
            $service = filled($payload['service_id'])
                ? Service::query()->find((int) $payload['service_id'])
                : null;

            $updates['service_type'] = $service?->type;
            $updates['service_name'] = $service?->name;
        }

        if (array_key_exists('address', $payload)) {
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

        $payloadOverrides = [
            'techcalendar_overrides' => [
                'service_type' => $updates['service_type'] ?? $storedRequest->service_type,
                'service_name' => $updates['service_name'] ?? $storedRequest->service_name,
                'address' => $updates['address'] ?? $storedRequest->address,
                'comment' => $updates['comment'],
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
                    ? 'Archivage des demandes absentes du flux Coffrac à placer...'
                    : 'Archivage des RDV absents du flux Coffrac...', [
                        'processed' => $remoteAppointments->count(),
                        'total' => $remoteAppointments->count(),
                    ]);

                ExternalAppointmentRequest::query()
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
                        ])
                    ->update([
                        'status' => ExternalAppointmentRequest::STATUS_ARCHIVED,
                        'fetched_at' => now(),
                    ]);
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
        $counts = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
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

    public function markProblem(Appointment $appointment, string $comment): void
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

        $response = $this->request()->post($this->endpoint("appointments/{$appointment->external_reference}/problem"), [
            'comment' => $comment,
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
            'comment' => $comment,
            'fetched_at' => now(),
        ]);
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

        if ($externalReference === '') {
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
                'documents' => $normalized['documents'],
                'payload' => $appointment,
                'appointment_id' => $existingAppointment?->id,
                'remote_updated_at' => $normalized['remote_updated_at'],
                'fetched_at' => now(),
            ],
        );

        $this->syncPlacedAppointment($storedRequest);

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
            'address_line' => $address,
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

        return ExternalAppointmentRequest::STATUS_PENDING;
    }

    private function appointmentFromStoredRequest(ExternalAppointmentRequest $request): array
    {
        $documents = $request->documents ?: $this->documentSerializer->fromPayload($request->payload, $request->source);

        return [
            'id' => self::SOURCE.'-'.$request->external_reference,
            'external_source' => self::SOURCE,
            'external_reference' => $request->external_reference,
            'external_payload' => $request->payload,
            'source' => $request->source_label ?: 'Coffrac',
            'first_name' => $request->customer_first_name ?: 'Client',
            'last_name' => $request->customer_last_name ?: 'Coffrac',
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
            'service' => $this->matchingService([
                'service_type' => $request->service_type,
                'service_name' => $request->service_name,
            ]),
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

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('services.coffrac.api_token'))
            ->timeout((int) config('services.coffrac.timeout', 15))
            ->connectTimeout((int) config('services.coffrac.connect_timeout', 5))
            ->retry(2, 250, throw: false);
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
