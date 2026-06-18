<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CoffracAppointmentService
{
    public const SOURCE = 'coffrac';

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
     * @return array{appointments: Collection<int, array<string, mixed>>, status: array{state:string,label:string,detail:string,count:int}}
     */
    public function pendingWithStatus(int $limit = 15, bool $shuffle = false): array
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

        $query = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('status', ExternalAppointmentRequest::STATUS_PENDING)
            ->orderByDesc('remote_updated_at')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->limit($limit);

        $appointments = $query->get()
            ->map(fn (ExternalAppointmentRequest $appointment): array => $this->appointmentFromStoredRequest($appointment))
            ->filter()
            ->values();

        if ($shuffle) {
            $appointments = $appointments->shuffle()->values();
        }

        return [
            'appointments' => $appointments,
            'status' => $this->statusFromLastSync($appointments->count()),
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
            ->first();

        return $storedRequest ? $this->appointmentFromStoredRequest($storedRequest) : null;
    }

    /**
     * Synchronise les demandes Coffrac locales depuis l'API distante.
     *
     * @return array{available: bool, message: string, count: int, pending_count: int, placed_count: int, problem_count: int}
     */
    public function sync(int $pageSize = 500): array
    {
        if (! $this->isConfigured()) {
            $message = 'COFFRAC_API_URL ou COFFRAC_API_TOKEN est absent.';
            $this->persistSyncState(ExternalApiSync::STATE_NOT_CONFIGURED, $message, ['appointments_count' => 0]);

            return [
                'available' => false,
                'message' => $message,
                'count' => 0,
                'pending_count' => 0,
                'placed_count' => 0,
                'problem_count' => 0,
            ];
        }

        $this->markSyncStarted();

        try {
            $remoteAppointments = $this->fetchRemoteAppointments('all', $pageSize);
        } catch (Throwable $exception) {
            report($exception);

            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Coffrac ne répond pas pour le moment.';
            $this->persistSyncState(ExternalApiSync::STATE_UNAVAILABLE, $message, [
                'appointments_count' => 0,
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

        $stored = $remoteAppointments
            ->map(fn (array $appointment): ?ExternalAppointmentRequest => $this->persistRemoteAppointment($appointment))
            ->filter()
            ->values();
        $remoteReferences = $stored->pluck('external_reference')->filter()->values();

        ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->when(
                $remoteReferences->isNotEmpty(),
                fn ($query) => $query->whereNotIn('external_reference', $remoteReferences->all()),
            )
            ->whereIn('status', [
                ExternalAppointmentRequest::STATUS_PENDING,
                ExternalAppointmentRequest::STATUS_PLACED,
                ExternalAppointmentRequest::STATUS_PROBLEM,
            ])
            ->update([
                'status' => ExternalAppointmentRequest::STATUS_ARCHIVED,
                'fetched_at' => now(),
            ]);

        $counts = [
            'pending_count' => $stored->where('status', ExternalAppointmentRequest::STATUS_PENDING)->count(),
            'placed_count' => $stored->where('status', ExternalAppointmentRequest::STATUS_PLACED)->count(),
            'problem_count' => $stored->where('status', ExternalAppointmentRequest::STATUS_PROBLEM)->count(),
        ];
        $message = sprintf(
            'Synchronisation Coffrac terminée: %d demande(s), %d placée(s), %d en problème.',
            $counts['pending_count'],
            $counts['placed_count'],
            $counts['problem_count'],
        );

        $this->persistSyncState(ExternalApiSync::STATE_AVAILABLE, $message, [
            'appointments_count' => $stored->count(),
            ...$counts,
        ]);

        return [
            'available' => true,
            'message' => $message,
            'count' => $stored->count(),
            ...$counts,
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

        $appointment->loadMissing('technician:id,email');

        $response = $this->request()->post($this->endpoint("appointments/{$externalReference}/placed"), [
            'technician_email' => $appointment->technician?->email,
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'duration_minutes' => $appointment->duration_minutes,
            'comment' => $appointment->comment,
            'techcalendar_appointment_id' => $appointment->id,
        ]);

        if ($response->failed()) {
            $payload = $response->json();

            throw new RuntimeException($this->responseError(is_array($payload) ? $payload : null, 'Impossible de basculer le RDV Coffrac en attente visite.'));
        }

        $storedRequest = ExternalAppointmentRequest::query()
            ->where('source', self::SOURCE)
            ->where('external_reference', $externalReference)
            ->first();

        $remotePayload = $response->json('data');

        if (is_array($remotePayload)) {
            $storedRequest = $this->persistRemoteAppointment($remotePayload) ?: $storedRequest;
        }

        $storedRequest?->update([
            'status' => ExternalAppointmentRequest::STATUS_PLACED,
            'appointment_id' => $appointment->id,
            'technician_email' => $appointment->technician?->email,
            'starts_at' => $appointment->starts_at,
            'duration_minutes' => $appointment->duration_minutes,
            'comment' => $appointment->comment,
            'fetched_at' => now(),
        ]);
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
    private function fetchRemoteAppointments(string $status, int $pageSize, ?string $externalReference = null): Collection
    {
        $appointments = collect();
        $offset = 0;
        $safePageSize = max(1, min(500, $pageSize));

        do {
            $response = $this->request()->get($this->endpoint('appointments'), array_filter([
                'status' => $status,
                'limit' => $safePageSize,
                'offset' => $externalReference ? null : $offset,
                'id' => $externalReference,
            ], fn ($value): bool => $value !== null && $value !== ''));

            if ($response->failed()) {
                $payload = $response->json();

                throw new RuntimeException($this->responseError(is_array($payload) ? $payload : null, 'Impossible de récupérer les RDV Coffrac.'));
            }

            $page = collect($response->json('data', []))
                ->filter(fn ($appointment): bool => is_array($appointment))
                ->values();

            $appointments = $appointments->merge($page);
            $offset += $safePageSize;
        } while (! $externalReference && $page->count() === $safePageSize);

        return $appointments->values();
    }

    private function persistRemoteAppointment(array $appointment): ?ExternalAppointmentRequest
    {
        $normalized = $this->normalizeRemoteAppointment($appointment);

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
                'customer_phone' => $request->phone ?: '',
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
    private function normalizeRemoteAppointment(array $appointment): ?array
    {
        $externalReference = (string) ($appointment['id'] ?? '');

        if ($externalReference === '') {
            return null;
        }

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
            'phone' => trim((string) ($appointment['phone'] ?? '')) ?: null,
            'address' => trim((string) ($appointment['address'] ?? '')) ?: null,
            'address_line' => trim((string) ($appointment['address_line'] ?? '')) ?: null,
            'postal_code' => trim((string) ($appointment['postal_code'] ?? '')) ?: null,
            'city' => trim((string) ($appointment['city'] ?? '')) ?: null,
            'department_code' => strtoupper(trim((string) ($appointment['department_code'] ?? ''))) ?: null,
            'latitude' => ($appointment['latitude'] ?? null) !== null ? (float) $appointment['latitude'] : null,
            'longitude' => ($appointment['longitude'] ?? null) !== null ? (float) $appointment['longitude'] : null,
            'technician_email' => trim((string) ($appointment['technician_email'] ?? '')) ?: null,
            'starts_at' => ! empty($appointment['starts_at']) ? Carbon::parse($appointment['starts_at']) : null,
            'duration_minutes' => ($appointment['duration_minutes'] ?? null) !== null ? (int) $appointment['duration_minutes'] : null,
            'comment' => trim((string) ($appointment['comment'] ?? '')) ?: null,
            'documents' => collect($appointment['documents'] ?? [])
                ->filter(fn ($document): bool => is_array($document))
                ->values()
                ->all(),
            'remote_updated_at' => ! empty($appointment['updated_at']) ? Carbon::parse($appointment['updated_at']) : null,
        ];
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
        if ($request->latitude === null || $request->longitude === null) {
            return [];
        }

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
            'latitude' => (float) $request->latitude,
            'longitude' => (float) $request->longitude,
            'preferred_starts_at' => null,
            'is_manual' => false,
            'is_lot' => false,
            'documents' => $request->documents ?? [],
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

        $service = Service::query()
            ->where('type', $type)
            ->where('name', $name)
            ->first(['id', 'type', 'name', 'average_duration_minutes']);

        return $service ? [
            'id' => $service->id,
            'type' => $service->type,
            'name' => $service->name,
            'average_duration_minutes' => $service->average_duration_minutes,
        ] : null;
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
     * @return array{state:string,label:string,detail:string,count:int}
     */
    private function statusFromLastSync(int $count): array
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

        $state = match ($sync->state) {
            ExternalApiSync::STATE_AVAILABLE => 'available',
            ExternalApiSync::STATE_NOT_CONFIGURED => 'not_configured',
            default => 'unavailable',
        };
        $label = match ($sync->state) {
            ExternalApiSync::STATE_AVAILABLE => 'API Coffrac disponible',
            ExternalApiSync::STATE_NOT_CONFIGURED => 'API Coffrac non configurée',
            default => 'API Coffrac indisponible',
        };
        $lastSync = $sync->last_successful_at?->format('d/m/Y H:i');
        $detail = trim(($sync->message ?: 'Statut Coffrac inconnu.').($lastSync ? " Dernière synchro: {$lastSync}." : ''));

        return $this->availabilityStatus($state, $label, $detail, $count);
    }

    private function markSyncStarted(): void
    {
        ExternalApiSync::query()->updateOrCreate(
            ['source' => self::SOURCE],
            [
                'state' => ExternalApiSync::STATE_UNAVAILABLE,
                'message' => 'Synchronisation Coffrac en cours...',
                'last_started_at' => now(),
            ],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function persistSyncState(string $state, string $message, array $metadata): ExternalApiSync
    {
        $sync = ExternalApiSync::query()->firstOrNew(['source' => self::SOURCE]);
        $sync->fill([
            'state' => $state,
            'message' => $message,
            'last_finished_at' => now(),
            'metadata' => $metadata,
        ]);

        if ($state === ExternalApiSync::STATE_AVAILABLE) {
            $sync->last_successful_at = now();
        }

        $sync->save();

        return $sync;
    }

    /**
     * @return array{state:string,label:string,detail:string,count:int}
     */
    private function availabilityStatus(string $state, string $label, string $detail, int $count): array
    {
        return [
            'state' => $state,
            'label' => $label,
            'detail' => $detail,
            'count' => $count,
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
