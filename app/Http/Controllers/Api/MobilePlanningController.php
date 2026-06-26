<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\TechnicianDailyRouteMetric;
use App\Services\AppointmentDocumentSerializer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobilePlanningController extends Controller
{
    public function __construct(
        private readonly AppointmentDocumentSerializer $documentSerializer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $technician = $request->user();
        $now = now();
        $windowStart = Carbon::parse($request->query('start', $now->copy()->startOfDay()))->startOfDay();
        $windowEnd = Carbon::parse($request->query('end', $now->copy()->addDays(45)->endOfDay()))->endOfDay();

        if ($windowEnd->diffInDays($windowStart) > 120) {
            $windowEnd = $windowStart->copy()->addDays(120)->endOfDay();
        }

        $appointments = Appointment::query()
            ->with('service:id,type,name')
            ->where('technician_id', $technician->id)
            ->where('starts_at', '<=', $windowEnd)
            ->where('ends_at', '>=', $windowStart)
            ->orderBy('starts_at')
            ->get();
        $documentsByAppointment = $this->documentSerializer->forAppointments($appointments);

        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();
        $weekAppointments = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->starts_at?->betweenIncluded($weekStart, $weekEnd) ?? false);
        $todayAppointments = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->starts_at?->isSameDay($now) ?? false);
        $nextAppointment = $appointments
            ->first(fn (Appointment $appointment): bool => $appointment->starts_at?->gte($now) ?? false);
        $metrics = TechnicianDailyRouteMetric::query()
            ->where('technician_id', $technician->id)
            ->whereBetween('service_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();

        return response()->json([
            'generated_at' => $now->toIso8601String(),
            'period' => [
                'start' => $windowStart->toDateString(),
                'end' => $windowEnd->toDateString(),
            ],
            'widgets' => [
                'today_count' => $todayAppointments->count(),
                'week_count' => $weekAppointments->count(),
                'week_planned_hours' => round(((int) $weekAppointments->sum('duration_minutes')) / 60, 1),
                'week_drive_km' => round((float) $metrics->sum('drive_distance_km'), 1),
                'week_drive_hours' => round(((int) $metrics->sum('drive_duration_minutes')) / 60, 1),
                'week_overtime_hours' => round(((int) $metrics->sum('overtime_minutes')) / 60, 1),
                'next_appointment' => $nextAppointment
                    ? $this->serializeAppointment($nextAppointment, $documentsByAppointment[$nextAppointment->id] ?? [])
                    : null,
            ],
            'appointments' => $appointments
                ->map(fn (Appointment $appointment): array => $this->serializeAppointment(
                    $appointment,
                    $documentsByAppointment[$appointment->id] ?? [],
                ))
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAppointment(Appointment $appointment, array $documents): array
    {
        $serviceLabel = $appointment->service
            ? sprintf('%s - %s', $appointment->service->type, $appointment->service->name)
            : 'Prestation';
        $location = $this->extractLocationFromAddress((string) $appointment->address);

        return [
            'id' => $appointment->id,
            'service_label' => $serviceLabel,
            'service_type' => $appointment->service?->type,
            'service_name' => $appointment->service?->name,
            'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
            'customer_phone' => $appointment->customer_phone,
            'address' => $appointment->address,
            'postal_code' => $location['postal_code'],
            'city' => $location['city'],
            'latitude' => $appointment->latitude,
            'longitude' => $appointment->longitude,
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'ends_at' => $appointment->ends_at?->toIso8601String(),
            'duration_minutes' => $appointment->duration_minutes,
            'comment' => $appointment->comment,
            'documents' => $documents,
        ];
    }

    /**
     * @return array{postal_code:?string, city:?string}
     */
    private function extractLocationFromAddress(string $address): array
    {
        preg_match('/\b(?<postal_code>\d{5})\b(?:\s+(?<city>[^,]+))?/u', $address, $matches);

        return [
            'postal_code' => $matches['postal_code'] ?? null,
            'city' => isset($matches['city']) ? trim($matches['city']) : null,
        ];
    }
}
