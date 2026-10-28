<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\AppointmentDocumentSerializer;
use App\Services\CoffracAppointmentService;
use App\Services\Tech\TechnicianWorkspace;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TechPlanningController extends Controller
{
    public function index(Request $request, TechnicianWorkspace $workspace): View
    {
        abort_unless($this->canAccess($request), 403);
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return view('tech.planning', $workspace->overview($request->user(), $filters['date'] ?? null));
    }

    public function events(Request $request, TechnicianWorkspace $workspace): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $appointments = $workspace->appointmentsQuery($request->user())
            ->where('starts_at', '<', Carbon::parse($payload['end']))
            ->where('ends_at', '>', Carbon::parse($payload['start']))
            ->orderBy('starts_at')
            ->get();
        $documentsByAppointment = app(AppointmentDocumentSerializer::class)->forAppointments($appointments);

        return response()->json([
            'events' => $appointments->map(function (Appointment $appointment) use ($documentsByAppointment, $workspace): array {
                $serviceLabel = $appointment->service
                    ? sprintf('%s - %s', $appointment->service->display_type, $appointment->service->name)
                    : 'Prestation';

                return [
                    'id' => $appointment->id,
                    'title' => sprintf('%s | %s %s', $serviceLabel, $appointment->customer_first_name, $appointment->customer_last_name),
                    'start' => $appointment->starts_at?->toIso8601String(),
                    'end' => $appointment->ends_at?->toIso8601String(),
                    'extendedProps' => [
                        'service_label' => $serviceLabel,
                        'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                        'customer_phone' => $appointment->customer_phone,
                        'phone_href' => $workspace->card($appointment)['phone']['href'] ?? null,
                        'address' => $appointment->address,
                        'latitude' => $appointment->latitude,
                        'longitude' => $appointment->longitude,
                        'duration_minutes' => $appointment->duration_minutes,
                        'comment' => $appointment->comment,
                        'documents' => $documentsByAppointment[$appointment->id] ?? [],
                        'external_source' => $appointment->external_source,
                        'external_reference' => $appointment->external_reference,
                        'detail_url' => config('crm.v2_enabled') ? route('tech.appointments.show', $appointment) : null,
                    ],
                ];
            })->values(),
        ]);
    }

    public function refreshCoffracAppointment(
        Request $request,
        Appointment $appointment,
        CoffracAppointmentService $coffracAppointments,
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $user = $request->user();

        abort_unless($user?->admin || (int) $appointment->technician_id === (int) $user?->id, 404);

        $refresh = $coffracAppointments->refreshAppointment($appointment);

        return response()->json([
            'message' => 'Documents Cofrac mis à jour.',
            'documents' => $refresh['documents'],
            'status' => $refresh['status'],
            'remote_status_name' => $refresh['remote_status_name'],
            'fetched_at' => $refresh['fetched_at'],
        ]);
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return app(TechnicianWorkspace::class)->canAccess($user);
    }
}
