<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlannerTrackingController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canAccess($request), 403);

        $technicians = User::query()
            ->with('services:id')
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'phone', 'address', 'department_code']);

        return view('planner.tracking', [
            'technicians' => $technicians,
            'services' => Service::query()
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'type', 'name']),
            'section' => $request->routeIs('manager.appointments') ? 'Gerant' : 'Planning',
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
                'technician:id,first_name,last_name,address,latitude,longitude',
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

        return response()->json([
            'events' => $appointments->map(function (Appointment $appointment) use ($activeAppointmentsByTechnician): array {
                $technicianName = $appointment->technician?->full_name ?? 'Technicien';
                $serviceLabel = $appointment->service
                    ? sprintf('%s - %s', $appointment->service->type, $appointment->service->name)
                    : 'Prestation';
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
                        'service_label' => $serviceLabel,
                        'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                        'customer_phone' => $appointment->customer_phone,
                        'address' => $appointment->address,
                        'latitude' => $appointment->latitude,
                        'longitude' => $appointment->longitude,
                        'origin_latitude' => $originLatitude,
                        'origin_longitude' => $originLongitude,
                        'origin_name' => $originName,
                        'origin_label' => $previousAppointment ? 'RDV precedent' : 'Domicile',
                        'duration_minutes' => $appointment->duration_minutes,
                        'comment' => $appointment->comment,
                        'deleted_at' => $appointment->deleted_at?->toIso8601String(),
                        'created_by_name' => $appointment->creator?->full_name,
                    ],
                ];
            })->values(),
        ]);
    }

    public function reassignTechnician(Request $request, int $appointment): JsonResponse
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
                'technician_id' => 'Choisis un autre technicien pour reaffecter ce RDV.',
            ]);
        }

        $technician = User::query()
            ->with('services:id')
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
                'technician_id' => 'Ce technicien a deja un RDV sur ce creneau.',
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

        return response()->json([
            'message' => 'Rendez-vous reaffecte.',
            'technician' => [
                'id' => $technician->id,
                'name' => $technician->full_name,
                'address' => $technician->address,
                'latitude' => $technician->latitude,
                'longitude' => $technician->longitude,
            ],
        ]);
    }

    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        if (trim($payload['comment']) === trim((string) $appointment->comment)) {
            throw ValidationException::withMessages([
                'comment' => 'Le commentaire doit etre modifie avant de soft delete le RDV.',
            ]);
        }

        $appointment->update([
            'comment' => $payload['comment'],
        ]);
        $appointment->delete();

        return response()->json([
            'message' => 'Rendez-vous desactive.',
            'deleted_at' => $appointment->deleted_at?->toIso8601String(),
            'comment' => $appointment->comment,
        ]);
    }

    public function restore(Request $request, int $appointment): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::withTrashed()->findOrFail($appointment);

        $payload = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        if (trim($payload['comment']) === trim((string) $appointment->comment)) {
            throw ValidationException::withMessages([
                'comment' => 'Le commentaire doit etre modifie avant de reactiver le RDV.',
            ]);
        }

        $appointment->update([
            'comment' => $payload['comment'],
        ]);

        if ($appointment->trashed()) {
            $appointment->restore();
        }

        return response()->json([
            'message' => 'Rendez-vous reactive.',
            'comment' => $appointment->comment,
            'deleted_at' => null,
        ]);
    }

    public function updateComment(Request $request, int $appointment): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::withTrashed()->findOrFail($appointment);

        $payload = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment->update([
            'comment' => $payload['comment'] ?? null,
        ]);

        return response()->json([
            'message' => 'Commentaire mis a jour.',
            'comment' => $appointment->comment,
        ]);
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || in_array($user->role, [0, 1], true));
    }
}
