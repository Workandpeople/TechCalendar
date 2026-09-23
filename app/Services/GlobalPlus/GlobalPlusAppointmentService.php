<?php

namespace App\Services\GlobalPlus;

use App\Jobs\AssignGlobalPlusTechnicianJob;
use App\Jobs\CreateGlobalPlusDemandJob;
use App\Models\ExternalDelegataire;
use App\Models\LotAppointment;
use App\Models\LotAppointmentDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GlobalPlusAppointmentService
{
    public const SOURCE = 'global_plus';

    public const STATUS_CREATED = 'created';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CREATION_PENDING = 'creation_pending';

    public const STATUS_CREATION_UNCERTAIN = 'creation_uncertain';

    public const STATUS_APPOINTMENT_PENDING = 'appointment_pending';

    public const STATUS_APPOINTMENT_FAILED = 'appointment_failed';

    public const STATUS_DOCUMENTS_SYNCED = 'documents_synced';

    public const STATUS_DOCUMENTS_FAILED = 'documents_failed';

    private const ADDRESS_NAME_MAX_LENGTH = 50;

    private const ADDRESS_COMPANY_MAX_LENGTH = 255;

    private const ADDRESS_LINE_MAX_LENGTH = 200;

    private const POSTAL_CODE_MAX_LENGTH = 10;

    private const CITY_MAX_LENGTH = 50;

    private const SIREN_MAX_LENGTH = 20;

    private const TITLE_MAX_LENGTH = 50;

    private const SUB_TITLE_MAX_LENGTH = 255;

    // Global+ radio buttons use "M.", despite the "Mr" default in their Swagger.
    private const DEFAULT_CIVILITY = 'M.';

    public function __construct(private readonly GlobalPlusClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public static function isProcessing(LotAppointment $appointment): bool
    {
        return in_array($appointment->global_plus_status, [self::STATUS_CREATION_PENDING, self::STATUS_APPOINTMENT_PENDING], true);
    }

    public function queueDemand(LotAppointment $appointment, array $payload, ?User $actor = null): LotAppointment
    {
        $lock = Cache::lock('global_plus:lot_appointment:'.$appointment->id, 300);
        if (! $lock->get()) {
            throw new RuntimeException('Un envoi Global+ est déjà en cours pour ce dossier.');
        }

        try {
            $appointment->refresh();
            if (self::isProcessing($appointment)) {
                return $appointment;
            }
            if ($appointment->global_plus_status === self::STATUS_CREATION_UNCERTAIN) {
                throw new RuntimeException('La réponse de création Global+ est incertaine. Vérifie le dossier avec Global+ avant tout nouvel envoi pour éviter un doublon.');
            }
            $retryAssignment = filled($appointment->global_plus_demand_id)
                && $appointment->global_plus_status === self::STATUS_APPOINTMENT_FAILED;
            if (! $retryAssignment) {
                $this->assertCanCreateDemand($appointment);
                $appointment->loadMissing(['lot.service', 'lot.coffracServiceAlias', 'service', 'appointment.technician', 'appointment.service']);
                // Validate references now; files are read by the worker, not embedded in the queue payload.
                $this->demandPayload($appointment, [...$payload, 'send_documents' => false]);
            }
            $controllerId = $this->controllerId($payload);
            $operationId = (string) Str::uuid();

            try {
                DB::transaction(function () use ($appointment, $payload, $actor, $retryAssignment, $controllerId, $operationId): void {
                    $appointment->update([
                        'global_plus_status' => $retryAssignment ? self::STATUS_APPOINTMENT_PENDING : self::STATUS_CREATION_PENDING,
                        'global_plus_error_message' => null,
                        'global_plus_payload' => [
                            ...($appointment->global_plus_payload ?? []),
                            'workflow' => ['id' => $operationId, 'queued_at' => now()->toIso8601String()],
                            'appointment_assignment' => ['controller_id' => $controllerId, 'stage' => 'queued'],
                        ],
                    ]);
                    $connection = (string) config('queue.default', 'database');
                    if (in_array($connection, ['sync', 'deferred', 'background', 'null'], true)) {
                        $connection = 'database';
                    }
                    $assignment = (new AssignGlobalPlusTechnicianJob($appointment->id, $operationId, $controllerId))->delay(10)->afterCommit();
                    $jobs = $retryAssignment ? [$assignment] : [
                        (new CreateGlobalPlusDemandJob($appointment->id, $operationId, $payload, $actor?->id))->afterCommit(),
                        $assignment,
                    ];
                    Bus::chain($jobs)->onConnection($connection)->dispatch();
                });
                Log::channel('global_plus')->info('Global+ : traitement mis en file.', [
                    'workflow_id' => $operationId, 'lot_appointment_id' => $appointment->id,
                    'demand_id' => $appointment->global_plus_demand_id, 'assignment_only' => $retryAssignment,
                ]);
            } catch (Throwable $exception) {
                $this->failWorkflow($appointment->id, $operationId, $exception);
                throw new RuntimeException('Mise en file Global+ impossible. Vérifie le service de queue puis réessaie.', 0, $exception);
            }

            return $appointment->refresh();
        } finally {
            $lock->release();
        }
    }

    public function failWorkflow(int $appointmentId, string $operationId, Throwable $exception): void
    {
        DB::transaction(function () use ($appointmentId, $operationId, $exception): void {
            $appointment = LotAppointment::query()->lockForUpdate()->find($appointmentId);
            if (! $appointment || data_get($appointment->global_plus_payload, 'workflow.id') !== $operationId
                || ! self::isProcessing($appointment)) {
                return;
            }
            $uncertain = ! filled($appointment->global_plus_demand_id)
                && filled(data_get($appointment->global_plus_payload, 'workflow.creation_attempted_at'));
            $message = $appointment->global_plus_error_message ?: ($uncertain
                ? 'Réponse de création Global+ incertaine. Vérifie avec Global+ avant de renvoyer le dossier pour éviter un doublon.'
                : $exception->getMessage());
            if (filled($appointment->global_plus_demand_id)) {
                $message = 'Affectation arrêtée après les tentatives automatiques. '.$message.' Diagnostic : '.$operationId.'.';
            }
            $appointment->update([
                'global_plus_status' => filled($appointment->global_plus_demand_id) ? self::STATUS_APPOINTMENT_FAILED
                    : ($uncertain ? self::STATUS_CREATION_UNCERTAIN : self::STATUS_FAILED),
                'global_plus_error_message' => $message,
            ]);
            $context = [
                'workflow_id' => $operationId, 'lot_appointment_id' => $appointmentId,
                'demand_id' => $appointment->global_plus_demand_id, 'status' => $appointment->global_plus_status,
                'stage' => data_get($appointment->global_plus_payload, 'appointment_assignment.stage'),
                'attempt' => data_get($appointment->global_plus_payload, 'appointment_assignment.attempt'),
                'exception_class' => $exception::class,
            ];
            Log::error('Global+ : traitement arrêté.', $context);
            Log::channel('global_plus')->error('Global+ : traitement arrêté.', $context);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function referenceDataFor(LotAppointment $lotAppointment): array
    {
        $lotAppointment->loadMissing([
            'lot.service',
            'lot.coffracServiceAlias',
            'service',
            'appointment.technician',
            'appointment.service',
        ]);

        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'message' => 'API Global+ non configurée.',
                'installers' => [],
                'clients' => [],
                'controllers' => [],
                'intervention_versions' => [],
                'suggested_installer_address_id' => null,
                'matching_client_address_ids' => [],
                'suggested_client_address_id' => null,
                'suggested_controller_id' => null,
                'suggested_version_formulaire_id' => null,
            ];
        }

        $installers = $this->client->installers();
        $controllers = $this->client->controllers();
        $versions = $this->client->activeFormVersions();
        $clients = $this->client->clients();
        $matchingClientIds = $this->matchingClientAddressIds($lotAppointment, $clients);

        return [
            'configured' => true,
            'installers' => $this->publicReferences($installers),
            'clients' => $this->publicReferences($clients),
            'delegataire' => $lotAppointment->lot?->delegataire,
            'matching_client_address_ids' => $matchingClientIds,
            'suggested_client_address_id' => count($matchingClientIds) === 1 ? $matchingClientIds[0] : null,
            'existing_client_address_id' => data_get($lotAppointment->global_plus_payload, 'last_request.client.id'),
            'controllers' => $this->publicReferences($controllers),
            'intervention_versions' => $this->publicReferences($versions),
            'suggested_installer_address_id' => $this->suggestInstallerAddressId($lotAppointment, $installers),
            'suggested_controller_id' => data_get($lotAppointment->global_plus_payload, 'appointment_assignment.controller_id') ?: $this->suggestControllerId($lotAppointment, $controllers),
            'suggested_version_formulaire_id' => $this->suggestVersionFormulaireId($lotAppointment, $versions),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDemandFromLotAppointment(LotAppointment $lotAppointment, array $payload, ?User $actor = null): LotAppointment
    {
        // Queue middleware serializes creation and assignment for this lot dossier.
        $lotAppointment->refresh();
        $this->assertCanCreateDemand($lotAppointment);

        $lotAppointment->loadMissing([
            'lot.service',
            'lot.coffracServiceAlias',
            'service',
            'appointment.technician',
            'appointment.service',
            'documents',
        ]);

        $demandPayload = $this->demandPayload($lotAppointment, $payload);

        if (filled(data_get($lotAppointment->global_plus_payload, 'workflow.creation_attempted_at'))) {
            throw new RuntimeException('Un envoi de création a déjà été tenté. Vérifie le résultat dans Global+ avant de recommencer.');
        }
        $lotAppointment->update(['global_plus_payload' => array_replace_recursive($lotAppointment->global_plus_payload ?? [], [
            'workflow' => ['creation_attempted_at' => now()->toIso8601String()],
        ])]);
        Log::channel('global_plus')->info('Global+ : création démarrée.', [
            'workflow_id' => data_get($lotAppointment->global_plus_payload, 'workflow.id'),
            'lot_appointment_id' => $lotAppointment->id,
        ]);

        try {
            $demandId = $this->client->createDemand($demandPayload);
        } catch (Throwable $exception) {
            $lotAppointment->update([
                'global_plus_status' => $exception instanceof GlobalPlusApiException && in_array($exception->statusCode(), [400, 401, 403, 404, 422], true)
                    ? self::STATUS_FAILED : self::STATUS_CREATION_UNCERTAIN,
                'global_plus_error_message' => $exception->getMessage(),
                'global_plus_payload' => [
                    ...($lotAppointment->global_plus_payload ?? []),
                    'last_request' => $this->safeDemandPayload($demandPayload),
                    'failed_at' => now()->toIso8601String(),
                    'actor_id' => $actor?->id,
                ],
            ]);

            Log::warning('Création Global+ depuis un dossier de lot refusée.', [
                'lot_appointment_id' => $lotAppointment->id,
                'lot_id' => $lotAppointment->lot_id,
                'appointment_id' => $lotAppointment->appointment_id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $documentsWereSent = array_key_exists('files', $demandPayload);
        $now = now();

        $lotAppointment->update([
            'added_to_global_plus' => true,
            'global_plus_demand_id' => $demandId,
            'global_plus_status' => self::STATUS_APPOINTMENT_PENDING,
            'global_plus_payload' => [
                ...($lotAppointment->global_plus_payload ?? []),
                'last_request' => $this->safeDemandPayload($demandPayload),
                'created_at' => $now->toIso8601String(),
                'actor_id' => $actor?->id,
            ],
            'global_plus_created_at' => $now,
            'global_plus_synced_at' => $now,
            'global_plus_error_message' => null,
        ]);
        Log::channel('global_plus')->info('Global+ : dossier créé, affectation en attente.', [
            'workflow_id' => data_get($lotAppointment->global_plus_payload, 'workflow.id'),
            'lot_appointment_id' => $lotAppointment->id, 'demand_id' => $demandId,
        ]);

        if ($documentsWereSent) {
            $this->markDocumentsSynced($lotAppointment, $demandId, 'sent_in_creation');
        }

        return $lotAppointment->fresh([
            'lot.service',
            'lot.coffracServiceAlias',
            'service',
            'appointment.technician.departments',
            'appointment.service',
            'documents.uploader',
        ]);
    }

    public function syncAppointment(LotAppointment $lotAppointment, int $controllerId, int $attempt = 1, int $maxAttempts = 5): void
    {
        $lotAppointment->loadMissing('appointment');
        $stage = 'resolve_intervention';
        $patchAcceptedAt = null;
        $diagnostic = [
            'workflow_id' => data_get($lotAppointment->global_plus_payload, 'workflow.id'),
            'lot_appointment_id' => $lotAppointment->id, 'demand_id' => $lotAppointment->global_plus_demand_id,
            'controller_id' => $controllerId, 'attempt' => $attempt, 'max_attempts' => $maxAttempts,
        ];
        Log::channel('global_plus')->info('Global+ : tentative d’affectation démarrée.', $diagnostic);
        try {
            $interventionId = $this->resolveIntervention($lotAppointment, $diagnostic);
            $stage = 'verify_intervention';
            $remote = $this->client->intervention($interventionId);
            $diagnostic['intervention_demand_id'] = filter_var($remote['idDemande'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            if ((string) ($remote['idDemande'] ?? '') !== (string) $lotAppointment->global_plus_demand_id) {
                throw new RuntimeException('Le rattachement de l’intervention au dossier Global+ n’est pas confirmé. Aucun changement n’a été envoyé.');
            }
            // Verify ownership before any PATCH, including on a resumed assignment.
            $lotAppointment->update(['global_plus_intervention_id' => $interventionId]);
            $stage = 'assign_technician';
            $startsAt = $lotAppointment->appointment?->starts_at?->format('Y-m-d\TH:i:s');
            $endsAt = $lotAppointment->appointment?->ends_at?->format('Y-m-d\TH:i:s');
            if (! $startsAt || ! $endsAt) {
                throw new RuntimeException('Le rendez-vous local doit avoir une date et une heure de fin.');
            }
            $this->client->patchIntervention($interventionId, [
                ['op' => 'replace', 'path' => '/idControleur', 'value' => $controllerId],
                ['op' => 'replace', 'path' => '/dateIntervention', 'value' => $startsAt],
                ['op' => 'replace', 'path' => '/dateInterventionEnd', 'value' => $endsAt],
            ]);
            $patchAcceptedAt = now()->toIso8601String();
            $stage = 'verify_assignment';
            $remote = $this->client->intervention($interventionId);
            $diagnostic['verification'] = [
                'demand_matches' => (string) ($remote['idDemande'] ?? '') === (string) $lotAppointment->global_plus_demand_id,
                'controller_matches' => (int) ($remote['idControleur'] ?? 0) === $controllerId,
                'starts_at_matches' => $this->sameAppointmentTime($remote['dateIntervention'] ?? null, $startsAt),
                'ends_at_matches' => $this->sameAppointmentTime($remote['dateInterventionEnd'] ?? null, $endsAt),
            ];
            if (in_array(false, $diagnostic['verification'], true)) {
                throw new GlobalPlusAssignmentPendingException('Global+ n’a pas encore confirmé le technicien et les horaires sélectionnés.');
            }
            $lotAppointment->refresh();
            $lotAppointment->update([
                'global_plus_status' => self::STATUS_CREATED,
                'global_plus_error_message' => null,
                'global_plus_synced_at' => now(),
                'global_plus_payload' => [
                    ...($lotAppointment->global_plus_payload ?? []),
                    'appointment_assignment' => [
                        ...$diagnostic,
                        'controller_id' => $controllerId, 'intervention_id' => $interventionId,
                        'stage' => 'confirmed', 'patch_accepted_at' => $patchAcceptedAt, 'confirmed_at' => now()->toIso8601String(),
                    ],
                ],
            ]);
            Log::channel('global_plus')->info('Global+ : affectation confirmée.', $diagnostic + ['intervention_id' => $interventionId]);
        } catch (Throwable $exception) {
            $context = $exception instanceof GlobalPlusApiException ? $exception->requestContext() : [];
            $message = match ($stage) {
                'resolve_intervention' => 'Affectation non envoyée : impossible de retrouver l’intervention du dossier créé. ',
                'verify_intervention' => 'Affectation non envoyée : rattachement de l’intervention non vérifié. ',
                'assign_technician' => 'Affectation du technicien non confirmée. ',
                'verify_assignment' => 'Affectation envoyée, mais vérification impossible ou non conforme. ',
            }.$exception->getMessage();
            $lotAppointment->refresh();
            $lotAppointment->update([
                'global_plus_status' => self::assignmentCanBeRetried($exception) ? self::STATUS_APPOINTMENT_PENDING : self::STATUS_APPOINTMENT_FAILED,
                'global_plus_error_message' => $message,
                'global_plus_payload' => [
                    ...($lotAppointment->global_plus_payload ?? []),
                    'appointment_assignment' => [
                        ...$diagnostic,
                        'controller_id' => $controllerId,
                        'intervention_id' => $lotAppointment->global_plus_intervention_id,
                        'stage' => $stage, 'patch_accepted_at' => $patchAcceptedAt,
                        'failed_at' => now()->toIso8601String(), ...$context,
                    ],
                ],
            ]);
            $logContext = [
                ...$diagnostic,
                'intervention_id' => $lotAppointment->global_plus_intervention_id,
                'stage' => $stage, 'patch_accepted_at' => $patchAcceptedAt, ...$context,
                'retryable' => self::assignmentCanBeRetried($exception), 'exception_class' => $exception::class,
            ];
            Log::warning('Global+ : dossier créé, affectation du RDV incomplète.', $logContext);
            Log::channel('global_plus')->warning('Global+ : dossier créé, affectation du RDV incomplète.', $logContext);
            throw $exception;
        }
    }

    private function resolveIntervention(LotAppointment $appointment, array &$diagnostic): string
    {
        $storedId = trim((string) $appointment->global_plus_intervention_id);
        if ($storedId !== '') {
            $diagnostic['resolution_source'] = 'stored_intervention';

            return $storedId;
        }

        $demandId = (string) $appointment->global_plus_demand_id;
        $demand = $this->client->demand($demandId);
        $embedded = $demand['interventions'] ?? null;
        $diagnostic['embedded_interventions_state'] = ! array_key_exists('interventions', $demand) ? 'absent'
            : ($embedded === null ? 'null' : (is_array($embedded) && array_is_list($embedded) ? 'list' : 'invalid'));
        $candidates = $this->interventionCandidates(is_array($embedded) && array_is_list($embedded) ? $embedded : [], $demandId, $diagnostic, 'embedded');
        $diagnostic['resolution_source'] = 'demand';
        if ($candidates === []) {
            // The Demande relation may not be loaded; use the documented, demand-scoped list.
            $diagnostic['resolution_source'] = 'intervention_list';
            $listed = $this->client->demandInterventions($demandId);
            $candidates = $this->interventionCandidates($listed, $demandId, $diagnostic, 'list');
            if ($candidates === []) {
                if ($listed !== []) {
                    throw new RuntimeException('La liste Global+ contient des interventions, mais aucune ne peut être reliée à ce dossier. Vérifier le diagnostic avec Global+.');
                }
                throw new GlobalPlusAssignmentPendingException('La liste Global+ ne renvoie aucune intervention pour le dossier '.$demandId.' (GET /api/Intervention/ListInterventions?demandeId='.$demandId.').');
            }
        }
        if (count($candidates) !== 1) {
            throw new RuntimeException('Plusieurs interventions Global+ correspondent au dossier. L’affectation nécessite une vérification manuelle.');
        }
        $diagnostic['resolved_intervention_id'] = $candidates[0];

        return $candidates[0];
    }

    private function interventionCandidates(array $items, string $demandId, array &$diagnostic, string $source): array
    {
        $candidates = [];
        $summary = ['count' => count($items), 'invalid' => 0, 'other_demand' => 0, 'missing_demand_id' => 0, 'candidates' => []];
        foreach ($items as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id) || (int) $id <= 0) {
                $summary['invalid']++;

                continue;
            }
            $parentId = $item['idDemande'] ?? null;
            if ($parentId === null || $parentId === '') {
                $summary['missing_demand_id']++;
            } elseif ((! is_int($parentId) && ! is_string($parentId)) || (string) $parentId !== $demandId) {
                $summary['other_demand']++;

                continue;
            }
            $candidates[] = (string) $id;
        }
        $candidates = array_values(array_unique($candidates));
        $summary['candidates'] = array_slice($candidates, 0, 10);
        $summary['candidate_count'] = count($candidates);
        // Only known schema field names and types, never arbitrary fields or their values.
        $summary['item_shapes'] = array_map(static function ($item): array {
            if (! is_array($item)) {
                return ['type' => get_debug_type($item)];
            }

            return array_map(get_debug_type(...), array_intersect_key($item, array_flip(['id', 'idIntervention', 'interventionId', 'idDemande', 'demandeId'])));
        }, array_slice($items, 0, 3));
        $diagnostic[$source.'_interventions'] = $summary;

        return $candidates;
    }

    public static function assignmentCanBeRetried(Throwable $exception): bool
    {
        return $exception instanceof GlobalPlusAssignmentPendingException
            || $exception instanceof ConnectionException
            || ($exception instanceof GlobalPlusApiException
                && (in_array($exception->statusCode(), [404, 408, 429], true) || $exception->statusCode() >= 500));
    }

    private function sameAppointmentTime(?string $remote, string $expected): bool
    {
        if (! $remote) {
            return false;
        }

        return CarbonImmutable::parse($remote, config('app.timezone'))
            ->equalTo(CarbonImmutable::parse($expected, config('app.timezone')));
    }

    public function syncDocuments(LotAppointment $lotAppointment): LotAppointment
    {
        $lotAppointment->loadMissing(['documents', 'lot']);
        $demandId = trim((string) $lotAppointment->global_plus_demand_id);

        if ($demandId === '') {
            throw new RuntimeException('Ce dossier n’a pas encore été créé dans Global+.');
        }

        $files = $this->documentsPayload($lotAppointment);

        return $this->withDemandFilesLock($demandId, function () use ($lotAppointment, $demandId, $files): LotAppointment {
            try {
                $response = $this->client->replaceDemandFiles($demandId, $files);
            } catch (Throwable $exception) {
                $lotAppointment->refresh();
                $assignmentPending = in_array($lotAppointment->global_plus_status, [self::STATUS_APPOINTMENT_FAILED, self::STATUS_APPOINTMENT_PENDING], true);
                $lotAppointment->update([
                    'global_plus_status' => $assignmentPending ? $lotAppointment->global_plus_status : self::STATUS_DOCUMENTS_FAILED,
                    'global_plus_error_message' => $assignmentPending ? $lotAppointment->global_plus_error_message : $exception->getMessage(),
                ]);

                $lotAppointment->documents()->update([
                    'global_plus_error_message' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $lotAppointment->refresh();
            $assignmentPending = in_array($lotAppointment->global_plus_status, [self::STATUS_APPOINTMENT_FAILED, self::STATUS_APPOINTMENT_PENDING], true);
            $lotAppointment->update([
                'global_plus_status' => $assignmentPending ? $lotAppointment->global_plus_status : self::STATUS_DOCUMENTS_SYNCED,
                'global_plus_synced_at' => now(),
                'global_plus_error_message' => $assignmentPending ? $lotAppointment->global_plus_error_message : null,
                'global_plus_payload' => [
                    ...(is_array($lotAppointment->global_plus_payload) ? $lotAppointment->global_plus_payload : []),
                    'last_documents_sync' => [
                        'synced_at' => now()->toIso8601String(),
                        'files_count' => count($files),
                        'response' => $this->safeResponsePayload($response),
                    ],
                ],
            ]);

            $this->markDocumentsSynced($lotAppointment, $demandId, 'replace_files');

            return $lotAppointment->fresh(['documents.uploader']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function demandPayload(LotAppointment $lotAppointment, array $payload): array
    {
        $appointment = $lotAppointment->appointment;

        if (! $appointment) {
            throw new RuntimeException('Le dossier doit d’abord être placé physiquement dans TechCalendar.');
        }

        $versionFormulaire = $this->versionFormulaireDto((int) ($payload['version_formulaire_id'] ?? 0));
        $installer = $this->installerAddress($lotAppointment, $payload);
        $this->controllerId($payload);
        $title = $this->limit(trim((string) ($payload['title'] ?? '')) ?: $this->defaultTitle($lotAppointment), self::TITLE_MAX_LENGTH);
        $subTitle = $this->limit(trim((string) ($payload['sub_title'] ?? '')) ?: $this->defaultSubTitle($lotAppointment), self::SUB_TITLE_MAX_LENGTH);
        $sendDocuments = (bool) ($payload['send_documents'] ?? true);
        $files = $sendDocuments ? $this->documentsPayload($lotAppointment) : [];
        $demandPayload = [
            'idBureauInspection' => (int) config('services.global_plus.bureau_id'),
            'dateCreation' => now()->format('Y-m-d\TH:i:s'),
            'startDate' => $appointment->starts_at?->format('Y-m-d\TH:i:s'),
            'endDate' => $appointment->ends_at?->format('Y-m-d\TH:i:s'),
            'dateIntervention' => $appointment->starts_at?->format('Y-m-d\TH:i:s'),
            'title' => $title,
            'subTitle' => $subTitle,
            'typeIntervention' => [
                $versionFormulaire,
            ],
            'client' => $this->clientAddress($lotAppointment, $payload),
            'lieuInspection' => $this->inspectionAddress($lotAppointment, $payload),
            'beneficiaire' => $this->beneficiaryAddress($lotAppointment),
            'entreprise' => $installer,
            'facturation' => false,
        ];

        if ($files !== []) {
            $demandPayload['files'] = $files;
        }

        return array_filter($demandPayload, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientAddress(LotAppointment $lotAppointment, array $payload): array
    {
        $clients = $this->client->clients();
        $clientId = (int) ($payload['client_address_id'] ?? 0);
        $client = collect($clients)->firstWhere('address_id', $clientId);
        if (! $client) {
            throw new RuntimeException('Sélectionne le délégataire dans la liste des clients Global+.');
        }
        $matchingIds = $this->matchingClientAddressIds($lotAppointment, $clients);
        if ($matchingIds !== [] && ! in_array($clientId, $matchingIds, true)) {
            throw new RuntimeException('Le client Global+ sélectionné ne correspond pas au délégataire du lot : '.$lotAppointment->lot?->delegataire.'.');
        }
        if ($matchingIds === [] && ! ($payload['client_delegataire_confirmed'] ?? false)) {
            throw new RuntimeException('Confirme que le client Global+ sélectionné correspond bien au délégataire du lot, et non au bénéficiaire ou à l’installateur.');
        }
        $address = $client['payload'];

        return [
            ...array_intersect_key($address, array_flip(['id', 'nom', 'prenom', 'raisonSociale', 'adresse', 'codePostal', 'ville', 'email', 'phone', 'siren', 'batiment'])),
            'idTypeAdresse' => 1,
            'civilite' => self::DEFAULT_CIVILITY,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function inspectionAddress(LotAppointment $lotAppointment, array $payload): array
    {
        return $this->addressDto(2, $lotAppointment, [
            'raisonSociale' => $lotAppointment->site_name ?: $this->customerCompanyName($lotAppointment),
            'precariousness' => $payload['precariousness'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function beneficiaryAddress(LotAppointment $lotAppointment): array
    {
        return $this->addressDto(3, $lotAppointment, [
            'raisonSociale' => $this->customerCompanyName($lotAppointment),
            'adresse' => $lotAppointment->beneficiary_address ?: $lotAppointment->address,
            'codePostal' => $lotAppointment->beneficiary_address ? $lotAppointment->beneficiary_postal_code : $lotAppointment->postal_code,
            'ville' => $lotAppointment->beneficiary_address ? $lotAppointment->beneficiary_city : $lotAppointment->city,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function installerAddress(LotAppointment $lotAppointment, array $payload): array
    {
        $installerAddressId = (int) ($payload['installer_address_id'] ?? 0);

        if ($installerAddressId > 0) {
            $installer = collect($this->client->installers())
                ->first(fn (array $installer): bool => (int) $installer['address_id'] === $installerAddressId);

            if (! $installer) {
                throw new RuntimeException('L’installateur Global+ sélectionné est introuvable. Recharge la liste puis réessaie.');
            }

            if ((bool) ($installer['blocked'] ?? false)) {
                throw new RuntimeException('Cet installateur est bloqué côté Global+ et ne peut pas être utilisé.');
            }

            return array_filter([
                'id' => (int) $installer['address_id'],
                'idTypeAdresse' => 4,
                'civilite' => self::DEFAULT_CIVILITY,
                'raisonSociale' => $this->limit($installer['name'] ?? $installer['label'] ?? null, self::ADDRESS_COMPANY_MAX_LENGTH),
                'adresse' => $this->limit($installer['address'] ?? null, self::ADDRESS_LINE_MAX_LENGTH),
                'codePostal' => $this->limit($installer['postal_code'] ?? null, self::POSTAL_CODE_MAX_LENGTH),
                'ville' => $this->limit($installer['city'] ?? null, self::CITY_MAX_LENGTH),
                'phone' => $this->nullableString($installer['phone'] ?? null),
                'siren' => $this->limit($installer['siren'] ?? null, self::SIREN_MAX_LENGTH),
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $name = trim((string) ($payload['installer_name'] ?? '')) ?: $lotAppointment->installer_name;

        if (! filled($name)) {
            throw new RuntimeException('Renseigne ou sélectionne l’installateur avant de créer le dossier Global+.');
        }

        return array_filter([
            'id' => 0,
            'idTypeAdresse' => 4,
            'civilite' => self::DEFAULT_CIVILITY,
            'raisonSociale' => $this->limit($name, self::ADDRESS_COMPANY_MAX_LENGTH),
            'adresse' => $this->limit($payload['installer_address'] ?? null, self::ADDRESS_LINE_MAX_LENGTH),
            'codePostal' => $this->limit($payload['installer_postal_code'] ?? null, self::POSTAL_CODE_MAX_LENGTH),
            'ville' => $this->limit($payload['installer_city'] ?? null, self::CITY_MAX_LENGTH),
            'phone' => $this->nullableString($payload['installer_phone'] ?? null),
            'siren' => $this->limit($payload['installer_siren'] ?? $lotAppointment->installer_siren, self::SIREN_MAX_LENGTH),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function versionFormulaireDto(int $versionFormulaireId): array
    {
        if ($versionFormulaireId <= 0) {
            throw new RuntimeException('Choisis une prestation Global+ avant de créer le dossier.');
        }

        $version = collect($this->client->activeFormVersions())
            ->first(fn (array $version): bool => (int) $version['version_formulaire_id'] === $versionFormulaireId);

        if (! $version) {
            throw new RuntimeException('La prestation Global+ sélectionnée est introuvable. Recharge la liste puis réessaie.');
        }

        $payload = is_array($version['payload'] ?? null) ? $version['payload'] : [];

        return collect([
            'versionFormulaireId',
            'codeRapport',
            'id',
            'idTypeIntervention',
            'idTypeInterventionGroupe',
            'libelleTypeInterventionGroupe',
            'numVersion',
            'libelle',
            'dateModification',
            'dateMiseEnService',
            'templateDoc',
            'templateSynthesisPDF',
            'templateSynthesisWORD',
            'actif',
            'hasStepsJSON',
            'enablePlanning',
            'versionPDF',
            'createdBy',
        ])
            ->mapWithKeys(fn (string $key): array => [$key => $payload[$key] ?? null])
            ->reject(fn (mixed $value): bool => $value === null)
            ->put('versionFormulaireId', $versionFormulaireId)
            ->all();
    }

    private function controllerId(array $payload): int
    {
        $controllerId = (int) ($payload['controller_id'] ?? 0);

        if ($controllerId <= 0) {
            throw new RuntimeException('Choisis le technicien Global+ avant de créer le dossier.');
        }

        $controller = collect($this->client->controllers())
            ->first(fn (array $controller): bool => (int) $controller['id'] === $controllerId);

        if (! $controller) {
            throw new RuntimeException('Le technicien Global+ sélectionné est introuvable. Recharge la liste puis réessaie.');
        }

        if (($controller['active'] ?? true) === false) {
            throw new RuntimeException('Le technicien Global+ sélectionné est inactif.');
        }

        return $controllerId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function addressDto(int $addressType, LotAppointment $lotAppointment, array $overrides = []): array
    {
        [$firstName, $lastName] = $this->splitCustomerName($lotAppointment);

        return array_filter([
            'id' => (int) ($overrides['id'] ?? 0),
            'idTypeAdresse' => $addressType,
            'civilite' => self::DEFAULT_CIVILITY,
            'nom' => $this->limit($overrides['nom'] ?? $lastName, self::ADDRESS_NAME_MAX_LENGTH),
            'prenom' => $this->limit($overrides['prenom'] ?? $firstName, self::ADDRESS_NAME_MAX_LENGTH),
            'raisonSociale' => $this->limit($overrides['raisonSociale'] ?? $this->customerCompanyName($lotAppointment), self::ADDRESS_COMPANY_MAX_LENGTH),
            'adresse' => $this->limit($overrides['adresse'] ?? $lotAppointment->address, self::ADDRESS_LINE_MAX_LENGTH),
            'codePostal' => $this->limit(array_key_exists('codePostal', $overrides) ? $overrides['codePostal'] : $lotAppointment->postal_code, self::POSTAL_CODE_MAX_LENGTH),
            'ville' => $this->limit(array_key_exists('ville', $overrides) ? $overrides['ville'] : $lotAppointment->city, self::CITY_MAX_LENGTH),
            'phone' => $this->nullableString($overrides['phone'] ?? $lotAppointment->customer_phone),
            'email' => $this->nullableString($lotAppointment->customer_email),
            'batiment' => $this->limit($lotAppointment->site_name, 50),
            'precariousness' => $overrides['precariousness'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<int, array{fileName:string,fileContent:string,category:string}>
     */
    private function documentsPayload(LotAppointment $lotAppointment): array
    {
        $lotAppointment->loadMissing('documents');

        return $lotAppointment->documents
            ->map(function (LotAppointmentDocument $document): ?array {
                $disk = Storage::disk($document->disk);

                if (! $disk->exists($document->path)) {
                    throw new RuntimeException(sprintf('Le fichier local du document "%s" est introuvable.', $document->name));
                }

                return [
                    'fileName' => $this->documentFileName($document),
                    'fileContent' => base64_encode($disk->get($document->path)),
                    'category' => $this->documentCategory($document),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function documentFileName(LotAppointmentDocument $document): string
    {
        $name = trim((string) ($document->name ?: $document->original_name ?: 'document'));
        $extension = pathinfo($document->original_name ?: $document->path, PATHINFO_EXTENSION);

        if ($extension !== '' && pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= '.'.$extension;
        }

        return Str::limit($name, 180, '');
    }

    private function documentCategory(LotAppointmentDocument $document): string
    {
        $mime = (string) $document->mime;

        if (str_starts_with($mime, 'image/')) {
            return 'Photos';
        }

        return 'Client';
    }

    private function markDocumentsSynced(LotAppointment $lotAppointment, string $demandId, string $mode): void
    {
        $lotAppointment->loadMissing('documents');

        $lotAppointment->documents->each(function (LotAppointmentDocument $document) use ($demandId, $mode): void {
            $document->update([
                'global_plus_pushed_at' => now(),
                'global_plus_remote_document' => [
                    'demand_id' => $demandId,
                    'mode' => $mode,
                    'file_name' => $this->documentFileName($document),
                    'category' => $this->documentCategory($document),
                ],
                'global_plus_error_message' => null,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function safeDemandPayload(array $payload): array
    {
        if (! isset($payload['files']) || ! is_array($payload['files'])) {
            return $payload;
        }

        $payload['files'] = collect($payload['files'])
            ->filter(fn (mixed $file): bool => is_array($file))
            ->map(fn (array $file): array => [
                'fileName' => $file['fileName'] ?? null,
                'category' => $file['category'] ?? null,
                'content_size' => strlen((string) ($file['fileContent'] ?? '')),
            ])
            ->values()
            ->all();

        return $payload;
    }

    private function safeResponsePayload(mixed $payload): mixed
    {
        if (is_scalar($payload) || $payload === null) {
            return $payload;
        }

        if (is_array($payload)) {
            return collect($payload)->take(50)->all();
        }

        return (string) $payload;
    }

    private function assertCanCreateDemand(LotAppointment $lotAppointment): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Global+ non configurée.');
        }

        $lotAppointment->loadMissing('appointment');

        if (filled($lotAppointment->global_plus_demand_id)) {
            throw new RuntimeException('Ce dossier existe déjà dans Global+.');
        }

        if (! $lotAppointment->appointment_id || ! $lotAppointment->appointment) {
            throw new RuntimeException('Le dossier doit être placé physiquement dans TechCalendar avant création Global+.');
        }

        if ($lotAppointment->processing_mode !== LotAppointment::PROCESSING_MODE_PHYSICAL) {
            throw new RuntimeException('Seuls les dossiers traités en RDV physique peuvent être créés dans Global+.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $clients
     */
    private function matchingClientAddressIds(LotAppointment $lotAppointment, array $clients): array
    {
        $delegataire = trim((string) $lotAppointment->lot?->delegataire);
        if ($delegataire === '') {
            return [];
        }
        $names = ExternalDelegataire::query()->source('coffrac')->where('name', $delegataire)->pluck('company_name')
            ->push($delegataire)->map(fn ($name) => $this->normalizeMatchValue($name))->filter()->unique();

        return collect($clients)
            ->filter(fn ($client) => $names->contains($this->normalizeMatchValue($client['label'] ?? '')))
            ->pluck('address_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $installers
     */
    private function suggestInstallerAddressId(LotAppointment $lotAppointment, array $installers): ?int
    {
        $siren = preg_replace('/\s+/', '', (string) $lotAppointment->installer_siren);
        $installers = array_values(array_filter($installers, fn (array $installer): bool => ! ($installer['blocked'] ?? false)));
        if ($siren !== '') {
            $matches = collect($installers)->filter(fn (array $installer): bool => preg_replace('/\s+/', '', (string) ($installer['siren'] ?? '')) === $siren);

            return $matches->count() === 1 ? (int) $matches->first()['address_id'] : null;
        }
        $installerName = $this->normalizeMatchValue($lotAppointment->installer_name);

        if ($installerName === '') {
            return null;
        }

        $best = collect($installers)
            ->map(function (array $installer) use ($installerName): array {
                $candidate = $this->normalizeMatchValue($installer['name'] ?? $installer['label'] ?? '');
                $score = 0;

                if ($candidate === $installerName) {
                    $score += 100;
                }

                if ($candidate !== '' && (str_contains($candidate, $installerName) || str_contains($installerName, $candidate))) {
                    $score += 70;
                }

                similar_text($installerName, $candidate, $similarity);
                $score += (int) round($similarity / 2);

                return ['score' => $score, 'installer' => $installer];
            })
            ->sortByDesc('score')
            ->first();

        return ($best['score'] ?? 0) >= 55 ? (int) $best['installer']['address_id'] : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $controllers
     */
    private function suggestControllerId(LotAppointment $lotAppointment, array $controllers): ?int
    {
        $email = Str::lower(trim((string) $lotAppointment->appointment?->technician?->email));

        if ($email === '') {
            return null;
        }

        $controller = collect($controllers)->first(fn (array $controller): bool => ($controller['email'] ?? null) === $email
            && ($controller['active'] ?? true) !== false);

        return $controller ? (int) $controller['id'] : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $versions
     */
    private function suggestVersionFormulaireId(LotAppointment $lotAppointment, array $versions): ?int
    {
        $candidates = collect([
            $lotAppointment->service_name,
            $lotAppointment->service?->name,
            $lotAppointment->service?->type,
            $lotAppointment->appointment?->service?->name,
            $lotAppointment->appointment?->service?->type,
            $lotAppointment->lot?->service?->name,
            $lotAppointment->lot?->service?->type,
            $lotAppointment->lot?->coffracServiceAlias?->external_name,
            data_get($lotAppointment->raw_payload, 'coffrac_service_alias_name'),
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = collect($versions)
            ->map(function (array $version) use ($candidates): array {
                $score = $candidates
                    ->map(fn (string $candidate): int => $this->versionMatchScore($candidate, $version))
                    ->max() ?? 0;

                return ['score' => $score, 'version' => $version];
            })
            ->sortByDesc('score')
            ->first();

        return ($best['score'] ?? 0) >= 45 ? (int) $best['version']['version_formulaire_id'] : null;
    }

    /**
     * @param  array<string, mixed>  $version
     */
    private function versionMatchScore(string $candidate, array $version): int
    {
        $candidateTokens = $this->matchTokens($candidate);
        $versionTokens = $this->matchTokens(($version['label'] ?? '').' '.($version['code'] ?? ''));

        if ($candidateTokens === [] || $versionTokens === []) {
            return 0;
        }

        $candidateCompact = implode('', $candidateTokens);
        $versionCompact = implode('', $versionTokens);
        $score = 0;

        if ($candidateCompact === $versionCompact) {
            $score += 120;
        }

        if (str_contains($versionCompact, $candidateCompact) || str_contains($candidateCompact, $versionCompact)) {
            $score += 70;
        }

        $shared = array_intersect($candidateTokens, $versionTokens);
        $score += count($shared) * 18;

        similar_text($candidateCompact, $versionCompact, $similarity);
        $score += (int) round($similarity / 4);

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function matchTokens(string $value): array
    {
        $value = Str::upper(Str::ascii($value));
        $value = preg_replace('/([A-Z]+)([0-9]+)/', '$1 $2', (string) $value);
        $value = preg_replace('/([0-9]+)([A-Z]+)/', '$1 $2', (string) $value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', (string) $value);

        return collect(explode(' ', trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeMatchValue(?string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $value))));
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitCustomerName(LotAppointment $lotAppointment): array
    {
        $firstName = trim((string) $lotAppointment->customer_first_name);
        $lastName = trim((string) $lotAppointment->customer_last_name);

        if ($firstName !== '' || $lastName !== '') {
            return [$firstName ?: null, $lastName ?: null];
        }

        $name = trim((string) $lotAppointment->customer_name);

        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        if (count($parts) <= 1) {
            return [null, $this->limit($name, self::ADDRESS_NAME_MAX_LENGTH)];
        }

        return [
            $this->limit(array_shift($parts), self::ADDRESS_NAME_MAX_LENGTH),
            $this->limit(implode(' ', $parts), self::ADDRESS_NAME_MAX_LENGTH),
        ];
    }

    private function customerCompanyName(LotAppointment $lotAppointment): ?string
    {
        return $this->nullableString($lotAppointment->company_name)
            ?: $this->nullableString($lotAppointment->customer_name)
            ?: $this->nullableString($lotAppointment->site_name);
    }

    private function defaultTitle(LotAppointment $lotAppointment): string
    {
        return Str::limit('Lot '.$lotAppointment->lot?->name, self::TITLE_MAX_LENGTH, '');
    }

    private function defaultSubTitle(LotAppointment $lotAppointment): string
    {
        if (filled($lotAppointment->internalReference())) {
            return $this->limit($lotAppointment->internalReference(), self::SUB_TITLE_MAX_LENGTH);
        }

        return Str::limit(trim(implode(' - ', array_filter([
            $lotAppointment->lot?->name,
            $lotAppointment->row_number ? 'Ligne '.$lotAppointment->row_number : null,
            $this->customerCompanyName($lotAppointment),
            $lotAppointment->site_name,
        ]))), self::SUB_TITLE_MAX_LENGTH, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @return array<int, array<string, mixed>>
     */
    private function publicReferences(array $references): array
    {
        return collect($references)
            ->map(function (array $reference): array {
                unset($reference['payload']);

                return $reference;
            })
            ->values()
            ->all();
    }

    private function limit(mixed $value, int $limit): ?string
    {
        $string = $this->nullableString($value);

        return $string === null ? null : Str::limit($string, $limit, '');
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withDemandFilesLock(string $demandId, callable $callback): mixed
    {
        $lock = Cache::lock('global_plus:demand_files:'.$demandId, 300);

        if ($lock->get()) {
            try {
                return $callback();
            } finally {
                $lock->release();
            }
        }

        throw new RuntimeException('Une synchronisation des documents Global+ est déjà en cours pour ce dossier.');
    }
}
