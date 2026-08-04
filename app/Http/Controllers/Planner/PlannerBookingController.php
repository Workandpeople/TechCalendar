<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCoffracAppointmentsJob;
use App\Models\Appointment;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\MailTemplate;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use App\Services\AppointmentDocumentSerializer;
use App\Services\AppointmentMailTemplateData;
use App\Services\AppointmentTechnicianMailService;
use App\Services\CoffracAppointmentService;
use App\Services\ExternalAppointmentSourceRegistry;
use App\Services\LotAutoCompletionCalculator;
use App\Services\MapboxDrivingRouteService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class PlannerBookingController extends Controller
{
    private const CRM_APPOINTMENT_LIST_LIMIT = 300;

    private const APPOINTMENT_TRANSITION_MARGIN_MINUTES = 10;

    private const BREAK_WINDOW_START = '11:00';

    private const BREAK_WINDOW_END = '14:00';

    public function index(
        Request $request,
        CoffracAppointmentService $coffracAppointments,
        LotAutoCompletionCalculator $autoCompletion
    ): View {
        abort_unless($this->canAccess($request), 403);

        $isReplacementMode = $request->routeIs('planner.appointments.modify', 'manager.appointments.modify');
        $isManagerRoute = $request->routeIs('manager.*');
        $services = Service::query()
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'average_duration_minutes']);
        $mailTemplates = MailTemplate::query()
            ->with('sender:id,name,logo_path,is_active')
            ->where('is_active', true)
            ->whereHas('sender', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'mail_sender_id', 'subject', 'markdown_body', 'logo_path']);
        $bookingMailTemplates = $mailTemplates
            ->map(fn (MailTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'mail_sender_id' => $template->mail_sender_id,
                'sender_name' => $template->sender?->name,
                'subject' => $template->subject,
                'markdown_body' => $template->markdown_body,
                'logo_url' => $template->logo_url,
            ])
            ->values();
        $coffracPending = $coffracAppointments->pendingWithStatus(self::CRM_APPOINTMENT_LIST_LIMIT);
        $externalAppointmentSources = $this->externalAppointmentSources($coffracPending['status']);

        return view('planner.book', [
            'crmAppointments' => $coffracPending['appointments'],
            'coffracApiStatus' => $coffracPending['status'],
            'externalAppointmentSources' => $externalAppointmentSources,
            'lotRequests' => $this->lotAppointmentRequests($autoCompletion),
            'initialCrmAppointmentId' => $request->query('crm_appointment_id'),
            'initialReplaceAppointmentId' => $request->query('replace_appointment_id') ?: $request->query('appointment_id'),
            'bookingMode' => $isReplacementMode ? 'replace' : 'create',
            'bookingSection' => $isManagerRoute ? 'Gérant' : 'Planning',
            'bookingTitle' => $isReplacementMode ? 'Modifier un RDV' : 'Prise de RDV',
            'bookingSubtitle' => $isReplacementMode
                ? 'Recherche un RDV existant, consulte son détail ou relance un workflow complet de replacement.'
                : 'Sélectionne une demande externe ou saisis un RDV manuel pour identifier les techniciens éligibles.',
            'replaceSearchUrl' => route($isManagerRoute ? 'manager.appointments.search' : 'planner.tracking.search'),
            'replacementPageUrl' => route($isManagerRoute ? 'manager.appointments.modify' : 'planner.appointments.modify'),
            'bookingTrackingUrl' => route($isManagerRoute ? 'manager.appointments' : 'planner.tracking'),
            'mapboxToken' => config('services.mapbox.token'),
            'coffracProblemTypes' => $coffracAppointments->problemTypes(),
            'services' => $services,
            'mailTemplates' => $mailTemplates,
            'bookingMailTemplates' => $bookingMailTemplates,
            'bookingServices' => $services
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'type' => $service->type,
                    'name' => $service->name,
                    'average_duration_minutes' => $service->average_duration_minutes,
                ])
                ->values(),
        ]);
    }

    public function analyze(
        Request $request,
        CoffracAppointmentService $coffracAppointments,
        MapboxDrivingRouteService $drivingRoutes
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate($this->appointmentRequestRules());

        $crmAppointment = $this->resolveRequestedAppointment($payload, $coffracAppointments);

        abort_if(! $crmAppointment, 404, 'Demande de rendez-vous introuvable.');

        if (! is_numeric($crmAppointment['latitude'] ?? null) || ! is_numeric($crmAppointment['longitude'] ?? null)) {
            throw ValidationException::withMessages([
                'crm_appointment_id' => 'Coordonnées GPS absentes pour ce RDV. Ouvre le détail du RDV, corrige l’adresse puis relance le géocodage Mapbox.',
            ]);
        }

        $technicians = $this->ensureReplacementTechnician(
            $this->eligibleTechnicians($crmAppointment, $drivingRoutes),
            $crmAppointment,
            $drivingRoutes
        );
        $technicianIds = $technicians->pluck('id');
        $calendarStart = now()->startOfWeek();
        $calendarEnd = now()->copy()->addWeeks(8)->endOfWeek();
        $preferredStartsAt = $this->preferredStartsAt($crmAppointment);

        if ($preferredStartsAt) {
            $preferredWeekStart = $preferredStartsAt->copy()->startOfWeek();
            $preferredWeekEnd = $preferredStartsAt->copy()->endOfWeek();

            if ($preferredWeekStart->lt($calendarStart)) {
                $calendarStart = $preferredWeekStart;
            }

            if ($preferredWeekEnd->gt($calendarEnd)) {
                $calendarEnd = $preferredWeekEnd;
            }
        }

        $this->loadAbsencesForTechnicians($technicians, $calendarStart, $calendarEnd);
        $appointments = $this->appointmentsForTechnicians($technicianIds, $calendarStart, $calendarEnd);
        $replacementAppointmentId = $this->replacementAppointmentId($crmAppointment);
        $appointmentsForSuggestions = $this->appointmentsWithoutReplacement($appointments, $replacementAppointmentId);

        return response()->json([
            'crm_appointment' => $crmAppointment,
            'filters' => [
                'department_code' => $crmAppointment['department_code'],
                'service_required' => $crmAppointment['service'] !== null,
                'preferred_starts_at' => $crmAppointment['preferred_starts_at'] ?? null,
                'source' => $crmAppointment['source'],
                'is_manual' => (bool) ($crmAppointment['is_manual'] ?? false),
                'is_lot' => (bool) ($crmAppointment['is_lot'] ?? false),
            ],
            'technicians' => $this->serializeTechnicians($technicians),
            'events' => $this->calendarEvents($appointments, $replacementAppointmentId),
            'suggestions' => $this->buildSlotSuggestions($technicians, $appointmentsForSuggestions, $crmAppointment, $drivingRoutes),
            'calendar_range' => [
                'start' => $calendarStart->toDateString(),
                'end' => $calendarEnd->toDateString(),
            ],
        ]);
    }

    public function refreshCrmAppointments(
        Request $request,
        CoffracAppointmentService $coffracAppointments
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        if ($coffracAppointments->isConfigured()) {
            $coffracAppointments->markSyncQueued('Récupération des RDV à placer Coffrac lancée en arrière-plan...');
            SyncCoffracAppointmentsJob::dispatch(false, CoffracAppointmentService::REMOTE_STATUS_PENDING);
        }

        $coffracPending = $coffracAppointments->pendingWithStatus(0);

        return response()->json([
            'sync_queued' => $coffracAppointments->isConfigured(),
            'message' => $coffracAppointments->isConfigured()
                ? 'Récupération des RDV à placer Coffrac lancée. Les rendez-vous affichés correspondent aux dernières données déjà récupérées.'
                : 'API Coffrac non configurée.',
            'appointments' => [],
            'coffrac_api_status' => $coffracPending['status'],
            'external_sources' => $this->externalAppointmentSources($coffracPending['status']),
        ]);
    }

    public function crmAppointments(
        Request $request,
        CoffracAppointmentService $coffracAppointments
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $coffracPending = $coffracAppointments->pendingWithStatus(self::CRM_APPOINTMENT_LIST_LIMIT, shuffle: true);

        return response()->json([
            'appointments' => $coffracPending['appointments'],
            'coffrac_api_status' => $coffracPending['status'],
            'external_sources' => $this->externalAppointmentSources($coffracPending['status']),
        ]);
    }

    public function updateCrmAppointment(
        Request $request,
        string $crmAppointmentId,
        CoffracAppointmentService $coffracAppointments
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id'),
            ],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $appointment = $coffracAppointments->updatePendingAppointment($crmAppointmentId, $payload);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'address' => $exception->getMessage(),
            ]);
        }

        abort_if(! $appointment, 404, 'Demande de rendez-vous Coffrac introuvable.');

        $coffracPending = $coffracAppointments->pendingWithStatus(self::CRM_APPOINTMENT_LIST_LIMIT);

        return response()->json([
            'message' => 'RDV externe mis à jour.',
            'appointment' => $appointment,
            'appointments' => $coffracPending['appointments'],
            'coffrac_api_status' => $coffracPending['status'],
            'external_sources' => $this->externalAppointmentSources($coffracPending['status']),
        ]);
    }

    public function refreshCrmAppointment(
        Request $request,
        string $crmAppointmentId,
        CoffracAppointmentService $coffracAppointments
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        try {
            $appointment = $coffracAppointments->refreshPendingAppointment($crmAppointmentId);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'crm_appointment_id' => $exception->getMessage(),
            ]);
        }

        abort_if(! $appointment, 404, 'Demande de rendez-vous Coffrac introuvable.');

        $coffracPending = $coffracAppointments->pendingWithStatus(self::CRM_APPOINTMENT_LIST_LIMIT);

        return response()->json([
            'message' => 'Dossier Coffrac mis à jour.',
            'appointment' => $appointment,
            'appointments' => $coffracPending['appointments'],
            'coffrac_api_status' => $coffracPending['status'],
            'external_sources' => $this->externalAppointmentSources($coffracPending['status']),
        ]);
    }

    public function markCrmAppointmentProblem(
        Request $request,
        string $crmAppointmentId,
        CoffracAppointmentService $coffracAppointments
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate($this->problemReportRules($coffracAppointments));

        try {
            $appointment = $coffracAppointments->markPendingAppointmentProblem($crmAppointmentId, $payload);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'comment' => $exception->getMessage(),
            ]);
        }

        abort_if(! $appointment, 404, 'Demande de rendez-vous Coffrac introuvable.');

        $coffracPending = $coffracAppointments->pendingWithStatus(self::CRM_APPOINTMENT_LIST_LIMIT);

        return response()->json([
            'message' => 'Problème RDV déclaré dans Coffrac.',
            'appointment' => $appointment,
            'appointments' => $coffracPending['appointments'],
            'coffrac_api_status' => $coffracPending['status'],
            'external_sources' => $this->externalAppointmentSources($coffracPending['status']),
        ]);
    }

    public function searchTechnicians(
        Request $request,
        CoffracAppointmentService $coffracAppointments,
        MapboxDrivingRouteService $drivingRoutes
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            ...$this->appointmentRequestRules(),
            'query' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $crmAppointment = $this->resolveRequestedAppointment($payload, $coffracAppointments);
        abort_if(! $crmAppointment, 404, 'Demande de rendez-vous introuvable.');

        $technicians = $this->searchTechniciansForAppointment(
            $crmAppointment,
            trim($payload['query']),
            $drivingRoutes,
        );
        $this->loadAbsencesForTechnicians($technicians, now()->copy()->startOfDay(), now()->copy()->addWeeks(8)->endOfWeek());

        return response()->json([
            'technicians' => $this->serializeTechnicians($technicians),
        ]);
    }

    public function calendarWindow(
        Request $request,
        CoffracAppointmentService $coffracAppointments,
        MapboxDrivingRouteService $drivingRoutes
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            ...$this->appointmentRequestRules(),
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'technician_ids' => ['nullable', 'array', 'max:200'],
            'technician_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 2)->where('admin', false)),
            ],
        ]);

        $crmAppointment = $this->resolveRequestedAppointment($payload, $coffracAppointments);
        abort_if(! $crmAppointment, 404, 'Demande de rendez-vous introuvable.');

        $windowStart = Carbon::parse($payload['start']);
        $windowEnd = Carbon::parse($payload['end']);
        $technicians = isset($payload['technician_ids'])
            ? $this->techniciansByIdsForAppointment($payload['technician_ids'], $crmAppointment, $drivingRoutes)
            : $this->eligibleTechnicians($crmAppointment, $drivingRoutes);
        $technicians = $this->ensureReplacementTechnician($technicians, $crmAppointment, $drivingRoutes);
        $this->loadAbsencesForTechnicians($technicians, $windowStart, $windowEnd);
        $appointments = $this->appointmentsForTechnicians($technicians->pluck('id'), $windowStart, $windowEnd);
        $replacementAppointmentId = $this->replacementAppointmentId($crmAppointment);
        $appointmentsForSuggestions = $this->appointmentsWithoutReplacement($appointments, $replacementAppointmentId);

        return response()->json([
            'technicians' => $this->serializeTechnicians($technicians),
            'events' => $this->calendarEvents($appointments, $replacementAppointmentId),
            'suggestions' => $this->buildSlotSuggestions(
                $technicians,
                $appointmentsForSuggestions,
                $crmAppointment,
                $drivingRoutes,
                $windowStart,
                $windowEnd,
            ),
            'calendar_range' => [
                'start' => $windowStart->toIso8601String(),
                'end' => $windowEnd->toIso8601String(),
            ],
        ]);
    }

    public function store(
        Request $request,
        CoffracAppointmentService $coffracAppointments,
        AppointmentTechnicianMailService $appointmentMails,
        AppointmentMailTemplateData $appointmentMailData,
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            ...$this->appointmentRequestRules(),
            'technician_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 2)->where('admin', false)),
            ],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:480'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $crmAppointment = $this->resolveRequestedAppointment($payload, $coffracAppointments);
        abort_if(! $crmAppointment, 404, 'Demande de rendez-vous introuvable.');
        $replacementAppointment = ! empty($payload['replace_appointment_id'])
            ? Appointment::query()->findOrFail((int) $payload['replace_appointment_id'])
            : null;

        if (! $crmAppointment['service']) {
            $serviceErrorKey = match (true) {
                ! empty($payload['lot_appointment_id']) => 'lot_appointment_id',
                ! empty($payload['replace_appointment_id']) => 'replace_appointment_id',
                default => 'crm_service_id',
            };

            throw ValidationException::withMessages([
                $serviceErrorKey => 'Impossible de valider sans prestation renseignée.',
            ]);
        }

        if (! $this->technicianSupportsService((int) $payload['technician_id'], (int) $crmAppointment['service']['id'])) {
            throw ValidationException::withMessages([
                'technician_id' => 'Ce technicien ne réalise pas cette prestation.',
            ]);
        }

        $startsAt = Carbon::parse($payload['starts_at']);
        $durationMinutes = (int) $payload['duration_minutes'];
        $endsAt = (clone $startsAt)->addMinutes($durationMinutes);
        $absence = $this->absenceOverlapForTechnician((int) $payload['technician_id'], $startsAt, $endsAt);

        if ($absence) {
            throw ValidationException::withMessages([
                'technician_id' => 'Ce technicien est absent '.$this->absenceLabel($absence).'.',
            ]);
        }

        $hasOverlappingAppointment = Appointment::query()
            ->where('technician_id', (int) $payload['technician_id'])
            ->whereNull('deleted_at')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($replacementAppointment, fn ($query) => $query->whereKeyNot($replacementAppointment->id))
            ->exists();

        if ($hasOverlappingAppointment) {
            throw ValidationException::withMessages([
                'starts_at' => 'Ce technicien a déjà un RDV sur ce créneau.',
            ]);
        }

        if (($crmAppointment['external_source'] ?? null) === CoffracAppointmentService::SOURCE
            && Appointment::query()
                ->where('external_source', CoffracAppointmentService::SOURCE)
                ->where('external_reference', (string) ($crmAppointment['external_reference'] ?? ''))
                ->when($replacementAppointment, fn ($query) => $query->whereKeyNot($replacementAppointment->id))
                ->exists()) {
            throw ValidationException::withMessages([
                'crm_appointment_id' => 'Ce RDV Coffrac a déjà été placé dans TechCalendar.',
            ]);
        }

        $previousTechnicianId = $replacementAppointment?->technician_id ? (int) $replacementAppointment->technician_id : null;
        $previousDate = $replacementAppointment?->starts_at?->toDateString();

        try {
            $appointment = DB::transaction(function () use (
                $coffracAppointments,
                $crmAppointment,
                $durationMinutes,
                $endsAt,
                $payload,
                $replacementAppointment,
                $request,
                $startsAt
            ): Appointment {
                $appointmentAttributes = [
                    'service_id' => $crmAppointment['service']['id'],
                    'technician_id' => $payload['technician_id'],
                    'customer_first_name' => $crmAppointment['first_name'],
                    'customer_last_name' => $crmAppointment['last_name'],
                    'customer_phone' => $crmAppointment['phone'],
                    'address' => $crmAppointment['address'],
                    'latitude' => $crmAppointment['latitude'],
                    'longitude' => $crmAppointment['longitude'],
                    'starts_at' => $startsAt,
                    'duration_minutes' => $durationMinutes,
                    'ends_at' => $endsAt,
                    'comment' => $payload['comment'] ?? null,
                    'status' => Appointment::STATUS_SCHEDULED,
                    'problem_reported_at' => null,
                    'external_source' => $crmAppointment['external_source'] ?? null,
                    'external_reference' => $crmAppointment['external_reference'] ?? null,
                    'external_payload' => $crmAppointment['external_payload'] ?? null,
                ];

                if ($replacementAppointment) {
                    $replacementAppointment->update($appointmentAttributes);
                    $appointment = $replacementAppointment->refresh();
                } else {
                    $appointment = Appointment::query()->create([
                        ...$appointmentAttributes,
                        'created_by' => $request->user()->id,
                    ]);
                }

                if (! $replacementAppointment && ! empty($payload['lot_appointment_id'])) {
                    $lotAppointment = LotAppointment::query()
                        ->with('lot')
                        ->where('excluded_from_lot_stats', false)
                        ->whereKey((int) $payload['lot_appointment_id'])
                        ->first();

                    if (! $lotAppointment) {
                        throw new RuntimeException('Ce dossier est sorti des statistiques du lot.');
                    }

                    $lotAppointment->update([
                        'appointment_id' => $appointment->id,
                        'service_id' => $crmAppointment['service']['id'],
                        'status' => LotAppointment::STATUS_PLACED,
                        'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL,
                    ]);

                    $this->refreshLotStatus($lotAppointment->lot);
                }

                $coffracAppointments->markPlaced($appointment, $crmAppointment);

                return $appointment;
            });
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'crm_appointment_id' => $exception->getMessage(),
            ]);
        }

        if ($replacementAppointment) {
            $this->forgetRouteMetricsForAppointmentChange(
                $previousTechnicianId,
                (int) $appointment->technician_id,
                $previousDate,
                $appointment->starts_at?->toDateString(),
            );

            if ($previousTechnicianId && $previousTechnicianId !== (int) $appointment->technician_id) {
                $appointmentMails->reassigned($appointment, $previousTechnicianId);
            } else {
                $appointmentMails->detailsUpdated($appointment);
            }
        } else {
            $appointmentMails->created($appointment);
        }

        return response()->json([
            'message' => $replacementAppointment ? 'Rendez-vous replacé.' : 'Rendez-vous créé.',
            'appointment_id' => $appointment->id,
            'mail_recipient_email' => $appointmentMailData->defaultRecipientEmail($appointment),
        ], $replacementAppointment ? 200 : 201);
    }

    public function processLotContactAppointment(Request $request, LotAppointment $lotAppointment): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $lotAppointment->load('lot');

        abort_if(! $lotAppointment->lot?->supportsContactProcessing(), 422, 'Ce lot ne prévoit pas de traitement par téléphone.');
        abort_if($this->isExcludedFromLotStats($lotAppointment), 422, 'Ce dossier est sorti des statistiques du lot.');
        abort_if($this->isPlacedLotAppointment($lotAppointment), 422, 'Ce dossier a déjà été transformé en RDV physique.');

        $payload = $request->validate([
            'contact_satisfaction' => ['required', 'boolean'],
            'contact_comment' => ['required', 'string', 'max:2000'],
        ]);

        $lotAppointment->update([
            'processing_mode' => LotAppointment::PROCESSING_MODE_CONTACT,
            'status' => LotAppointment::STATUS_CONTACT_PROCESSED,
            'contact_satisfaction' => (bool) $payload['contact_satisfaction'],
            'contact_comment' => $payload['contact_comment'],
            'contact_processed_at' => now(),
            'contact_processed_by' => $request->user()->id,
        ]);

        $this->refreshLotStatus($lotAppointment->lot);

        return response()->json([
            'message' => 'Traitement téléphonique enregistré.',
            'appointment' => [
                'id' => $lotAppointment->id,
                'status' => LotAppointment::STATUS_CONTACT_PROCESSED,
                'status_label' => $lotAppointment->fresh()->statusLabel(),
            ],
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleTechnicians(array $crmAppointment, MapboxDrivingRouteService $drivingRoutes): Collection
    {
        $candidateTechnicians = User::query()
            ->with(['departments:code,name'])
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($crmAppointment['service'], function ($query) use ($crmAppointment): void {
                $query->whereHas('services', fn ($serviceQuery) => $serviceQuery->where('services.id', $crmAppointment['service']['id']));
            })
            ->get();

        $techniciansByDistance = $candidateTechnicians
            ->map(function (User $technician) use ($crmAppointment): User {
                $technician->setAttribute('flight_distance_km', $this->haversine(
                    (float) $technician->latitude,
                    (float) $technician->longitude,
                    (float) $crmAppointment['latitude'],
                    (float) $crmAppointment['longitude'],
                ));
                $technician->setAttribute(
                    'covers_requested_department',
                    $technician->departments->contains('code', $crmAppointment['department_code'])
                );

                return $technician;
            })
            ->sortBy(fn (User $technician): float => (float) $technician->getAttribute('flight_distance_km'))
            ->values();
        $strictTechnicians = $techniciansByDistance
            ->filter(fn (User $technician): bool => (bool) $technician->getAttribute('covers_requested_department'))
            ->values();
        $routingPool = $strictTechnicians->count() >= 3
            ? $strictTechnicians
            : $strictTechnicians->merge(
                $techniciansByDistance->whereNotIn('id', $strictTechnicians->pluck('id'))->take(3 - $strictTechnicians->count())
            );

        return $routingPool
            ->map(function (User $technician) use ($crmAppointment, $drivingRoutes): User {
                $route = $drivingRoutes->estimate(
                    (float) $technician->latitude,
                    (float) $technician->longitude,
                    (float) $crmAppointment['latitude'],
                    (float) $crmAppointment['longitude'],
                );

                $technician->setAttribute('driving_distance_km', $route['distance_km']);
                $technician->setAttribute('driving_duration_minutes', $route['duration_minutes']);
                $technician->setAttribute('route_source', $route['source']);

                return $technician;
            })
            ->sort(function (User $leftTechnician, User $rightTechnician): int {
                $durationComparison = (int) $leftTechnician->getAttribute('driving_duration_minutes')
                    <=> (int) $rightTechnician->getAttribute('driving_duration_minutes');

                if ($durationComparison !== 0) {
                    return $durationComparison;
                }

                return (float) $leftTechnician->getAttribute('driving_distance_km')
                    <=> (float) $rightTechnician->getAttribute('driving_distance_km');
            })
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function searchTechniciansForAppointment(
        array $crmAppointment,
        string $query,
        MapboxDrivingRouteService $drivingRoutes
    ): Collection {
        $terms = collect(preg_split('/\s+/', trim($query)) ?: [])
            ->filter()
            ->values();

        if ($terms->isEmpty()) {
            return collect();
        }

        $serviceId = $crmAppointment['service']['id'] ?? null;

        $technicians = User::query()
            ->with(['departments:code,name'])
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($serviceId, function ($query) use ($serviceId): void {
                $query->whereHas('services', fn ($serviceQuery) => $serviceQuery->where('services.id', $serviceId));
            })
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $term).'%';

                    $query->where(function ($termQuery) use ($like): void {
                        $termQuery
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhere('address', 'like', $like)
                            ->orWhere('department_code', 'like', $like);
                    });
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(12)
            ->get();

        return $this->withRouteAttributes($technicians, $crmAppointment, $drivingRoutes)
            ->sort(function (User $leftTechnician, User $rightTechnician): int {
                $coverageComparison = (int) $rightTechnician->getAttribute('covers_requested_department')
                    <=> (int) $leftTechnician->getAttribute('covers_requested_department');

                if ($coverageComparison !== 0) {
                    return $coverageComparison;
                }

                $durationComparison = (int) $leftTechnician->getAttribute('driving_duration_minutes')
                    <=> (int) $rightTechnician->getAttribute('driving_duration_minutes');

                if ($durationComparison !== 0) {
                    return $durationComparison;
                }

                return (float) $leftTechnician->getAttribute('driving_distance_km')
                    <=> (float) $rightTechnician->getAttribute('driving_distance_km');
            })
            ->values();
    }

    /**
     * @param  array<int, mixed>  $technicianIds
     * @return Collection<int, User>
     */
    private function techniciansByIdsForAppointment(
        array $technicianIds,
        array $crmAppointment,
        MapboxDrivingRouteService $drivingRoutes
    ): Collection {
        $orderedIds = collect($technicianIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($orderedIds->isEmpty()) {
            return collect();
        }

        $positions = $orderedIds
            ->flip()
            ->map(fn ($position): int => (int) $position)
            ->all();
        $serviceId = $crmAppointment['service']['id'] ?? null;

        $technicians = User::query()
            ->with(['departments:code,name'])
            ->whereIn('id', $orderedIds->all())
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($serviceId, function ($query) use ($serviceId): void {
                $query->whereHas('services', fn ($serviceQuery) => $serviceQuery->where('services.id', $serviceId));
            })
            ->get();

        return $this->withRouteAttributes($technicians, $crmAppointment, $drivingRoutes)
            ->sortBy(fn (User $technician): int => $positions[$technician->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * @param  Collection<int, User>  $technicians
     * @return Collection<int, User>
     */
    private function withRouteAttributes(Collection $technicians, array $crmAppointment, MapboxDrivingRouteService $drivingRoutes): Collection
    {
        return $technicians
            ->map(function (User $technician) use ($crmAppointment, $drivingRoutes): User {
                $route = $drivingRoutes->estimate(
                    (float) $technician->latitude,
                    (float) $technician->longitude,
                    (float) $crmAppointment['latitude'],
                    (float) $crmAppointment['longitude'],
                );

                $technician->setAttribute(
                    'covers_requested_department',
                    $technician->departments->contains('code', $crmAppointment['department_code'])
                );
                $technician->setAttribute('driving_distance_km', $route['distance_km']);
                $technician->setAttribute('driving_duration_minutes', $route['duration_minutes']);
                $technician->setAttribute('route_source', $route['source']);

                return $technician;
            })
            ->values();
    }

    /**
     * @param  Collection<int, User>  $technicians
     * @return Collection<int, User>
     */
    private function ensureReplacementTechnician(
        Collection $technicians,
        array $crmAppointment,
        MapboxDrivingRouteService $drivingRoutes
    ): Collection {
        $replacementAppointmentId = $this->replacementAppointmentId($crmAppointment);

        if (! $replacementAppointmentId) {
            return $technicians;
        }

        $replacementAppointment = Appointment::query()
            ->with(['technician.departments:code,name'])
            ->whereKey($replacementAppointmentId)
            ->first();

        $technician = $replacementAppointment?->technician;

        if (! $technician || $technicians->contains('id', $technician->id)) {
            return $technicians;
        }

        if ($technician->latitude === null || $technician->longitude === null) {
            return $technicians;
        }

        return $this->withRouteAttributes($technicians->push($technician), $crmAppointment, $drivingRoutes)
            ->sort(function (User $leftTechnician, User $rightTechnician): int {
                $durationComparison = (int) $leftTechnician->getAttribute('driving_duration_minutes')
                    <=> (int) $rightTechnician->getAttribute('driving_duration_minutes');

                if ($durationComparison !== 0) {
                    return $durationComparison;
                }

                return (float) $leftTechnician->getAttribute('driving_distance_km')
                    <=> (float) $rightTechnician->getAttribute('driving_distance_km');
            })
            ->values();
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, Appointment>
     */
    private function appointmentsWithoutReplacement(Collection $appointments, ?int $replacementAppointmentId): Collection
    {
        if (! $replacementAppointmentId) {
            return $appointments;
        }

        return $appointments
            ->reject(fn (Appointment $appointment): bool => (int) $appointment->id === $replacementAppointmentId)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $crmAppointment
     */
    private function replacementAppointmentId(array $crmAppointment): ?int
    {
        $replacementAppointmentId = $crmAppointment['replace_appointment_id'] ?? null;

        return filled($replacementAppointmentId) ? (int) $replacementAppointmentId : null;
    }

    /**
     * @param  Collection<int, User>  $technicians
     * @return Collection<int, array<string, mixed>>
     */
    private function serializeTechnicians(Collection $technicians): Collection
    {
        return $technicians->map(function (User $technician): array {
            $absences = $technician->relationLoaded('absences')
                ? $technician->absences
                : collect();

            return [
                'id' => $technician->id,
                'name' => $technician->full_name_with_departments,
                'phone' => $technician->phone,
                'address' => $technician->address,
                'department_code' => $technician->department_code,
                'latitude' => $technician->latitude,
                'longitude' => $technician->longitude,
                'driving_distance_km' => round((float) $technician->getAttribute('driving_distance_km'), 1),
                'driving_duration_minutes' => (int) $technician->getAttribute('driving_duration_minutes'),
                'route_source' => $technician->getAttribute('route_source'),
                'covers_requested_department' => (bool) $technician->getAttribute('covers_requested_department'),
                'absence_label' => $absences
                    ->map(fn (TechnicianAbsence $absence): string => 'Abs '.$this->absenceLabel($absence))
                    ->implode(' · '),
                'absences' => $absences
                    ->map(fn (TechnicianAbsence $absence): array => [
                        'id' => $absence->id,
                        'starts_at' => $absence->starts_at?->toIso8601String(),
                        'ends_at' => $absence->ends_at?->toIso8601String(),
                        'label' => 'Abs '.$this->absenceLabel($absence),
                        'reason' => $absence->reason,
                    ])
                    ->values(),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, User>  $technicians
     */
    private function loadAbsencesForTechnicians(Collection $technicians, Carbon $start, Carbon $end): void
    {
        $technicianIds = $technicians
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($technicianIds->isEmpty()) {
            return;
        }

        $absences = TechnicianAbsence::query()
            ->whereIn('technician_id', $technicianIds->all())
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy('technician_id');

        $technicians->each(function (User $technician) use ($absences): void {
            $technician->setRelation(
                'absences',
                $absences->get($technician->id, (new TechnicianAbsence)->newCollection())
            );
        });
    }

    /**
     * @param  Collection<int, int>  $technicianIds
     * @return Collection<int, Appointment>
     */
    private function appointmentsForTechnicians(Collection $technicianIds, Carbon $start, Carbon $end): Collection
    {
        if ($technicianIds->isEmpty()) {
            return collect();
        }

        return Appointment::query()
            ->with([
                'service:id,type,name',
                'technician:id,first_name,last_name,department_code,role,latitude,longitude,address',
                'technician.departments:code',
            ])
            ->whereIn('technician_id', $technicianIds)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function appointmentRequestRules(): array
    {
        return [
            'crm_appointment_id' => ['nullable', 'string', 'required_without_all:manual_appointment,lot_appointment_id,replace_appointment_id'],
            'crm_service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id'),
            ],
            'lot_appointment_id' => [
                'nullable',
                'integer',
                'required_without_all:crm_appointment_id,manual_appointment,replace_appointment_id',
                Rule::exists('lot_appointments', 'id'),
            ],
            'lot_service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id'),
            ],
            'replace_appointment_id' => [
                'nullable',
                'integer',
                'required_without_all:crm_appointment_id,manual_appointment,lot_appointment_id',
                Rule::exists('appointments', 'id')->whereNull('deleted_at'),
            ],
            'manual_appointment' => ['nullable', 'array', 'required_without_all:crm_appointment_id,lot_appointment_id,replace_appointment_id'],
            'manual_appointment.first_name' => ['required_with:manual_appointment', 'string', 'max:120'],
            'manual_appointment.last_name' => ['required_with:manual_appointment', 'string', 'max:120'],
            'manual_appointment.phone' => ['required_with:manual_appointment', 'string', 'max:255'],
            'manual_appointment.address' => ['required_with:manual_appointment', 'string', 'max:255'],
            'manual_appointment.department_code' => ['required_with:manual_appointment', 'string', 'max:3'],
            'manual_appointment.latitude' => ['required_with:manual_appointment', 'numeric', 'between:-90,90'],
            'manual_appointment.longitude' => ['required_with:manual_appointment', 'numeric', 'between:-180,180'],
            'manual_appointment.service_id' => [
                'required_with:manual_appointment',
                'integer',
                Rule::exists('services', 'id'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function problemReportRules(CoffracAppointmentService $coffracAppointments): array
    {
        return [
            'comment' => ['required', 'string', 'min:3', 'max:5000'],
            'problem_type' => ['required', 'string', Rule::in($coffracAppointments->problemTypeValues())],
            'recall_date' => ['nullable', 'required_if:problem_type,'.CoffracAppointmentService::PROBLEM_TYPE_CALLBACK, 'date'],
            'recall_time' => ['nullable', 'required_if:problem_type,'.CoffracAppointmentService::PROBLEM_TYPE_CALLBACK, 'date_format:H:i'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function resolveRequestedAppointment(
        array $payload,
        CoffracAppointmentService $coffracAppointments
    ): ?array {
        if (! empty($payload['crm_appointment_id'])) {
            $crmAppointmentId = (string) $payload['crm_appointment_id'];

            if (! str_starts_with($crmAppointmentId, CoffracAppointmentService::SOURCE.'-')) {
                return null;
            }

            $appointment = $coffracAppointments->find($crmAppointmentId);

            if (! $appointment) {
                return null;
            }

            $service = $appointment['service'];

            if (! $service && isset($payload['crm_service_id'])) {
                $selectedService = Service::query()->find((int) $payload['crm_service_id']);
                $service = $selectedService ? [
                    'id' => $selectedService->id,
                    'type' => $selectedService->type,
                    'name' => $selectedService->name,
                    'average_duration_minutes' => $selectedService->average_duration_minutes,
                ] : null;
            }

            return [
                ...$appointment,
                'service' => $service,
                'is_manual' => false,
                'preferred_starts_at' => null,
            ];
        }

        if (! empty($payload['lot_appointment_id'])) {
            return $this->lotAppointmentFromId(
                (int) $payload['lot_appointment_id'],
                isset($payload['lot_service_id']) ? (int) $payload['lot_service_id'] : null,
            );
        }

        if (! empty($payload['replace_appointment_id'])) {
            return $this->replacementAppointmentFromId((int) $payload['replace_appointment_id']);
        }

        if (! isset($payload['manual_appointment']) || ! is_array($payload['manual_appointment'])) {
            return null;
        }

        return $this->manualAppointmentFromPayload($payload['manual_appointment']);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lotAppointmentRequests(LotAutoCompletionCalculator $autoCompletion): Collection
    {
        $placeableStatus = [
            LotAppointment::STATUS_PENDING,
            LotAppointment::STATUS_NEEDS_REVIEW,
        ];

        return Lot::query()
            ->with([
                'service:id,type,name,average_duration_minutes',
                'appointments' => fn ($query) => $query
                    ->with([
                        'appointment:id,technician_id,service_id,starts_at,ends_at',
                        'appointment.service:id,type,name',
                        'appointment.technician:id,first_name,last_name,department_code,role',
                        'appointment.technician.departments:code',
                        'service:id,type,name,average_duration_minutes',
                    ])
                    ->where(function ($query) use ($placeableStatus): void {
                        $query
                            ->where(function ($query) use ($placeableStatus): void {
                                $query
                                    ->whereNull('appointment_id')
                                    ->whereIn('status', $placeableStatus)
                                    ->where('excluded_from_lot_stats', false);
                            })
                            ->orWhere(function ($query): void {
                                $query
                                    ->where('excluded_from_lot_stats', false)
                                    ->where(function ($query): void {
                                        $query
                                            ->orWhereNotNull('appointment_id')
                                            ->orWhere('status', LotAppointment::STATUS_PLACED)
                                            ->orWhere('status', LotAppointment::STATUS_CONTACT_PROCESSED);
                                    });
                            });
                    })
                    ->orderByRaw('CASE WHEN `row_number` IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('row_number')
                    ->orderBy('customer_name'),
            ])
            ->whereHas('appointments', fn ($query) => $query
                ->whereNull('appointment_id')
                ->whereIn('status', $placeableStatus)
                ->where('excluded_from_lot_stats', false))
            ->where('status', '!=', Lot::STATUS_COMPLETED)
            ->latest()
            ->limit(50)
            ->get()
            ->map(function (Lot $lot) use ($autoCompletion): array {
                $statsAppointments = $lot->appointments->reject(fn (LotAppointment $appointment): bool => $this->isExcludedFromLotStats($appointment));
                $physicalProcessedAppointments = $statsAppointments->filter(fn (LotAppointment $appointment): bool => $this->isPhysicalProcessedLotAppointment($appointment));
                $placeableAppointments = $statsAppointments->filter(fn (LotAppointment $appointment): bool => $this->isPlaceableLotAppointment($appointment));
                $contactProcessedAppointments = $statsAppointments->filter(fn (LotAppointment $appointment): bool => $this->isContactProcessedLotAppointment($appointment));
                $status = $lot->status ?: Lot::STATUS_NOT_STARTED;
                $statusMeta = $this->lotStatusMeta($status);
                $autoCompletionData = $autoCompletion->calculate($lot, $statsAppointments);
                $physicalDefaultTarget = $lot->supportsPhysicalProcessing()
                    ? (int) data_get($autoCompletionData, 'physical.target_count', $lot->appointments->count())
                    : 0;
                $contactDefaultTarget = $lot->supportsContactProcessing()
                    ? (int) data_get($autoCompletionData, 'contact.target_count', $lot->appointments->count())
                    : 0;
                $physicalTarget = (int) ($lot->physical_appointment_target_count ?? $physicalDefaultTarget);
                $contactTarget = (int) ($lot->contact_appointment_target_count ?? $contactDefaultTarget);

                return [
                    'id' => $lot->id,
                    'title' => $lot->name,
                    'type_label' => $lot->typeLabel(),
                    'service_id' => $lot->service_id,
                    'service_label' => $lot->service
                        ? $lot->service->type.' - '.$lot->service->name
                        : null,
                    'status_label' => Lot::statuses()[$status] ?? Lot::statuses()[Lot::STATUS_NOT_STARTED],
                    'status_color' => $statusMeta['color'],
                    'status_background' => $statusMeta['background'],
                    'imported_at' => $lot->imported_at,
                    'auto_completion' => $autoCompletionData,
                    'appointment_targets' => [
                        'physical' => [
                            'enabled' => $lot->supportsPhysicalProcessing(),
                            'completed_count' => $physicalProcessedAppointments->count(),
                            'target_count' => max(0, $physicalTarget),
                            'default_target_count' => max(0, $physicalDefaultTarget),
                            'remaining_count' => max(0, $physicalTarget - $physicalProcessedAppointments->count()),
                            'percentage' => $physicalTarget > 0
                                ? (int) min(100, round(($physicalProcessedAppointments->count() / $physicalTarget) * 100))
                                : 0,
                            'is_manual' => $lot->physical_appointment_target_count !== null,
                        ],
                        'contact' => [
                            'enabled' => $lot->supportsContactProcessing(),
                            'completed_count' => $contactProcessedAppointments->count(),
                            'target_count' => max(0, $contactTarget),
                            'default_target_count' => max(0, $contactDefaultTarget),
                            'remaining_count' => max(0, $contactTarget - $contactProcessedAppointments->count()),
                            'percentage' => $contactTarget > 0
                                ? (int) min(100, round(($contactProcessedAppointments->count() / $contactTarget) * 100))
                                : 0,
                            'is_manual' => $lot->contact_appointment_target_count !== null,
                        ],
                    ],
                    'appointments_count' => $lot->appointments->count(),
                    'placeable_count' => $placeableAppointments->count(),
                    'target_remaining_count' => $autoCompletionData['remaining_count'] ?? $placeableAppointments->count(),
                    'target_count' => $autoCompletionData['target_count'] ?? $lot->appointments->count(),
                    'completed_target_count' => $autoCompletionData['completed_count'] ?? ($physicalProcessedAppointments->count() + $contactProcessedAppointments->count()),
                    'placed_count' => $physicalProcessedAppointments->count(),
                    'contact_processed_count' => $contactProcessedAppointments->count(),
                    'supports_physical' => $lot->supportsPhysicalProcessing(),
                    'supports_contact' => $lot->supportsContactProcessing(),
                    'is_hybrid' => $lot->isHybrid(),
                    'departments' => $lot->appointments->pluck('department_code')->filter()->unique()->sort()->values(),
                    'appointments' => $lot->appointments->map(fn (LotAppointment $appointment): array => [
                        'id' => $appointment->id,
                        'customer_name' => $appointment->customer_name,
                        'company_name' => $appointment->company_name,
                        'site_name' => $appointment->site_name,
                        'customer_phone' => $appointment->customer_phone,
                        'address' => $appointment->address,
                        'postal_code' => $appointment->postal_code ?: ($appointment->raw_payload['postal_code'] ?? null),
                        'city' => $appointment->city ?: ($appointment->raw_payload['city'] ?? null),
                        'department_code' => $appointment->department_code,
                        'row_number' => $appointment->row_number,
                        'external_reference' => $appointment->external_reference,
                        'service_id' => $appointment->service_id ?: $lot->service_id,
                        'service_label' => $appointment->service
                            ? $appointment->service->type.' - '.$appointment->service->name
                            : ($lot->service ? $lot->service->type.' - '.$lot->service->name : null),
                        'status' => $appointment->status,
                        'status_label' => $appointment->statusLabel(),
                        'appointment_id' => $appointment->appointment_id,
                        'is_placed' => $this->isPlacedLotAppointment($appointment),
                        'is_contact_processed' => $this->isContactProcessedLotAppointment($appointment),
                        'excluded_from_lot_stats' => $this->isExcludedFromLotStats($appointment),
                        'contact_satisfaction' => $appointment->contact_satisfaction,
                        'contact_comment' => $appointment->contact_comment,
                        'contact_processed_at' => $appointment->contact_processed_at,
                        'placed_at' => $appointment->appointment?->starts_at,
                        'placed_technician_name' => $appointment->appointment?->technician?->full_name_with_departments,
                        'placed_service_label' => $appointment->appointment?->service
                            ? $appointment->appointment->service->type.' - '.$appointment->appointment->service->name
                            : null,
                        'tracking_url' => $this->trackingUrlForLotAppointment($appointment, 'planner.tracking'),
                        'can_contact' => $this->isPlaceableLotAppointment($appointment) && $lot->supportsContactProcessing(),
                        'contact_url' => route('planner.book.lots.appointments.contact', $appointment),
                        'can_search' => $this->isPlaceableLotAppointment($appointment)
                            && $lot->supportsPhysicalProcessing()
                            && ($appointment->service_id !== null || $lot->service_id !== null)
                            && filled($appointment->address)
                            && filled($appointment->department_code)
                            && $appointment->latitude !== null
                            && $appointment->longitude !== null,
                    ])->values(),
                ];
            })
            ->values();
    }

    /**
     * @return array{color:string,background:string}
     */
    private function lotStatusMeta(string $status): array
    {
        return match ($status) {
            Lot::STATUS_IN_PROGRESS => ['color' => '#1d4ed8', 'background' => '#dbeafe'],
            Lot::STATUS_COMPLETED => ['color' => '#15803d', 'background' => '#dcfce7'],
            default => ['color' => '#b45309', 'background' => '#fef3c7'],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lotAppointmentFromId(int $id, ?int $serviceId = null): ?array
    {
        $lotAppointment = LotAppointment::query()
            ->with([
                'lot:id,name,type,status,delegataire,service_id',
                'lot.service:id,type,name,average_duration_minutes',
                'service:id,type,name,average_duration_minutes',
            ])
            ->whereNull('appointment_id')
            ->whereKey($id)
            ->first();

        if (! $lotAppointment
            || ! $lotAppointment->lot?->supportsPhysicalProcessing()
            || $this->isExcludedFromLotStats($lotAppointment)
            || $this->isContactProcessedLotAppointment($lotAppointment)
            || ! filled($lotAppointment->address)
            || ! filled($lotAppointment->department_code)) {
            return null;
        }

        if ($lotAppointment->latitude === null || $lotAppointment->longitude === null) {
            return null;
        }

        [$firstName, $lastName] = $this->splitCustomerName($lotAppointment);
        $service = $lotAppointment->service
            ?: $lotAppointment->lot?->service
            ?: ($serviceId ? Service::query()->find($serviceId) : null);

        return [
            'id' => 'lot-'.$lotAppointment->id,
            'lot_appointment_id' => $lotAppointment->id,
            'source' => $lotAppointment->lot ? 'Lot - '.$lotAppointment->lot->name : 'Lot',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'customer_name' => $this->appointmentRequestDisplayName([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company_name' => $lotAppointment->company_name,
                'site_name' => $lotAppointment->site_name,
                'is_lot' => true,
            ]),
            'company_name' => $lotAppointment->company_name,
            'site_name' => $lotAppointment->site_name,
            'phone' => $lotAppointment->customer_phone,
            'address' => $lotAppointment->address,
            'postal_code' => $lotAppointment->postal_code,
            'city' => $lotAppointment->city,
            'department_code' => strtoupper((string) $lotAppointment->department_code),
            'latitude' => (float) $lotAppointment->latitude,
            'longitude' => (float) $lotAppointment->longitude,
            'preferred_starts_at' => null,
            'is_manual' => false,
            'is_lot' => true,
            'external_payload' => [
                'source_type' => 'lot',
                'lot_id' => $lotAppointment->lot_id,
                'lot_name' => $lotAppointment->lot?->name,
                'lot_type' => $lotAppointment->lot?->type,
                'lot_delegataire' => $lotAppointment->lot?->delegataire,
                'lot_global_plus' => (bool) $lotAppointment->added_to_global_plus,
                'lot_appointment_id' => $lotAppointment->id,
                'row_number' => $lotAppointment->row_number,
                'company_name' => $lotAppointment->company_name,
                'site_name' => $lotAppointment->site_name,
                'raw_payload' => $lotAppointment->raw_payload,
            ],
            'service' => $service ? [
                'id' => $service->id,
                'type' => $service->type,
                'name' => $service->name,
                'average_duration_minutes' => $service->average_duration_minutes,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replacementAppointmentFromId(int $id): ?array
    {
        $appointment = Appointment::query()
            ->with(['service:id,type,name,average_duration_minutes'])
            ->whereKey($id)
            ->first();

        if (! $appointment || ! filled($appointment->address)) {
            return null;
        }

        if ($appointment->latitude === null || $appointment->longitude === null) {
            return null;
        }

        $externalPayload = is_array($appointment->external_payload) ? $appointment->external_payload : [];
        $companyName = data_get($externalPayload, 'company_name') ?: data_get($externalPayload, 'client.company_name');
        $siteName = data_get($externalPayload, 'site_name') ?: data_get($externalPayload, 'client.site_name');
        $departmentCode = $this->departmentCodeFromAddress($appointment->address);

        return [
            'id' => 'replace-'.$appointment->id,
            'replace_appointment_id' => $appointment->id,
            'source' => 'RDV à replacer',
            'first_name' => $appointment->customer_first_name,
            'last_name' => $appointment->customer_last_name,
            'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
            'company_name' => $companyName,
            'site_name' => $siteName,
            'phone' => $appointment->customer_phone,
            'address' => $appointment->address,
            'department_code' => $departmentCode,
            'latitude' => (float) $appointment->latitude,
            'longitude' => (float) $appointment->longitude,
            'preferred_starts_at' => null,
            'is_manual' => false,
            'is_lot' => false,
            'is_replacement' => true,
            'comment' => $appointment->comment,
            'external_source' => $appointment->external_source,
            'external_reference' => $appointment->external_reference,
            'external_payload' => $externalPayload,
            'documents' => app(AppointmentDocumentSerializer::class)->forAppointment($appointment),
            'comments' => $this->externalComments($appointment),
            'service' => $appointment->service ? [
                'id' => $appointment->service->id,
                'type' => $appointment->service->type,
                'name' => $appointment->service->name,
                'average_duration_minutes' => $appointment->service->average_duration_minutes,
            ] : null,
        ];
    }

    private function departmentCodeFromAddress(?string $address): string
    {
        preg_match('/\b(\d{2})\d{3}\b/u', (string) $address, $matches);

        return strtoupper($matches[1] ?? '');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitCustomerName(LotAppointment $lotAppointment): array
    {
        $firstName = trim((string) $lotAppointment->customer_first_name);
        $lastName = trim((string) $lotAppointment->customer_last_name);

        if ($firstName !== '' || $lastName !== '') {
            return [$firstName, $lastName];
        }

        $companyName = trim((string) $lotAppointment->company_name);
        $siteName = trim((string) $lotAppointment->site_name);

        if ($companyName !== '') {
            return [$companyName, $siteName];
        }

        if ($siteName !== '') {
            return [$siteName, ''];
        }

        $parts = preg_split('/\s+/', trim($lotAppointment->customer_name), 2) ?: [];

        return [
            $parts[0] ?? 'Client',
            $parts[1] ?? 'Lot',
        ];
    }

    /**
     * @param  array<string, mixed>  $appointment
     */
    private function appointmentRequestDisplayName(array $appointment): string
    {
        $companyName = trim((string) ($appointment['company_name'] ?? ''));

        if (($appointment['is_lot'] ?? false) && $companyName !== '') {
            return $companyName;
        }

        $individualName = trim(implode(' ', array_filter([
            trim((string) ($appointment['first_name'] ?? '')),
            trim((string) ($appointment['last_name'] ?? '')),
        ])));

        if ($individualName !== '') {
            return $individualName;
        }

        if ($companyName !== '') {
            return $companyName;
        }

        $siteName = trim((string) ($appointment['site_name'] ?? ''));

        return $siteName !== '' ? $siteName : 'Client à qualifier';
    }

    private function refreshLotStatus(?Lot $lot): void
    {
        if (! $lot) {
            return;
        }

        $baseQuery = $lot->appointments()
            ->where('excluded_from_lot_stats', false);

        $totalAppointments = (clone $baseQuery)->count();
        $completedAppointments = (clone $baseQuery)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('appointment_id')
                    ->orWhere('status', LotAppointment::STATUS_PLACED)
                    ->orWhere('status', LotAppointment::STATUS_CONTACT_PROCESSED)
                    ->orWhereNotNull('contact_satisfaction');
            })
            ->count();

        $status = match (true) {
            $totalAppointments > 0 && $completedAppointments >= $totalAppointments => Lot::STATUS_COMPLETED,
            $completedAppointments > 0 => Lot::STATUS_IN_PROGRESS,
            default => Lot::STATUS_NOT_STARTED,
        };

        if ($lot->status !== $status) {
            $lot->update(['status' => $status]);
        }
    }

    private function forgetRouteMetricsForAppointmentChange(
        ?int $previousTechnicianId,
        int $newTechnicianId,
        ?string $previousDate,
        ?string $newDate
    ): void {
        $technicianIds = collect([$previousTechnicianId, $newTechnicianId])
            ->filter()
            ->map(fn (int $id): int => $id)
            ->unique()
            ->values();

        $serviceDates = collect([$previousDate, $newDate])
            ->filter()
            ->unique()
            ->values();

        if ($technicianIds->isEmpty() || $serviceDates->isEmpty()) {
            return;
        }

        TechnicianDailyRouteMetric::query()
            ->whereIn('technician_id', $technicianIds->all())
            ->whereIn('service_date', $serviceDates->all())
            ->delete();
    }

    private function technicianSupportsService(int $technicianId, int $serviceId): bool
    {
        return User::query()
            ->whereKey($technicianId)
            ->where('role', 2)
            ->where('admin', false)
            ->whereHas('services', fn ($query) => $query->where('services.id', $serviceId))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $manualAppointment
     * @return array<string, mixed>|null
     */
    private function manualAppointmentFromPayload(array $manualAppointment): ?array
    {
        $service = Service::query()->find($manualAppointment['service_id'] ?? null);

        if (! $service) {
            return null;
        }

        $normalizedPayload = [
            'first_name' => trim((string) $manualAppointment['first_name']),
            'last_name' => trim((string) $manualAppointment['last_name']),
            'phone' => trim((string) $manualAppointment['phone']),
            'address' => trim((string) $manualAppointment['address']),
            'department_code' => strtoupper(trim((string) $manualAppointment['department_code'])),
            'latitude' => (float) $manualAppointment['latitude'],
            'longitude' => (float) $manualAppointment['longitude'],
            'service_id' => (int) $service->id,
        ];

        return [
            'id' => 'manual-'.hash('sha1', json_encode($normalizedPayload, JSON_THROW_ON_ERROR)),
            'source' => 'RDV manuel',
            'first_name' => $normalizedPayload['first_name'],
            'last_name' => $normalizedPayload['last_name'],
            'phone' => $normalizedPayload['phone'],
            'address' => $normalizedPayload['address'],
            'department_code' => $normalizedPayload['department_code'],
            'latitude' => $normalizedPayload['latitude'],
            'longitude' => $normalizedPayload['longitude'],
            'preferred_starts_at' => null,
            'is_manual' => true,
            'service' => [
                'id' => $service->id,
                'type' => $service->type,
                'name' => $service->name,
                'average_duration_minutes' => $service->average_duration_minutes,
            ],
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, array<string, mixed>>
     */
    private function calendarEvents(Collection $appointments, ?int $replacementAppointmentId = null): Collection
    {
        $appointmentsByTechnicianAndDay = $appointments
            ->groupBy(fn (Appointment $appointment): string => $appointment->technician_id.'|'.$appointment->starts_at?->toDateString());
        $documentsByAppointment = app(AppointmentDocumentSerializer::class)->forAppointments($appointments);

        return $appointments->map(function (Appointment $appointment) use ($appointmentsByTechnicianAndDay, $documentsByAppointment, $replacementAppointmentId): array {
            $serviceLabel = $appointment->service
                ? $appointment->service->type.' - '.$appointment->service->name
                : 'Prestation';
            $isReplacementTarget = $replacementAppointmentId !== null && (int) $appointment->id === $replacementAppointmentId;
            $sameDayAppointments = $appointmentsByTechnicianAndDay
                ->get($appointment->technician_id.'|'.$appointment->starts_at?->toDateString(), collect())
                ->sortBy('starts_at')
                ->values();
            $previousAppointment = $sameDayAppointments
                ->filter(fn (Appointment $candidate): bool => $candidate->starts_at?->lt($appointment->starts_at))
                ->last();
            $originLat = $previousAppointment ? (float) $previousAppointment->latitude : (float) $appointment->technician?->latitude;
            $originLng = $previousAppointment ? (float) $previousAppointment->longitude : (float) $appointment->technician?->longitude;
            $externalPayload = is_array($appointment->external_payload) ? $appointment->external_payload : [];

            return [
                'id' => $appointment->id,
                'title' => $appointment->technician?->full_name_with_departments.' | '.$serviceLabel,
                'start' => $appointment->starts_at?->toIso8601String(),
                'end' => $appointment->ends_at?->toIso8601String(),
                'backgroundColor' => $appointment->status === Appointment::STATUS_PROBLEM ? '#fff7ed' : '#9ccfe3',
                'borderColor' => $isReplacementTarget ? '#faff00' : ($appointment->status === Appointment::STATUS_PROBLEM ? '#f97316' : '#31424c'),
                'textColor' => $appointment->status === Appointment::STATUS_PROBLEM ? '#9a3412' : '#31424c',
                'classNames' => $isReplacementTarget ? ['appointment-replacement-target'] : [],
                'extendedProps' => [
                    'technician_id' => $appointment->technician_id,
                    'technician_name' => $appointment->technician?->full_name_with_departments,
                    'technician_address' => $appointment->technician?->address,
                    'technician_latitude' => $appointment->technician?->latitude ? (float) $appointment->technician->latitude : null,
                    'technician_longitude' => $appointment->technician?->longitude ? (float) $appointment->technician->longitude : null,
                    'service_label' => $serviceLabel,
                    'service_id' => $appointment->service_id,
                    'is_replacement_target' => $isReplacementTarget,
                    'external_source' => $appointment->external_source,
                    'external_reference' => $appointment->external_reference,
                    'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                    'customer_phone' => $appointment->customer_phone,
                    'address' => $appointment->address,
                    'latitude' => (float) $appointment->latitude,
                    'longitude' => (float) $appointment->longitude,
                    'duration_minutes' => (int) $appointment->duration_minutes,
                    'comment' => $appointment->comment,
                    'status' => $appointment->status,
                    'problem_reported_at' => $appointment->problem_reported_at?->toIso8601String(),
                    'problem_type' => data_get($externalPayload, 'problem_type') ?: data_get($externalPayload, 'techcalendar_problem.problem_type'),
                    'problem_comment' => data_get($externalPayload, 'problem_comment') ?: data_get($externalPayload, 'techcalendar_problem.comment'),
                    'recall_date' => data_get($externalPayload, 'recall_date') ?: data_get($externalPayload, 'techcalendar_problem.recall_date'),
                    'recall_time' => data_get($externalPayload, 'recall_time') ?: data_get($externalPayload, 'techcalendar_problem.recall_time'),
                    'recall_at' => data_get($externalPayload, 'recall_at') ?: data_get($externalPayload, 'date_rappel'),
                    'origin_label' => $previousAppointment ? 'rdv précédent' : 'domicile',
                    'origin_latitude' => $originLat,
                    'origin_longitude' => $originLng,
                    'origin_name' => $previousAppointment
                        ? trim($previousAppointment->customer_first_name.' '.$previousAppointment->customer_last_name)
                        : 'Domicile',
                    'comments' => $this->externalComments($appointment),
                    'documents' => $documentsByAppointment[$appointment->id] ?? [],
                ],
            ];
        })->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function externalComments(Appointment $appointment): array
    {
        $comments = data_get($appointment->external_payload, 'comments', []);

        return is_array($comments) ? array_values($comments) : [];
    }

    /**
     * @param  Collection<int, User>  $technicians
     * @param  Collection<int, Appointment>  $appointments
     * @param  array<string, mixed>  $crmAppointment
     * @return array<int, array<string, mixed>>
     */
    private function buildSlotSuggestions(
        Collection $technicians,
        Collection $appointments,
        array $crmAppointment,
        MapboxDrivingRouteService $drivingRoutes,
        ?Carbon $windowStart = null,
        ?Carbon $windowEnd = null,
    ): array {
        $durationMinutes = (int) ($crmAppointment['service']['average_duration_minutes'] ?? 60);
        $durationMinutes = max(30, $durationMinutes);
        $preferredStartsAt = $this->preferredStartsAt($crmAppointment);

        if ($preferredStartsAt) {
            if ($windowStart && $windowEnd && ! $preferredStartsAt->betweenIncluded($windowStart, $windowEnd)) {
                return [];
            }

            return $this->buildPreferredSlotSuggestions(
                $technicians,
                $appointments,
                $crmAppointment,
                $durationMinutes,
                $preferredStartsAt,
                $drivingRoutes
            );
        }

        $startDate = $windowStart?->copy()->startOfDay() ?? now()->copy()->startOfDay();
        $endDate = $windowEnd?->copy() ?? $startDate->copy()->addWeeks(2);
        $today = now()->copy()->startOfDay();

        if ($startDate->lt($today)) {
            $startDate = $today;
        }

        $days = collect();

        for ($date = $startDate->copy(); $date->lt($endDate); $date->addDay()) {
            if ($this->isBookableDay($date)) {
                $days->push($date->copy());
            }
        }

        $appointmentsByTechnician = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->starts_at?->betweenIncluded($startDate, $endDate))
            ->groupBy('technician_id');

        return $technicians
            ->flatMap(function (User $technician) use ($days, $appointmentsByTechnician, $crmAppointment, $durationMinutes, $drivingRoutes): array {
                $dailySuggestions = [];

                foreach ($days as $date) {
                    $dayAppointments = $appointmentsByTechnician
                        ->get($technician->id, collect())
                        ->filter(fn (Appointment $appointment): bool => $appointment->starts_at?->isSameDay($date))
                        ->sortBy('starts_at')
                        ->values();
                    $suggestions = $this->suggestSlotsForDay(
                        $technician,
                        $dayAppointments,
                        $crmAppointment,
                        $durationMinutes,
                        $date,
                        $drivingRoutes
                    );

                    array_push($dailySuggestions, ...$suggestions);
                }

                return $dailySuggestions;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $crmAppointment
     */
    private function preferredStartsAt(array $crmAppointment): ?Carbon
    {
        if (empty($crmAppointment['preferred_starts_at'])) {
            return null;
        }

        return Carbon::parse($crmAppointment['preferred_starts_at']);
    }

    /**
     * @param  Collection<int, User>  $technicians
     * @param  Collection<int, Appointment>  $appointments
     * @param  array<string, mixed>  $crmAppointment
     * @return array<int, array<string, mixed>>
     */
    private function buildPreferredSlotSuggestions(
        Collection $technicians,
        Collection $appointments,
        array $crmAppointment,
        int $durationMinutes,
        Carbon $preferredStartsAt,
        MapboxDrivingRouteService $drivingRoutes
    ): array {
        if (! $this->isBookableDay($preferredStartsAt) || $preferredStartsAt->lt(now())) {
            return [];
        }

        $appointmentsByTechnician = $appointments
            ->filter(fn (Appointment $appointment): bool => (bool) $appointment->starts_at?->isSameDay($preferredStartsAt))
            ->groupBy('technician_id');

        return $technicians
            ->map(fn (User $technician): ?array => $this->suggestPreferredSlotForDay(
                $technician,
                $appointmentsByTechnician
                    ->get($technician->id, collect())
                    ->sortBy('starts_at')
                    ->values(),
                $crmAppointment,
                $durationMinutes,
                $preferredStartsAt,
                $drivingRoutes
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Appointment>  $dayAppointments
     * @param  array<string, mixed>  $crmAppointment
     * @return array<int, array<string, mixed>>
     */
    private function suggestSlotsForDay(
        User $technician,
        Collection $dayAppointments,
        array $crmAppointment,
        int $durationMinutes,
        Carbon $date,
        MapboxDrivingRouteService $drivingRoutes
    ): array {
        $dayStart = Carbon::parse($date->format('Y-m-d').' '.($technician->day_start_time ?: '08:00'));
        $dayEnd = Carbon::parse($date->format('Y-m-d').' '.($technician->day_end_time ?: '17:00'));

        if ($this->technicianHasLoadedAbsenceOverlap($technician, $dayStart, $dayEnd)) {
            return [];
        }

        if ($date->isSameDay(now()) && $dayStart->lt(now())) {
            $dayStart = now()->copy()->addMinutes(15);
        }

        if ($dayStart->gte($dayEnd)) {
            return [];
        }

        $destination = [
            'lat' => (float) $crmAppointment['latitude'],
            'lng' => (float) $crmAppointment['longitude'],
            'label' => $this->appointmentRequestDisplayName($crmAppointment),
        ];
        $home = [
            'lat' => (float) $technician->latitude,
            'lng' => (float) $technician->longitude,
            'label' => 'Domicile',
        ];

        if ($dayAppointments->isEmpty()) {
            $suggestion = $this->buildEarliestSuggestionInGap(
                technician: $technician,
                dayAppointments: $dayAppointments,
                crmAppointment: $crmAppointment,
                date: $date,
                originAvailableAt: $dayStart,
                origin: $home,
                previousAppointment: null,
                nextAppointment: null,
                dayStart: $dayStart,
                dayEnd: $dayEnd,
                destination: $destination,
                durationMinutes: $durationMinutes,
                drivingRoutes: $drivingRoutes,
                originLabel: 'domicile',
            );

            return $suggestion ? [$suggestion] : [];
        }

        $suggestions = [];

        foreach ($dayAppointments as $index => $nextAppointment) {
            $previousAppointment = $index > 0 ? $dayAppointments->get($index - 1) : null;
            $origin = $previousAppointment ? $this->appointmentRoutePoint($previousAppointment) : $home;
            $suggestion = $this->buildLatestSuggestionInGap(
                technician: $technician,
                dayAppointments: $dayAppointments,
                crmAppointment: $crmAppointment,
                date: $date,
                originAvailableAt: $previousAppointment?->ends_at ?: $dayStart,
                origin: $origin,
                previousAppointment: $previousAppointment,
                nextAppointment: $nextAppointment,
                dayStart: $dayStart,
                dayEnd: $dayEnd,
                destination: $destination,
                durationMinutes: $durationMinutes,
                drivingRoutes: $drivingRoutes,
                originLabel: $previousAppointment ? 'rdv précédent' : 'domicile',
            );

            if ($suggestion) {
                $suggestions[] = $suggestion;
            }
        }

        foreach ($dayAppointments as $index => $previousAppointment) {
            $nextAppointment = $dayAppointments->get($index + 1);
            $suggestion = $this->buildEarliestSuggestionInGap(
                technician: $technician,
                dayAppointments: $dayAppointments,
                crmAppointment: $crmAppointment,
                date: $date,
                originAvailableAt: $previousAppointment->ends_at,
                origin: $this->appointmentRoutePoint($previousAppointment),
                previousAppointment: $previousAppointment,
                nextAppointment: $nextAppointment,
                dayStart: $dayStart,
                dayEnd: $dayEnd,
                destination: $destination,
                durationMinutes: $durationMinutes,
                drivingRoutes: $drivingRoutes,
                originLabel: 'rdv précédent',
            );

            if ($suggestion) {
                $suggestions[] = $suggestion;
            }
        }

        return collect($suggestions)
            ->unique(fn (array $suggestion): string => $suggestion['id'])
            ->sortBy('start')
            ->values()
            ->all();
    }

    /**
     * @return array{lat: float, lng: float, label: string}
     */
    private function appointmentRoutePoint(Appointment $appointment): array
    {
        return [
            'lat' => (float) $appointment->latitude,
            'lng' => (float) $appointment->longitude,
            'label' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $dayAppointments
     * @param  array<string, mixed>  $crmAppointment
     * @param  array{lat: float, lng: float, label: string}  $origin
     * @param  array{lat: float, lng: float, label: string}  $destination
     * @return array<string, mixed>|null
     */
    private function buildEarliestSuggestionInGap(
        User $technician,
        Collection $dayAppointments,
        array $crmAppointment,
        Carbon $date,
        Carbon $originAvailableAt,
        array $origin,
        ?Appointment $previousAppointment,
        ?Appointment $nextAppointment,
        Carbon $dayStart,
        Carbon $dayEnd,
        array $destination,
        int $durationMinutes,
        MapboxDrivingRouteService $drivingRoutes,
        string $originLabel,
    ): ?array {
        $travelTo = $drivingRoutes->estimate($origin['lat'], $origin['lng'], $destination['lat'], $destination['lng']);
        $travelAfter = $this->travelAfterSuggestion($technician, $destination, $nextAppointment, $drivingRoutes);
        $earliestStart = $this->roundUpToNextHalfHour(
            $originAvailableAt->copy()
                ->addMinutes((int) $travelTo['duration_minutes'])
                ->addMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES)
        );
        $latestEnd = $this->latestSuggestionEnd($dayEnd, $nextAppointment, $travelAfter);
        $latestStart = $this->roundDownToPreviousHalfHour($latestEnd->copy()->subMinutes($durationMinutes));

        for ($startsAt = $earliestStart->copy(); $startsAt->lte($latestStart); $startsAt->addMinutes(30)) {
            $suggestion = $this->buildSuggestionAt(
                technician: $technician,
                dayAppointments: $dayAppointments,
                crmAppointment: $crmAppointment,
                date: $date,
                startsAt: $startsAt,
                originAvailableAt: $originAvailableAt,
                origin: $origin,
                previousAppointment: $previousAppointment,
                nextAppointment: $nextAppointment,
                dayStart: $dayStart,
                dayEnd: $dayEnd,
                destination: $destination,
                durationMinutes: $durationMinutes,
                drivingRoutes: $drivingRoutes,
                originLabel: $originLabel,
                travelTo: $travelTo,
                travelAfter: $travelAfter,
            );

            if ($suggestion) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Appointment>  $dayAppointments
     * @param  array<string, mixed>  $crmAppointment
     * @param  array{lat: float, lng: float, label: string}  $origin
     * @param  array{lat: float, lng: float, label: string}  $destination
     * @return array<string, mixed>|null
     */
    private function buildLatestSuggestionInGap(
        User $technician,
        Collection $dayAppointments,
        array $crmAppointment,
        Carbon $date,
        Carbon $originAvailableAt,
        array $origin,
        ?Appointment $previousAppointment,
        ?Appointment $nextAppointment,
        Carbon $dayStart,
        Carbon $dayEnd,
        array $destination,
        int $durationMinutes,
        MapboxDrivingRouteService $drivingRoutes,
        string $originLabel,
    ): ?array {
        $travelTo = $drivingRoutes->estimate($origin['lat'], $origin['lng'], $destination['lat'], $destination['lng']);
        $travelAfter = $this->travelAfterSuggestion($technician, $destination, $nextAppointment, $drivingRoutes);
        $earliestStart = $this->roundUpToNextHalfHour(
            $originAvailableAt->copy()
                ->addMinutes((int) $travelTo['duration_minutes'])
                ->addMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES)
        );
        $latestEnd = $this->latestSuggestionEnd($dayEnd, $nextAppointment, $travelAfter);
        $latestStart = $this->roundDownToPreviousHalfHour($latestEnd->copy()->subMinutes($durationMinutes));

        for ($startsAt = $latestStart->copy(); $startsAt->gte($earliestStart); $startsAt->subMinutes(30)) {
            $suggestion = $this->buildSuggestionAt(
                technician: $technician,
                dayAppointments: $dayAppointments,
                crmAppointment: $crmAppointment,
                date: $date,
                startsAt: $startsAt,
                originAvailableAt: $originAvailableAt,
                origin: $origin,
                previousAppointment: $previousAppointment,
                nextAppointment: $nextAppointment,
                dayStart: $dayStart,
                dayEnd: $dayEnd,
                destination: $destination,
                durationMinutes: $durationMinutes,
                drivingRoutes: $drivingRoutes,
                originLabel: $originLabel,
                travelTo: $travelTo,
                travelAfter: $travelAfter,
            );

            if ($suggestion) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $travelAfter
     */
    private function latestSuggestionEnd(Carbon $dayEnd, ?Appointment $nextAppointment, array $travelAfter): Carbon
    {
        $boundary = $nextAppointment?->starts_at ?: $dayEnd;

        return $boundary->copy()
            ->subMinutes((int) $travelAfter['duration_minutes'])
            ->subMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES);
    }

    /**
     * @param  array{lat: float, lng: float, label: string}  $destination
     * @return array<string, mixed>
     */
    private function travelAfterSuggestion(
        User $technician,
        array $destination,
        ?Appointment $nextAppointment,
        MapboxDrivingRouteService $drivingRoutes
    ): array {
        if ($nextAppointment) {
            return $drivingRoutes->estimate(
                $destination['lat'],
                $destination['lng'],
                (float) $nextAppointment->latitude,
                (float) $nextAppointment->longitude
            );
        }

        return $drivingRoutes->estimate(
            $destination['lat'],
            $destination['lng'],
            (float) $technician->latitude,
            (float) $technician->longitude
        );
    }

    /**
     * @param  Collection<int, Appointment>  $dayAppointments
     * @param  array<string, mixed>  $crmAppointment
     * @param  array{lat: float, lng: float, label: string}  $origin
     * @param  array{lat: float, lng: float, label: string}  $destination
     * @param  array<string, mixed>  $travelTo
     * @param  array<string, mixed>  $travelAfter
     * @return array<string, mixed>|null
     */
    private function buildSuggestionAt(
        User $technician,
        Collection $dayAppointments,
        array $crmAppointment,
        Carbon $date,
        Carbon $startsAt,
        Carbon $originAvailableAt,
        array $origin,
        ?Appointment $previousAppointment,
        ?Appointment $nextAppointment,
        Carbon $dayStart,
        Carbon $dayEnd,
        array $destination,
        int $durationMinutes,
        MapboxDrivingRouteService $drivingRoutes,
        string $originLabel,
        array $travelTo,
        array $travelAfter,
    ): ?array {
        $startsAt = $startsAt->copy();
        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);
        $earliestStart = $originAvailableAt->copy()
            ->addMinutes((int) $travelTo['duration_minutes'])
            ->addMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES);
        $latestEnd = $this->latestSuggestionEnd($dayEnd, $nextAppointment, $travelAfter);

        if ($startsAt->lt($dayStart) || $startsAt->lt($earliestStart) || $endsAt->gt($latestEnd)) {
            return null;
        }

        if ($this->technicianHasLoadedAbsenceOverlap($technician, $startsAt, $endsAt)) {
            return null;
        }

        if (! $this->technicianKeepsLunchBreak(
            $technician,
            $dayAppointments,
            $dayStart,
            $dayEnd,
            $startsAt,
            $endsAt,
            $destination,
            $drivingRoutes
        )) {
            return null;
        }

        return $this->suggestionPayload(
            technician: $technician,
            crmAppointment: $crmAppointment,
            date: $date,
            startsAt: $startsAt,
            durationMinutes: $durationMinutes,
            origin: $origin,
            originLabel: $originLabel,
            travelTo: $travelTo,
            travelAfter: $travelAfter,
            previousAppointment: $previousAppointment,
            nextAppointment: $nextAppointment,
            drivingRoutes: $drivingRoutes,
        );
    }

    /**
     * @param  Collection<int, Appointment>  $dayAppointments
     * @param  array<string, mixed>  $crmAppointment
     * @return array<string, mixed>|null
     */
    private function suggestPreferredSlotForDay(
        User $technician,
        Collection $dayAppointments,
        array $crmAppointment,
        int $durationMinutes,
        Carbon $preferredStartsAt,
        MapboxDrivingRouteService $drivingRoutes
    ): ?array {
        $dayStart = Carbon::parse($preferredStartsAt->format('Y-m-d').' '.($technician->day_start_time ?: '08:00'));
        $dayEnd = Carbon::parse($preferredStartsAt->format('Y-m-d').' '.($technician->day_end_time ?: '17:00'));
        $startsAt = $this->roundUpToNextHalfHour($preferredStartsAt);
        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

        if ($this->technicianHasLoadedAbsenceOverlap($technician, $startsAt, $endsAt)) {
            return null;
        }

        if ($startsAt->lt($dayStart) || $endsAt->gt($dayEnd)) {
            return null;
        }

        $previousAppointment = $dayAppointments
            ->filter(fn (Appointment $appointment): bool => (bool) $appointment->starts_at?->lt($startsAt))
            ->last();

        if ($previousAppointment && $previousAppointment->ends_at?->gt($startsAt)) {
            return null;
        }

        $nextAppointment = $dayAppointments
            ->first(fn (Appointment $appointment): bool => (bool) $appointment->starts_at?->gte($startsAt));

        if ($nextAppointment && $nextAppointment->starts_at?->lt($endsAt)) {
            return null;
        }

        $origin = $previousAppointment ? [
            'lat' => (float) $previousAppointment->latitude,
            'lng' => (float) $previousAppointment->longitude,
            'label' => trim($previousAppointment->customer_first_name.' '.$previousAppointment->customer_last_name),
        ] : [
            'lat' => (float) $technician->latitude,
            'lng' => (float) $technician->longitude,
            'label' => 'Domicile',
        ];
        $originAvailableAt = $previousAppointment?->ends_at ?: $dayStart;
        $originLabel = $previousAppointment ? 'rdv précédent' : 'domicile';
        $destination = [
            'lat' => (float) $crmAppointment['latitude'],
            'lng' => (float) $crmAppointment['longitude'],
        ];
        $travelTo = $drivingRoutes->estimate($origin['lat'], $origin['lng'], $destination['lat'], $destination['lng']);

        if ($originAvailableAt->copy()
            ->addMinutes((int) $travelTo['duration_minutes'])
            ->addMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES)
            ->gt($startsAt)
        ) {
            return null;
        }

        if ($nextAppointment) {
            $travelAfter = $drivingRoutes->estimate(
                $destination['lat'],
                $destination['lng'],
                (float) $nextAppointment->latitude,
                (float) $nextAppointment->longitude
            );

            if ($endsAt->copy()
                ->addMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES)
                ->addMinutes((int) $travelAfter['duration_minutes'])
                ->gt($nextAppointment->starts_at)
            ) {
                return null;
            }
        } else {
            $travelAfter = $drivingRoutes->estimate(
                $destination['lat'],
                $destination['lng'],
                (float) $technician->latitude,
                (float) $technician->longitude
            );

            if ($endsAt->copy()
                ->addMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES)
                ->addMinutes((int) $travelAfter['duration_minutes'])
                ->gt($dayEnd)
            ) {
                return null;
            }
        }

        if (! $this->technicianKeepsLunchBreak(
            $technician,
            $dayAppointments,
            $dayStart,
            $dayEnd,
            $startsAt,
            $endsAt,
            [
                'lat' => (float) $crmAppointment['latitude'],
                'lng' => (float) $crmAppointment['longitude'],
                'label' => $this->appointmentRequestDisplayName($crmAppointment),
            ],
            $drivingRoutes
        )) {
            return null;
        }

        return $this->suggestionPayload(
            technician: $technician,
            crmAppointment: $crmAppointment,
            date: $preferredStartsAt,
            startsAt: $startsAt,
            durationMinutes: $durationMinutes,
            origin: $origin,
            originLabel: $originLabel,
            travelTo: $travelTo,
            travelAfter: $travelAfter,
            previousAppointment: $previousAppointment,
            nextAppointment: $nextAppointment,
            isPreferred: true,
            drivingRoutes: $drivingRoutes,
        );
    }

    private function roundUpToNextHalfHour(Carbon $date): Carbon
    {
        $intervalSeconds = 30 * 60;
        $timestamp = $date->getTimestamp();
        $roundedTimestamp = intdiv($timestamp + $intervalSeconds - 1, $intervalSeconds) * $intervalSeconds;

        return Carbon::createFromTimestamp($roundedTimestamp, $date->getTimezone());
    }

    private function roundDownToPreviousHalfHour(Carbon $date): Carbon
    {
        $intervalSeconds = 30 * 60;
        $timestamp = $date->getTimestamp();
        $roundedTimestamp = intdiv($timestamp, $intervalSeconds) * $intervalSeconds;

        return Carbon::createFromTimestamp($roundedTimestamp, $date->getTimezone());
    }

    /**
     * @param  Collection<int, Appointment>  $dayAppointments
     * @param  array{lat: float, lng: float, label: string}  $proposalDestination
     */
    private function technicianKeepsLunchBreak(
        User $technician,
        Collection $dayAppointments,
        Carbon $dayStart,
        Carbon $dayEnd,
        Carbon $proposalStartsAt,
        Carbon $proposalEndsAt,
        array $proposalDestination,
        MapboxDrivingRouteService $drivingRoutes
    ): bool {
        $breakDurationMinutes = max(0, (int) ($technician->break_duration_minutes ?? 0));

        if ($breakDurationMinutes === 0) {
            return true;
        }

        $breakWindowStart = Carbon::parse($dayStart->format('Y-m-d').' '.self::BREAK_WINDOW_START);
        $breakWindowEnd = Carbon::parse($dayStart->format('Y-m-d').' '.self::BREAK_WINDOW_END);

        if ($breakWindowEnd->lte($dayStart) || $breakWindowStart->gte($dayEnd)) {
            return true;
        }

        $visits = $dayAppointments
            ->map(fn (Appointment $appointment): array => [
                'starts_at' => $appointment->starts_at,
                'ends_at' => $appointment->ends_at,
                'lat' => (float) $appointment->latitude,
                'lng' => (float) $appointment->longitude,
            ])
            ->push([
                'starts_at' => $proposalStartsAt->copy(),
                'ends_at' => $proposalEndsAt->copy(),
                'lat' => $proposalDestination['lat'],
                'lng' => $proposalDestination['lng'],
            ])
            ->filter(fn (array $visit): bool => $visit['starts_at'] instanceof Carbon && $visit['ends_at'] instanceof Carbon)
            ->sortBy('starts_at')
            ->values();

        $home = [
            'lat' => (float) $technician->latitude,
            'lng' => (float) $technician->longitude,
        ];
        $previousPoint = $home;
        $availableFrom = $dayStart->copy();

        foreach ($visits as $visit) {
            $routeToVisit = $drivingRoutes->estimate(
                $previousPoint['lat'],
                $previousPoint['lng'],
                (float) $visit['lat'],
                (float) $visit['lng']
            );
            $availableUntil = $visit['starts_at']->copy()
                ->subMinutes((int) $routeToVisit['duration_minutes'])
                ->subMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES);

            if ($this->breakFitsInInterval($availableFrom, $availableUntil, $breakWindowStart, $breakWindowEnd, $breakDurationMinutes)) {
                return true;
            }

            $availableFrom = $visit['ends_at']->copy()->addMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES);
            $previousPoint = [
                'lat' => (float) $visit['lat'],
                'lng' => (float) $visit['lng'],
            ];
        }

        $routeHome = $drivingRoutes->estimate(
            $previousPoint['lat'],
            $previousPoint['lng'],
            $home['lat'],
            $home['lng']
        );
        $availableUntil = $dayEnd->copy()
            ->subMinutes((int) $routeHome['duration_minutes'])
            ->subMinutes(self::APPOINTMENT_TRANSITION_MARGIN_MINUTES);

        return $this->breakFitsInInterval($availableFrom, $availableUntil, $breakWindowStart, $breakWindowEnd, $breakDurationMinutes);
    }

    private function breakFitsInInterval(
        Carbon $availableFrom,
        Carbon $availableUntil,
        Carbon $breakWindowStart,
        Carbon $breakWindowEnd,
        int $breakDurationMinutes
    ): bool {
        $startsAt = $availableFrom->greaterThan($breakWindowStart) ? $availableFrom : $breakWindowStart;
        $endsAt = $availableUntil->lessThan($breakWindowEnd) ? $availableUntil : $breakWindowEnd;

        return $startsAt->lt($endsAt) && $endsAt->diffInMinutes($startsAt, true) >= $breakDurationMinutes;
    }

    private function isBookableDay(Carbon $date): bool
    {
        return ! $date->isSunday();
    }

    private function absenceOverlapForTechnician(int $technicianId, Carbon $startsAt, Carbon $endsAt): ?TechnicianAbsence
    {
        return TechnicianAbsence::query()
            ->where('technician_id', $technicianId)
            ->where('starts_at', '<=', $endsAt)
            ->where('ends_at', '>=', $startsAt)
            ->orderBy('starts_at')
            ->first();
    }

    private function technicianHasLoadedAbsenceOverlap(User $technician, Carbon $startsAt, Carbon $endsAt): bool
    {
        if (! $technician->relationLoaded('absences')) {
            return $this->absenceOverlapForTechnician((int) $technician->id, $startsAt, $endsAt) !== null;
        }

        return $technician->absences->contains(
            fn (TechnicianAbsence $absence): bool => $absence->starts_at?->lte($endsAt)
                && $absence->ends_at?->gte($startsAt)
        );
    }

    private function absenceLabel(TechnicianAbsence $absence): string
    {
        $startsOn = $absence->starts_at?->format('d/m/Y') ?? '-';
        $endsOn = $absence->ends_at?->format('d/m/Y') ?? '-';

        return "du {$startsOn} au {$endsOn}";
    }

    private function isPlacedLotAppointment(LotAppointment $appointment): bool
    {
        return $appointment->appointment_id !== null || $appointment->status === LotAppointment::STATUS_PLACED;
    }

    private function isPhysicalProcessedLotAppointment(LotAppointment $appointment): bool
    {
        if ($appointment->processing_mode === LotAppointment::PROCESSING_MODE_CONTACT) {
            return false;
        }

        return $appointment->processing_mode === LotAppointment::PROCESSING_MODE_PHYSICAL
            || $this->isPlacedLotAppointment($appointment)
            || $appointment->physical_satisfaction !== null
            || $appointment->physical_satisfaction_synced_at !== null;
    }

    private function isContactProcessedLotAppointment(LotAppointment $appointment): bool
    {
        if ($appointment->processing_mode === LotAppointment::PROCESSING_MODE_PHYSICAL) {
            return false;
        }

        return $appointment->processing_mode === LotAppointment::PROCESSING_MODE_CONTACT
            || $appointment->status === LotAppointment::STATUS_CONTACT_PROCESSED
            || $appointment->contact_satisfaction !== null;
    }

    private function isExcludedFromLotStats(LotAppointment $appointment): bool
    {
        return (bool) $appointment->excluded_from_lot_stats;
    }

    private function isPlaceableLotAppointment(LotAppointment $appointment): bool
    {
        return ! $this->isExcludedFromLotStats($appointment)
            && ! $this->isPlacedLotAppointment($appointment)
            && ! $this->isContactProcessedLotAppointment($appointment)
            && in_array($appointment->status, [
                LotAppointment::STATUS_PENDING,
                LotAppointment::STATUS_NEEDS_REVIEW,
            ], true);
    }

    private function trackingUrlForLotAppointment(LotAppointment $lotAppointment, string $routeName): ?string
    {
        $appointment = $lotAppointment->appointment;

        if (! $appointment) {
            return null;
        }

        return route($routeName, array_filter([
            'technician_id' => $appointment->technician_id,
            'appointment_id' => $appointment->id,
            'date' => $appointment->starts_at?->toDateString(),
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param  array{lat: float, lng: float, label: string}  $origin
     * @param  array<string, mixed>  $crmAppointment
     * @param  array<string, mixed>  $travelTo
     * @param  array<string, mixed>  $travelAfter
     * @return array<string, mixed>
     */
    private function suggestionPayload(
        User $technician,
        array $crmAppointment,
        Carbon $date,
        Carbon $startsAt,
        int $durationMinutes,
        array $origin,
        string $originLabel,
        array $travelTo,
        array $travelAfter,
        ?Appointment $previousAppointment,
        ?Appointment $nextAppointment,
        bool $isPreferred = false,
        ?MapboxDrivingRouteService $drivingRoutes = null,
    ): array {
        $kind = $isPreferred ? 'preferred' : 'suggestion';
        $endsAt = (clone $startsAt)->addMinutes($durationMinutes);
        $homeTravelTo = $travelTo;
        $returnHomeTravel = $travelAfter;

        if ($drivingRoutes && $technician->latitude !== null && $technician->longitude !== null) {
            $homeTravelTo = $drivingRoutes->estimate(
                (float) $technician->latitude,
                (float) $technician->longitude,
                (float) $crmAppointment['latitude'],
                (float) $crmAppointment['longitude'],
            );
            $returnHomeTravel = $drivingRoutes->estimate(
                (float) $crmAppointment['latitude'],
                (float) $crmAppointment['longitude'],
                (float) $technician->latitude,
                (float) $technician->longitude,
            );
        }

        return [
            'id' => sprintf('%s-%d-%s-%s', $kind, $technician->id, $date->format('Ymd'), $startsAt->format('Hi')),
            'title' => ($isPreferred ? 'Dispo client' : 'Proposition').' | '.$technician->full_name_with_departments,
            'start' => $startsAt->toIso8601String(),
            'end' => $endsAt->toIso8601String(),
            'extendedProps' => [
                'technician_id' => $technician->id,
                'technician_name' => $technician->full_name_with_departments,
                'technician_address' => $technician->address,
                'technician_latitude' => $technician->latitude ? (float) $technician->latitude : null,
                'technician_longitude' => $technician->longitude ? (float) $technician->longitude : null,
                'is_suggestion' => true,
                'origin_label' => $originLabel,
                'origin_latitude' => $origin['lat'],
                'origin_longitude' => $origin['lng'],
                'origin_name' => $origin['label'],
                'latitude' => (float) $crmAppointment['latitude'],
                'longitude' => (float) $crmAppointment['longitude'],
                'address' => $crmAppointment['address'],
                'customer_name' => $this->appointmentRequestDisplayName($crmAppointment),
                'company_name' => $crmAppointment['company_name'] ?? null,
                'site_name' => $crmAppointment['site_name'] ?? null,
                'customer_phone' => $crmAppointment['phone'],
                'service_label' => $crmAppointment['service']
                    ? $crmAppointment['service']['name']
                    : ($crmAppointment['service_display_name'] ?? $crmAppointment['service_name'] ?? 'Prestation non renseignée'),
                'documents' => app(AppointmentDocumentSerializer::class)->normalize($crmAppointment['documents'] ?? []),
                'comments' => $crmAppointment['comments'] ?? [],
                'problem_type' => $crmAppointment['problem_type'] ?? null,
                'problem_comment' => $crmAppointment['problem_comment'] ?? null,
                'recall_date' => $crmAppointment['recall_date'] ?? null,
                'recall_time' => $crmAppointment['recall_time'] ?? null,
                'recall_at' => $crmAppointment['recall_at'] ?? null,
                'crm_appointment_id' => $crmAppointment['id'],
                'lot_appointment_id' => $crmAppointment['lot_appointment_id'] ?? null,
                'replace_appointment_id' => $crmAppointment['replace_appointment_id'] ?? null,
                'can_validate' => $crmAppointment['service'] !== null,
                'travel_to_distance_km' => round((float) $travelTo['distance_km'], 1),
                'travel_to_minutes' => (int) $travelTo['duration_minutes'],
                'travel_after_distance_km' => round((float) $travelAfter['distance_km'], 1),
                'travel_after_minutes' => (int) $travelAfter['duration_minutes'],
                'home_to_distance_km' => round((float) $homeTravelTo['distance_km'], 1),
                'home_to_minutes' => (int) $homeTravelTo['duration_minutes'],
                'return_home_distance_km' => round((float) $returnHomeTravel['distance_km'], 1),
                'return_home_minutes' => (int) $returnHomeTravel['duration_minutes'],
                'has_previous_appointment' => str_contains(mb_strtolower($originLabel), 'rdv'),
                'has_next_appointment' => $nextAppointment !== null,
                'duration_minutes' => $durationMinutes,
                'previous_appointment_id' => $previousAppointment?->id,
                'next_appointment_id' => $nextAppointment?->id,
                'preferred_locked' => $isPreferred,
            ],
        ];
    }

    /**
     * @param  array{state?: string, label?: string, detail?: string, count?: int}  $coffracStatus
     * @return array<int, array<string, mixed>>
     */
    private function externalAppointmentSources(array $coffracStatus): array
    {
        $reservedStatus = [
            'state' => 'not_configured',
            'label' => 'Connecteur à connecter',
            'detail' => 'Emplacement préparé pour une future application externe.',
            'count' => 0,
        ];

        return collect(app(ExternalAppointmentSourceRegistry::class)->all())
            ->map(function (array $source) use ($coffracStatus, $reservedStatus): array {
                $source['status'] = $source['key'] === CoffracAppointmentService::SOURCE
                    ? $coffracStatus
                    : [
                        ...$reservedStatus,
                        'label' => $source['label'].' à connecter',
                    ];

                return $source;
            })
            ->values()
            ->all();
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || in_array($user->role, [0, 1], true));
    }

    private function haversine(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusKm = 6371;
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
