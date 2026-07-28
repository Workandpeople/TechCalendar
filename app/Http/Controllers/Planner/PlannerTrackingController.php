<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCoffracAppointmentsJob;
use App\Models\Appointment;
use App\Models\MailTemplate;
use App\Models\Service;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use App\Services\AppointmentDocumentSerializer;
use App\Services\AppointmentMailTemplateData;
use App\Services\AppointmentTechnicianMailService;
use App\Services\CoffracAppointmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class PlannerTrackingController extends Controller
{
    public function index(Request $request, CoffracAppointmentService $coffracAppointments): View
    {
        abort_unless($this->canAccess($request), 403);

        $technicians = User::query()
            ->with(['services:id', 'departments:code'])
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'phone', 'address', 'department_code', 'role']);
        $mailTemplates = MailTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'subject', 'markdown_body', 'logo_path']);
        $trackingMailTemplates = $mailTemplates
            ->map(fn (MailTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'subject' => $template->subject,
                'markdown_body' => $template->markdown_body,
                'logo_url' => $template->logo_url,
            ])
            ->values();

        return view('planner.tracking', [
            'technicians' => $technicians,
            'services' => Service::query()
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'type', 'name']),
            'section' => $request->routeIs('manager.appointments') ? 'Gérant' : 'Planning',
            'title' => $request->routeIs('manager.appointments') ? 'Gestion des rdv' : 'Suivi des rdv',
            'mapboxToken' => config('services.mapbox.token'),
            'coffracProblemTypes' => $coffracAppointments->problemTypes(),
            'mailTemplates' => $mailTemplates,
            'trackingMailTemplates' => $trackingMailTemplates,
            'refreshPlacedCoffracUrl' => route($request->routeIs('manager.appointments')
                ? 'manager.appointments.coffrac.placed.refresh'
                : 'planner.tracking.coffrac.placed.refresh'),
        ]);
    }

    public function refreshPlacedCoffracAppointments(
        Request $request,
        CoffracAppointmentService $coffracAppointments,
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        if (! $coffracAppointments->isConfigured()) {
            return response()->json([
                'sync_queued' => false,
                'message' => 'API Coffrac non configurée.',
            ], 422);
        }

        $coffracAppointments->markSyncQueued('Récupération des RDV Coffrac déjà placés lancée en arrière-plan...');
        SyncCoffracAppointmentsJob::dispatch(false, CoffracAppointmentService::REMOTE_STATUS_PLACED);

        return response()->json([
            'sync_queued' => true,
            'message' => 'Récupération des RDV Coffrac déjà placés lancée. Le calendrier va se mettre à jour avec les données locales.',
        ]);
    }

    public function refreshCoffracAppointment(
        Request $request,
        int $appointment,
        CoffracAppointmentService $coffracAppointments,
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::query()->findOrFail($appointment);

        try {
            $refresh = $coffracAppointments->refreshAppointment($appointment);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'appointment' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Dossier Coffrac mis à jour.',
            'documents' => $refresh['documents'],
            'comments' => $refresh['comments'],
            'status' => $refresh['status'],
            'remote_status_name' => $refresh['remote_status_name'],
            'fetched_at' => $refresh['fetched_at'],
        ]);
    }

    public function events(Request $request, AppointmentMailTemplateData $appointmentMailData): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'technician_ids' => ['nullable', 'array', 'max:100'],
            'technician_ids.*' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 2)->where('admin', false)),
            ],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'appointment_status' => ['nullable', Rule::in(['all', 'active', 'problem'])],
        ]);

        $technicianIds = collect($payload['technician_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($technicianIds->isEmpty()) {
            return response()->json(['events' => []]);
        }

        $appointmentsQuery = Appointment::query()
            ->with([
                'service:id,type,name',
                'technician:id,first_name,last_name,address,department_code,latitude,longitude,role',
                'technician.departments:code',
                'creator:id,first_name,last_name',
            ])
            ->whereIn('technician_id', $technicianIds)
            ->where('starts_at', '<', Carbon::parse($payload['end']))
            ->where('ends_at', '>', Carbon::parse($payload['start']))
            ->when(! empty($payload['service_id']), fn ($query) => $query->where('service_id', (int) $payload['service_id']));

        match ($payload['appointment_status'] ?? 'all') {
            'active' => $appointmentsQuery->where('status', Appointment::STATUS_SCHEDULED),
            'problem' => $appointmentsQuery->where('status', Appointment::STATUS_PROBLEM),
            default => null,
        };

        $appointments = $appointmentsQuery
            ->orderBy('starts_at')
            ->get();

        $appointmentsByTechnician = $appointments
            ->groupBy('technician_id')
            ->map(fn ($technicianAppointments) => $technicianAppointments->sortBy('starts_at')->values());
        $documentsByAppointment = app(AppointmentDocumentSerializer::class)->forAppointments($appointments);

        return response()->json([
            'events' => $appointments->map(function (Appointment $appointment) use ($appointmentsByTechnician, $documentsByAppointment, $appointmentMailData): array {
                $technicianName = $appointment->technician?->full_name_with_departments ?? 'Technicien';
                $serviceLabel = $appointment->service
                    ? sprintf('%s - %s', $appointment->service->type, $appointment->service->name)
                    : 'Prestation';
                $location = $this->extractLocationFromAddress($appointment->address);
                $previousAppointment = $appointmentsByTechnician
                    ->get($appointment->technician_id, collect())
                    ->filter(fn (Appointment $candidate): bool => $candidate->id !== $appointment->id)
                    ->filter(fn (Appointment $candidate): bool => (bool) $candidate->starts_at?->isSameDay($appointment->starts_at))
                    ->filter(fn (Appointment $candidate): bool => (bool) $candidate->ends_at?->lte($appointment->starts_at))
                    ->sortByDesc('ends_at')
                    ->first();

                $originLatitude = $previousAppointment?->latitude ?? $appointment->technician?->latitude;
                $originLongitude = $previousAppointment?->longitude ?? $appointment->technician?->longitude;
                $originName = $previousAppointment
                    ? trim($previousAppointment->customer_first_name.' '.$previousAppointment->customer_last_name)
                    : ($appointment->technician?->address ?: 'Domicile technicien');
                $externalPayload = is_array($appointment->external_payload) ? $appointment->external_payload : [];

                return [
                    'id' => $appointment->id,
                    'title' => sprintf('%s | %s', $technicianName, $appointment->customer_first_name.' '.$appointment->customer_last_name),
                    'start' => $appointment->starts_at?->toIso8601String(),
                    'end' => $appointment->ends_at?->toIso8601String(),
                    'extendedProps' => [
                        'technician_id' => $appointment->technician_id,
                        'technician_name' => $technicianName,
                        'technician_address' => $appointment->technician?->address,
                        'technician_latitude' => $appointment->technician?->latitude ? (float) $appointment->technician->latitude : null,
                        'technician_longitude' => $appointment->technician?->longitude ? (float) $appointment->technician->longitude : null,
                        'service_id' => $appointment->service_id,
                        'service_type' => $appointment->service?->type,
                        'service_label' => $serviceLabel,
                        'external_source' => $appointment->external_source,
                        'external_reference' => $appointment->external_reference,
                        'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                        'customer_phone' => $appointment->customer_phone,
                        'customer_email' => $appointmentMailData->defaultRecipientEmail($appointment),
                        'address' => $appointment->address,
                        'postal_code' => $location['postal_code'],
                        'city' => $location['city'],
                        'location_label' => $location['label'],
                        'latitude' => $appointment->latitude,
                        'longitude' => $appointment->longitude,
                        'origin_latitude' => $originLatitude,
                        'origin_longitude' => $originLongitude,
                        'origin_name' => $originName,
                        'origin_label' => $previousAppointment ? 'RDV précédent' : 'Domicile',
                        'duration_minutes' => $appointment->duration_minutes,
                        'comment' => $appointment->comment,
                        'status' => $appointment->status,
                        'problem_reported_at' => $appointment->problem_reported_at?->toIso8601String(),
                        'problem_type' => data_get($externalPayload, 'problem_type') ?: data_get($externalPayload, 'techcalendar_problem.problem_type'),
                        'problem_comment' => data_get($externalPayload, 'problem_comment') ?: data_get($externalPayload, 'techcalendar_problem.comment'),
                        'recall_date' => data_get($externalPayload, 'recall_date') ?: data_get($externalPayload, 'techcalendar_problem.recall_date'),
                        'recall_time' => data_get($externalPayload, 'recall_time') ?: data_get($externalPayload, 'techcalendar_problem.recall_time'),
                        'recall_at' => data_get($externalPayload, 'recall_at') ?: data_get($externalPayload, 'date_rappel'),
                        'created_by_name' => $appointment->creator?->full_name,
                        'comments' => $this->externalComments($appointment),
                        'documents' => $documentsByAppointment[$appointment->id] ?? [],
                    ],
                ];
            })->values(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function externalComments(Appointment $appointment): array
    {
        $comments = data_get($appointment->external_payload, 'comments', []);

        return is_array($comments) ? array_values($comments) : [];
    }

    public function updateDetails(
        Request $request,
        int $appointment,
        AppointmentTechnicianMailService $appointmentMails,
        CoffracAppointmentService $coffracAppointments,
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::query()
            ->with('service:id,type,name')
            ->findOrFail($appointment);

        $payload = $request->validate([
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $startsAt = Carbon::parse($payload['starts_at']);
        $endsAt = $startsAt->copy()->addMinutes((int) $payload['duration_minutes']);

        $hasOverlappingAppointment = Appointment::query()
            ->where('technician_id', $appointment->technician_id)
            ->whereKeyNot($appointment->id)
            ->whereNull('deleted_at')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($hasOverlappingAppointment) {
            throw ValidationException::withMessages([
                'starts_at' => 'Ce technicien a déjà un RDV sur ce créneau.',
            ]);
        }

        $previousDate = $appointment->starts_at?->toDateString();

        try {
            $coffracAppointments->updateAppointmentAddress($appointment, $payload);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'address' => $exception->getMessage(),
            ]);
        }

        $appointment->update([
            'starts_at' => $startsAt,
            'duration_minutes' => (int) $payload['duration_minutes'],
            'ends_at' => $endsAt,
            'address' => $payload['address'],
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
        ]);

        $this->forgetRouteMetricsForAppointmentDates(
            (int) $appointment->technician_id,
            array_filter([$previousDate, $startsAt->toDateString()]),
        );

        $appointmentMails->detailsUpdated($appointment);

        $location = $this->extractLocationFromAddress($appointment->address);

        return response()->json([
            'message' => 'Rendez-vous mis à jour.',
            'appointment' => [
                'id' => $appointment->id,
                'start' => $appointment->starts_at?->toIso8601String(),
                'end' => $appointment->ends_at?->toIso8601String(),
                'duration_minutes' => $appointment->duration_minutes,
                'address' => $appointment->address,
                'latitude' => $appointment->latitude,
                'longitude' => $appointment->longitude,
                'postal_code' => $location['postal_code'],
                'city' => $location['city'],
                'location_label' => $location['label'],
            ],
        ]);
    }

    public function reassignTechnician(
        Request $request,
        int $appointment,
        AppointmentTechnicianMailService $appointmentMails,
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::query()
            ->with('service:id,type,name')
            ->findOrFail($appointment);

        $payload = $request->validate([
            'technician_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', 2)
                    ->where('admin', false)
                    ->whereNull('deleted_at')),
            ],
        ]);

        $targetTechnicianId = (int) $payload['technician_id'];

        if ((int) $appointment->technician_id === $targetTechnicianId) {
            throw ValidationException::withMessages([
                'technician_id' => 'Choisis un autre technicien pour réaffecter ce RDV.',
            ]);
        }

        $technician = User::query()
            ->with(['services:id', 'departments:code'])
            ->whereKey($targetTechnicianId)
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->firstOrFail();

        if ($appointment->service_id && ! $technician->services->contains('id', $appointment->service_id)) {
            throw ValidationException::withMessages([
                'technician_id' => 'Ce technicien ne couvre pas la prestation du RDV.',
            ]);
        }

        $hasOverlappingAppointment = Appointment::query()
            ->where('technician_id', $technician->id)
            ->whereKeyNot($appointment->id)
            ->whereNull('deleted_at')
            ->where('starts_at', '<', $appointment->ends_at)
            ->where('ends_at', '>', $appointment->starts_at)
            ->exists();

        if ($hasOverlappingAppointment) {
            throw ValidationException::withMessages([
                'technician_id' => 'Ce technicien a déjà un RDV sur ce créneau.',
            ]);
        }

        $previousTechnicianId = (int) $appointment->technician_id;
        $serviceDate = $appointment->starts_at?->toDateString();

        $appointment->update([
            'technician_id' => $technician->id,
        ]);

        if ($serviceDate) {
            TechnicianDailyRouteMetric::query()
                ->whereIn('technician_id', [$previousTechnicianId, $technician->id])
                ->whereDate('service_date', $serviceDate)
                ->delete();
        }

        $appointmentMails->reassigned($appointment, $previousTechnicianId);

        return response()->json([
            'message' => 'Rendez-vous réaffecté.',
            'technician' => [
                'id' => $technician->id,
                'name' => $technician->full_name_with_departments,
                'address' => $technician->address,
                'latitude' => $technician->latitude,
                'longitude' => $technician->longitude,
            ],
        ]);
    }

    public function updateComment(
        Request $request,
        int $appointment,
        AppointmentTechnicianMailService $appointmentMails,
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::query()->findOrFail($appointment);

        $payload = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment->update([
            'comment' => $payload['comment'] ?? null,
        ]);

        $appointmentMails->commentUpdated($appointment);

        return response()->json([
            'message' => 'Commentaire mis à jour.',
            'comment' => $appointment->comment,
        ]);
    }

    public function markProblem(
        Request $request,
        int $appointment,
        CoffracAppointmentService $coffracAppointments,
        AppointmentTechnicianMailService $appointmentMails,
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::query()->findOrFail($appointment);

        $payload = $request->validate($this->problemReportRules($coffracAppointments));

        try {
            $appointment = DB::transaction(function () use ($appointment, $coffracAppointments, $payload): Appointment {
                $externalPayload = is_array($appointment->external_payload) ? $appointment->external_payload : [];

                $appointment->update([
                    'comment' => $payload['comment'],
                    'status' => Appointment::STATUS_PROBLEM,
                    'problem_reported_at' => now(),
                    'external_payload' => [
                        ...$externalPayload,
                        'techcalendar_problem' => $payload,
                    ],
                ]);

                $appointment = Appointment::query()
                    ->with(['technician:id,email', 'service:id,type,name'])
                    ->findOrFail($appointment->id);

                $coffracAppointments->markProblem($appointment, $payload);

                return $appointment;
            });
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'comment' => $exception->getMessage(),
            ]);
        }

        $appointmentMails->problemReported($appointment);

        return response()->json([
            'message' => 'Problème RDV déclaré.',
            'comment' => $appointment->comment,
            'status' => $appointment->status,
            'problem_reported_at' => $appointment->problem_reported_at?->toIso8601String(),
            'problem_type' => $payload['problem_type'],
            'recall_date' => $payload['recall_date'] ?? null,
            'recall_time' => $payload['recall_time'] ?? null,
        ]);
    }

    /**
     * @param  array<int, string>  $dates
     */
    private function forgetRouteMetricsForAppointmentDates(int $technicianId, array $dates): void
    {
        $uniqueDates = collect($dates)
            ->filter()
            ->unique()
            ->values();

        if ($uniqueDates->isEmpty()) {
            return;
        }

        TechnicianDailyRouteMetric::query()
            ->where('technician_id', $technicianId)
            ->where(function ($query) use ($uniqueDates): void {
                foreach ($uniqueDates as $date) {
                    $query->orWhereDate('service_date', $date);
                }
            })
            ->delete();
    }

    /**
     * @return array{postal_code:?string, city:?string, label:string}
     */
    private function extractLocationFromAddress(?string $address): array
    {
        $address = trim((string) $address);

        if ($address === '') {
            return ['postal_code' => null, 'city' => null, 'label' => 'Adresse non renseignée'];
        }

        preg_match('/\b(\d{5})\b/u', $address, $matches);
        $postalCode = $matches[1] ?? null;
        $city = null;

        if ($postalCode) {
            $parts = preg_split('/,\s*/u', $address) ?: [];

            foreach ($parts as $part) {
                if (str_contains($part, $postalCode)) {
                    $city = trim((string) preg_replace('/\b'.preg_quote($postalCode, '/').'\b/u', '', $part));
                    break;
                }
            }
        }

        if (! $city) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/,\s*/u', $address) ?: [])));
            $lastPart = end($parts) ?: null;
            $city = $lastPart ? trim((string) preg_replace('/\b\d{5}\b/u', '', $lastPart)) : null;
        }

        $city = $city ? trim(str_replace('France', '', $city), " \t\n\r\0\x0B-") : null;
        $label = trim(implode(' ', array_filter([$postalCode, $city])));

        return [
            'postal_code' => $postalCode,
            'city' => $city ?: null,
            'label' => $label !== '' ? $label : $address,
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

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || in_array($user->role, [0, 1], true));
    }
}
