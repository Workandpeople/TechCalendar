<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $services = Service::query()->orderBy('type')->orderBy('name')->get();
        $technicians = User::query()
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
        $creator = User::query()->where('admin', true)->orderBy('id')->first()
            ?? User::query()->whereIn('role', [0, 1])->orderBy('id')->first();

        if ($services->isEmpty() || $technicians->isEmpty() || ! $creator) {
            return;
        }

        Appointment::query()->where('comment', 'like', '[seed-demo]%')->delete();

        $businessDays = collect(CarbonPeriod::create('2026-01-01', '2026-06-30'))
            ->reject(fn (Carbon $date): bool => $date->isWeekend())
            ->values();

        foreach ($technicians as $technicianIndex => $technician) {
            foreach ($businessDays as $dayIndex => $date) {
                $appointmentsCount = (($technicianIndex + $dayIndex) % 3) === 0 ? 1 : 2;
                $slots = $this->appointmentSlots($services, $technicianIndex, $dayIndex, $appointmentsCount);

                foreach ($slots as $slotIndex => $slot) {
                    $site = $this->customerSites()[($technicianIndex + $dayIndex + ($slotIndex * 7)) % count($this->customerSites())];
                    $startsAt = Carbon::parse($date->format('Y-m-d').' '.$slot['starts_at']);
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
     * @return array<int, array{starts_at:string, service:Service}>
     */
    private function appointmentSlots($services, int $technicianIndex, int $dayIndex, int $appointmentsCount): array
    {
        $shortServices = $services
            ->filter(fn (Service $service): bool => (int) $service->average_duration_minutes <= 180)
            ->values();

        if ($appointmentsCount === 1 || $shortServices->isEmpty()) {
            return [[
                'starts_at' => '08:30',
                'service' => $services[($technicianIndex + $dayIndex) % $services->count()],
            ]];
        }

        return [
            [
                'starts_at' => '08:30',
                'service' => $shortServices[($technicianIndex + $dayIndex) % $shortServices->count()],
            ],
            [
                'starts_at' => '13:30',
                'service' => $shortServices[($technicianIndex + $dayIndex + 3) % $shortServices->count()],
            ],
        ];
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
