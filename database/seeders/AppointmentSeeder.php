<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $services = Service::query()->orderBy('type')->orderBy('name')->get();
        $technicians = User::query()
            ->with('services:id,type,name,average_duration_minutes')
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->where('email', 'like', 'tech-dept-%@demo.local')
            ->orderBy('department_code')
            ->get();
        $creator = User::query()->where('admin', true)->orderBy('id')->first()
            ?? User::query()->whereIn('role', [0, 1])->orderBy('id')->first();

        if ($services->isEmpty() || $technicians->isEmpty() || ! $creator) {
            return;
        }

        Appointment::withTrashed()
            ->where('comment', 'like', '[seed-demo]%')
            ->forceDelete();

        $weekStarts = $this->weekStarts(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'));

        foreach ($technicians as $technicianIndex => $technician) {
            foreach ($weekStarts as $weekIndex => $weekStart) {
                $slots = $this->weeklyAppointmentSlots(
                    $technician->services->isNotEmpty() ? $technician->services : $services,
                    $technician,
                    $technicianIndex,
                    $weekIndex,
                    $weekStart
                );

                foreach ($slots as $slotIndex => $slot) {
                    $site = $this->customerSites()[($technicianIndex + $weekIndex + ($slotIndex * 11)) % count($this->customerSites())];
                    $startsAt = Carbon::parse($slot['date']->format('Y-m-d').' '.$slot['starts_at']);
                    $endsAt = (clone $startsAt)->addMinutes((int) $slot['service']->average_duration_minutes);

                    Appointment::query()->create([
                        'service_id' => $slot['service']->id,
                        'technician_id' => $technician->id,
                        'created_by' => $creator->id,
                        'customer_first_name' => $site['first_name'],
                        'customer_last_name' => $site['last_name'],
                        'customer_phone' => $site['phone'],
                        'address' => $site['address'],
                        'latitude' => $site['latitude'],
                        'longitude' => $site['longitude'],
                        'starts_at' => $startsAt,
                        'duration_minutes' => (int) $slot['service']->average_duration_minutes,
                        'ends_at' => $endsAt,
                        'comment' => '[seed-demo] Charge planning technicien 2026 S1.',
                    ]);
                }
            }
        }
    }

    /**
     * @return Collection<int, Carbon>
     */
    private function weekStarts(Carbon $startDate, Carbon $endDate): Collection
    {
        $weekStart = $startDate->copy()->startOfWeek();
        $weeks = collect();

        while ($weekStart->lte($endDate)) {
            $weeks->push($weekStart->copy());
            $weekStart->addWeek();
        }

        return $weeks;
    }

    /**
     * @param Collection<int, Service> $services
     * @return array<int, array{date:Carbon,starts_at:string,service:Service}>
     */
    private function weeklyAppointmentSlots(Collection $services, User $technician, int $technicianIndex, int $weekIndex, Carbon $weekStart): array
    {
        $appointmentsCount = (($technicianIndex + $weekIndex) % 4) === 0 ? 2 : 1;
        $businessDayOffsets = [0, 1, 2, 3, 4];
        $firstDayOffset = $businessDayOffsets[($technicianIndex + ($weekIndex * 2)) % count($businessDayOffsets)];
        $secondDayOffset = $businessDayOffsets[($firstDayOffset + 2 + ($technicianIndex % 2)) % count($businessDayOffsets)];
        $dayOffsets = $appointmentsCount === 2
            ? array_values(array_unique([$firstDayOffset, $secondDayOffset]))
            : [$firstDayOffset];

        if (count($dayOffsets) < $appointmentsCount) {
            $dayOffsets[] = $businessDayOffsets[($firstDayOffset + 3) % count($businessDayOffsets)];
        }

        return collect($dayOffsets)
            ->take($appointmentsCount)
            ->map(function (int $dayOffset, int $slotIndex) use ($services, $technician, $technicianIndex, $weekIndex, $weekStart): array {
                $date = $weekStart->copy()->addDays($dayOffset);
                $service = $services->values()[($technicianIndex + $weekIndex + ($slotIndex * 3)) % $services->count()];

                return [
                    'date' => $date,
                    'starts_at' => $this->startsAtForSlot($date, $technician, $service, $technicianIndex, $weekIndex, $slotIndex),
                    'service' => $service,
                ];
            })
            ->all();
    }

    private function startsAtForSlot(Carbon $date, User $technician, Service $service, int $technicianIndex, int $weekIndex, int $slotIndex): string
    {
        $dayStart = Carbon::parse($date->format('Y-m-d').' '.($technician->day_start_time ?: '08:00'));
        $dayEnd = Carbon::parse($date->format('Y-m-d').' '.($technician->day_end_time ?: '17:00'));
        $offsets = [0, 15, 30, 45, 60, 75, 90];
        $offset = $offsets[($technicianIndex + $weekIndex + $slotIndex) % count($offsets)];
        $duration = (int) $service->average_duration_minutes;

        $startsAt = (($technicianIndex + $weekIndex + $slotIndex) % 2) === 0
            ? (clone $dayStart)->addMinutes($offset)
            : (clone $dayEnd)->subMinutes($duration + $offset);

        if ($startsAt->lt($dayStart)) {
            $startsAt = clone $dayStart;
        }

        if ((clone $startsAt)->addMinutes($duration)->gt($dayEnd)) {
            $startsAt = (clone $dayEnd)->subMinutes($duration);
        }

        return $startsAt->format('H:i');
    }

    /**
     * @return array<int, array{first_name:string,last_name:string,phone:string,address:string,latitude:float,longitude:float}>
     */
    private function customerSites(): array
    {
        return [
            ['first_name' => 'Camille', 'last_name' => 'Martin', 'phone' => '0601020304', 'address' => '10 Rue de la Paix, 75002 Paris, France', 'latitude' => 48.8686, 'longitude' => 2.3316],
            ['first_name' => 'Thomas', 'last_name' => 'Bernard', 'phone' => '0602030405', 'address' => '20 Quai Victor Augagneur, 69003 Lyon, France', 'latitude' => 45.7594, 'longitude' => 4.8456],
            ['first_name' => 'Lea', 'last_name' => 'Robert', 'phone' => '0603040506', 'address' => '5 Rue du Chapeau Rouge, 33000 Bordeaux, France', 'latitude' => 44.8420, 'longitude' => -0.5766],
            ['first_name' => 'Julien', 'last_name' => 'Petit', 'phone' => '0604050607', 'address' => '3 Rue Leon Gambetta, 31000 Toulouse, France', 'latitude' => 43.6047, 'longitude' => 1.4442],
            ['first_name' => 'Sarah', 'last_name' => 'Durand', 'phone' => '0605060708', 'address' => '12 La Canebiere, 13001 Marseille, France', 'latitude' => 43.2965, 'longitude' => 5.3762],
            ['first_name' => 'Nicolas', 'last_name' => 'Moreau', 'phone' => '0606070809', 'address' => '8 Rue Kleber, 67000 Strasbourg, France', 'latitude' => 48.5846, 'longitude' => 7.7477],
            ['first_name' => 'Emma', 'last_name' => 'Simon', 'phone' => '0607080910', 'address' => '14 Rue Royale, 59000 Lille, France', 'latitude' => 50.6409, 'longitude' => 3.0586],
            ['first_name' => 'Hugo', 'last_name' => 'Laurent', 'phone' => '0608091011', 'address' => '4 Rue Graslin, 44000 Nantes, France', 'latitude' => 47.2130, 'longitude' => -1.5603],
            ['first_name' => 'Chloe', 'last_name' => 'Lefevre', 'phone' => '0609101112', 'address' => '6 Rue Jean Jaures, 35000 Rennes, France', 'latitude' => 48.1121, 'longitude' => -1.6800],
            ['first_name' => 'Antoine', 'last_name' => 'Michel', 'phone' => '0610111213', 'address' => '18 Avenue Jean Medecin, 06000 Nice, France', 'latitude' => 43.7034, 'longitude' => 7.2663],
            ['first_name' => 'Manon', 'last_name' => 'Garcia', 'phone' => '0611121314', 'address' => '7 Rue Victor Hugo, 38000 Grenoble, France', 'latitude' => 45.1885, 'longitude' => 5.7245],
            ['first_name' => 'Lucas', 'last_name' => 'Roux', 'phone' => '0612131415', 'address' => '11 Rue Stanislas, 54000 Nancy, France', 'latitude' => 48.6918, 'longitude' => 6.1830],
        ];
    }
}
