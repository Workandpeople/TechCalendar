<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\TechnicianDailyRouteMetric;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MobilePlanningController extends Controller
{
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
                'next_appointment' => $nextAppointment ? $this->serializeAppointment($nextAppointment) : null,
            ],
            'appointments' => $appointments
                ->map(fn (Appointment $appointment): array => $this->serializeAppointment($appointment))
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAppointment(Appointment $appointment): array
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
            'documents' => $this->serializeDocuments($appointment),
        ];
    }

    /**
     * @return array<int, array{
     *     id: mixed,
     *     scope: ?string,
     *     name: string,
     *     comment: ?string,
     *     url: ?string,
     *     is_private: bool,
     *     is_delegataire: bool
     * }>
     */
    private function serializeDocuments(Appointment $appointment): array
    {
        $documents = data_get($appointment->external_payload, 'documents', []);

        if (! is_array($documents)) {
            return [];
        }

        return collect($documents)
            ->filter(fn (mixed $document): bool => is_array($document))
            ->map(function (array $document): array {
                $name = trim((string) ($document['name'] ?? $document['filename'] ?? 'Document'));
                $comment = trim((string) ($document['comment'] ?? ''));
                $scope = trim((string) ($document['scope'] ?? ''));
                $url = trim((string) ($document['url'] ?? ''));

                return [
                    'id' => $document['id'] ?? null,
                    'scope' => $scope !== '' ? $scope : null,
                    'name' => $name !== '' ? $name : 'Document',
                    'comment' => $comment !== '' ? $comment : null,
                    'url' => $url !== '' ? $url : null,
                    'is_private' => (bool) ($document['is_private'] ?? false),
                    'is_delegataire' => (bool) ($document['is_delegataire'] ?? false),
                ];
            })
            ->values()
            ->all();
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
