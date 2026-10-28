<?php

namespace App\Services\Tech;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use App\Services\Crm\DossierTable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class TechnicianWorkspace
{
    public function canAccess(?User $user): bool
    {
        return $user && ! $user->trashed() && ! $user->isExternal() && ($user->admin || $user->role === 2);
    }

    public function appointmentsQuery(User $user): Builder
    {
        return Appointment::query()->where('technician_id', $user->id)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_PROBLEM])
            ->when(config('crm.v2_enabled'), fn ($query) => $query->onPlanning())
            ->with(['service:id,type,name', 'dossierOperation.dossier']);
    }

    public function overview(User $user, ?string $date): array
    {
        $day = $date ? Carbon::createFromFormat('!Y-m-d', $date) : now()->startOfDay();
        $start = $day->copy()->startOfWeek();
        $end = $start->copy()->addWeek();
        $weekAppointments = $this->appointmentsQuery($user)->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->get();
        $dailyAppointments = $this->appointmentsQuery($user)->where('starts_at', '<', $day->copy()->addDay())
            ->where('ends_at', '>', $day)->orderBy('starts_at')->orderBy('id')->get();
        $next = $this->appointmentsQuery($user)->where('ends_at', '>', now())->orderBy('starts_at')->first();
        // Route metrics are maintained by the scheduled calculation, not recalculated on every mobile visit.
        $metrics = TechnicianDailyRouteMetric::query()->where('technician_id', $user->id)
            ->where('service_date', '>=', $start->toDateString())->where('service_date', '<', $end->toDateString())->get();

        return [
            'technician' => $user, 'selectedDay' => $day,
            'dayCards' => $dailyAppointments->map(fn ($appointment) => ['appointment' => $appointment, ...$this->card($appointment)]),
            'nextCard' => $next ? ['appointment' => $next, ...$this->card($next)] : null,
            'weekDays' => collect(range(0, 6))->map(fn ($offset) => $start->copy()->addDays($offset)),
            'weekCounts' => $weekAppointments->groupBy(fn ($appointment) => $appointment->starts_at->toDateString())->map->count(),
            'mapboxToken' => config('services.mapbox.token'),
            'stats' => [
                ['label' => 'RDV cette semaine', 'value' => $weekAppointments->count()],
                ['label' => 'Heures planifiées', 'value' => round($weekAppointments->sum('duration_minutes') / 60, 1).' h'],
                ['label' => 'Kilomètres calculés', 'value' => $metrics->isEmpty() ? '—' : round($metrics->sum('drive_distance_km'), 1).' km'],
                ['label' => 'Temps de route', 'value' => $metrics->isEmpty() ? '—' : round($metrics->sum('drive_duration_minutes') / 60, 1).' h'],
                ['label' => 'Heures supplémentaires', 'value' => $metrics->isEmpty() ? '—' : round($metrics->sum('overtime_minutes') / 60, 1).' h'],
            ],
        ];
    }

    public function card(Appointment $appointment): array
    {
        $phone = app(DossierTable::class)->phoneNumbers(new Dossier(['phone' => $appointment->customer_phone]))[0] ?? null;
        $coordinates = $appointment->latitude !== null && $appointment->longitude !== null
            && abs($appointment->latitude) <= 90 && abs($appointment->longitude) <= 180;
        $destination = $coordinates ? $appointment->latitude.','.$appointment->longitude : $appointment->address;

        return [
            'customer' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name) ?: 'Client non renseigné',
            'service' => $appointment->service?->name ?: 'Prestation non renseignée',
            'phone' => $phone, 'address' => $appointment->address,
            'maps_url' => filled($destination) ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($destination) : null,
            'detail_url' => config('crm.v2_enabled') ? route('tech.appointments.show', $appointment) : null,
            'ongoing' => $appointment->starts_at <= now() && $appointment->ends_at > now(),
        ];
    }
}
