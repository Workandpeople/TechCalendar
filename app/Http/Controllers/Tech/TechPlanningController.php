<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\AppointmentDocumentSerializer;
use App\Services\TechnicianDailyRouteMetricService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TechPlanningController extends Controller
{
    public function index(Request $request, TechnicianDailyRouteMetricService $routeMetrics): View
    {
        abort_unless($this->canAccess($request), 403);

        $user = $request->user();
        $now = now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();

        $metrics = $routeMetrics->ensureForTechnicianPeriod($user, $weekStart, $weekEnd);
        $nextAppointment = $this->appointmentsQuery($user->id)
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->first();
        $upcomingAppointments = $this->appointmentsQuery($user->id)
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->limit(5)
            ->get();
        $todayAppointmentsCount = $this->appointmentsQuery($user->id)
            ->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->count();
        $weekAppointments = $this->appointmentsQuery($user->id)
            ->whereBetween('starts_at', [$weekStart, $weekEnd])
            ->get();

        return view('tech.planning', [
            'technician' => $user,
            'nextAppointment' => $nextAppointment,
            'upcomingAppointments' => $upcomingAppointments,
            'mapboxToken' => config('services.mapbox.token'),
            'stats' => [
                [
                    'label' => 'RDV aujourd hui',
                    'value' => $todayAppointmentsCount,
                    'detail' => 'Interventions prévues sur la journée',
                    'tone' => 'blue',
                ],
                [
                    'label' => 'RDV cette semaine',
                    'value' => $weekAppointments->count(),
                    'detail' => round(((int) $weekAppointments->sum('duration_minutes')) / 60, 1).'h planifiées',
                    'tone' => 'green',
                ],
                [
                    'label' => 'Km semaine',
                    'value' => round((float) $metrics->sum('drive_distance_km'), 1).' km',
                    'detail' => 'Domicile, RDV puis retour domicile',
                    'tone' => 'gold',
                ],
                [
                    'label' => 'Temps route',
                    'value' => round(((int) $metrics->sum('drive_duration_minutes')) / 60, 1).'h',
                    'detail' => $metrics->where('calculation_source', 'mapbox')->count().' jour(s) Mapbox',
                    'tone' => 'pink',
                ],
                [
                    'label' => 'Heures supp',
                    'value' => round(((int) $metrics->sum('overtime_minutes')) / 60, 1).'h',
                    'detail' => 'Temps hors horaires, trajets inclus',
                    'tone' => 'orange',
                ],
            ],
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $appointments = $this->appointmentsQuery($request->user()->id)
            ->where('starts_at', '<', Carbon::parse($payload['end']))
            ->where('ends_at', '>', Carbon::parse($payload['start']))
            ->orderBy('starts_at')
            ->get();
        $documentsByAppointment = app(AppointmentDocumentSerializer::class)->forAppointments($appointments);

        return response()->json([
            'events' => $appointments->map(function (Appointment $appointment) use ($documentsByAppointment): array {
                $serviceLabel = $appointment->service
                    ? sprintf('%s - %s', $appointment->service->type, $appointment->service->name)
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
                        'address' => $appointment->address,
                        'latitude' => $appointment->latitude,
                        'longitude' => $appointment->longitude,
                        'duration_minutes' => $appointment->duration_minutes,
                        'comment' => $appointment->comment,
                        'documents' => $documentsByAppointment[$appointment->id] ?? [],
                    ],
                ];
            })->values(),
        ]);
    }

    private function appointmentsQuery(int $technicianId)
    {
        return Appointment::query()
            ->with('service:id,type,name')
            ->where('technician_id', $technicianId);
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 2);
    }
}
