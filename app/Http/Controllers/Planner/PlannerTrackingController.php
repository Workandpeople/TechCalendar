<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use App\Services\AppointmentDocumentSerializer;
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
    public function index(Request $request): View
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

        return view('planner.tracking', [
            'technicians' => $technicians,
            'services' => Service::query()
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'type', 'name']),
            'section' => $request->routeIs('manager.appointments') ? 'Gérant' : 'Planning',
            'title' => $request->routeIs('manager.appointments') ? 'Gestion des rdv' : 'Suivi des rdv',
            'mapboxToken' => config('services.mapbox.token'),
        ]);
    }

    public function events(Request $request): JsonResponse
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
            'appointment_status' => ['nullable', Rule::in(['all', 'active', 'deleted'])],
        ]);

        $technicianIds = collect($payload['technician_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($technicianIds->isEmpty()) {
            return response()->json(['events' => []]);
        }

        $appointmentsQuery = Appointment::withTrashed()
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
            'active' => $appointmentsQuery->whereNull('deleted_at'),
            'deleted' => $appointmentsQuery->onlyTrashed(),
            default => null,
        };

        $appointments = $appointmentsQuery
            ->orderBy('starts_at')
            ->get();

        $activeAppointmentsByTechnician = $appointments
            ->filter(fn (Appointment $appointment): bool => ! $appointment->trashed())
            ->groupBy('technician_id')
            ->map(fn ($technicianAppointments) => $technicianAppointments->sortBy('starts_at')->values());
        $documentsByAppointment = app(AppointmentDocumentSerializer::class)->forAppointments($appointments);

        return response()->json([
            'events' => $appointments->map(function (Appointment $appointment) use ($activeAppointmentsByTechnician, $documentsByAppointment): array {
                $technicianName = $appointment->technician?->full_name_with_departments ?? 'Technicien';
                $serviceLabel = $appointment->service
                    ? sprintf('%s - %s', $appointment->service->type, $appointment->service->name)
                    : 'Prestation';
                $location = $this->extractLocationFromAddress($appointment->address);
                $previousAppointment = $activeAppointmentsByTechnician
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
                        'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                        'customer_phone' => $appointment->customer_phone,
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
                        'deleted_at' => $appointment->deleted_at?->toIso8601String(),
                        'created_by_name' => $appointment->creator?->full_name,
                        'documents' => $documentsByAppointment[$appointment->id] ?? [],
                    ],
                ];
            })->values(),
        ]);
    }

    public function updateDetails(
        Request $request,
        int $appointment,
        AppointmentTechnicianMailService $appointmentMails,
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::withTrashed()
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

        $appointment = Appointment::withTrashed()
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

    public function destroy(
        Request $request,
        Appointment $appointment,
        AppointmentTechnicianMailService $appointmentMails,
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        if (trim($payload['comment']) === trim((string) $appointment->comment)) {
            throw ValidationException::withMessages([
                'comment' => 'Le commentaire doit être modifié avant de soft delete le RDV.',
            ]);
        }

        $appointment->update([
            'comment' => $payload['comment'],
        ]);
        $appointment->delete();
        $appointmentMails->cancelled($appointment);

        return response()->json([
            'message' => 'Rendez-vous désactivé.',
            'deleted_at' => $appointment->deleted_at?->toIso8601String(),
            'comment' => $appointment->comment,
        ]);
    }

    public function restore(
        Request $request,
        int $appointment,
        AppointmentTechnicianMailService $appointmentMails,
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::withTrashed()->findOrFail($appointment);

        $payload = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        if (trim($payload['comment']) === trim((string) $appointment->comment)) {
            throw ValidationException::withMessages([
                'comment' => 'Le commentaire doit être modifié avant de réactiver le RDV.',
            ]);
        }

        $appointment->update([
            'comment' => $payload['comment'],
        ]);

        if ($appointment->trashed()) {
            $appointment->restore();
        }

        $appointmentMails->restored($appointment);

        return response()->json([
            'message' => 'Rendez-vous réactivé.',
            'comment' => $appointment->comment,
            'deleted_at' => null,
        ]);
    }

    public function updateComment(
        Request $request,
        int $appointment,
        AppointmentTechnicianMailService $appointmentMails,
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::withTrashed()->findOrFail($appointment);

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

        $appointment = Appointment::withTrashed()->findOrFail($appointment);

        if ($appointment->trashed()) {
            throw ValidationException::withMessages([
                'comment' => 'Réactive le RDV avant de le déclarer en problème.',
            ]);
        }

        $payload = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        if (trim($payload['comment']) === trim((string) $appointment->comment)) {
            throw ValidationException::withMessages([
                'comment' => 'Le commentaire doit être modifié avant de déclarer un problème RDV.',
            ]);
        }

        try {
            $appointment = DB::transaction(function () use ($appointment, $coffracAppointments, $payload): Appointment {
                $appointment->update([
                    'comment' => $payload['comment'],
                    'status' => Appointment::STATUS_PROBLEM,
                    'problem_reported_at' => now(),
                ]);

                $appointment = Appointment::withTrashed()
                    ->with(['technician:id,email', 'service:id,type,name'])
                    ->findOrFail($appointment->id);

                $coffracAppointments->markProblem($appointment, (string) $payload['comment']);

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

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || in_array($user->role, [0, 1], true));
    }
}
