<?php

namespace Database\Seeders;

use App\Models\WAPetGCUser;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCService;
use App\Models\WAPetGCAppointment;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Créer 10 techniciens, 1 assistante et 1 admin custom
        $users = WAPetGCUser::factory(10)->create(); // 10 techniciens
        $assistante = WAPetGCUser::factory()->assistante()->create(); // 1 assistante
        $admin = WAPetGCUser::factory()->customAdmin()->create(); // 1 admin personnalisé

        // Créer 20 services
        $services = WAPetGCService::factory(20)->create();

        // Associer chaque technicien à un profil de technicien
        $techniciens = $users->map(function ($user) {
            return WAPetGCTech::factory()->create(['user_id' => $user->id]);
        });

        // Générer 2 RDV par jour pour chaque technicien en mars 2025
        $this->generateAppointmentsForMarch($techniciens, $services);
    }

    private function generateAppointmentsForMarch($techniciens, $services)
    {
        foreach ($techniciens as $tech) {
            $date = Carbon::create(2025, 3, 1, 8, 0, 0); // Début Mars 2025 à 08h00

            while ($date->month === 3) {
                if (!$date->isWeekend()) { // Exclure samedis et dimanches
                    // Sélectionner un service aléatoire
                    $morningService = $services->random();
                    $afternoonService = $services->random();

                    // Générer le rendez-vous du matin
                    $morningStart = $date->copy()->setTime(rand(8, 10), rand(0, 59)); // Entre 8h et 10h
                    $morningEnd = (clone $morningStart)->addMinutes($morningService->default_time);

                    // Générer le rendez-vous de l'après-midi
                    $afternoonStart = $date->copy()->setTime(rand(13, 16), rand(0, 59)); // Entre 13h et 16h
                    $afternoonEnd = (clone $afternoonStart)->addMinutes($afternoonService->default_time);

                    // Créer les RDV
                    WAPetGCAppointment::create([
                        'id' => Str::uuid(),
                        'tech_id' => $tech->id,
                        'service_id' => $morningService->id,
                        'client_fname' => fake()->firstName(),
                        'client_lname' => fake()->lastName(),
                        'client_adresse' => fake()->streetAddress(),
                        'client_zip_code' => fake()->postcode(),
                        'client_city' => fake()->city(),
                        'client_phone' => '0' . rand(6, 7) . fake()->numerify('########'),
                        'start_at' => $morningStart->format('Y-m-d H:i:s'),
                        'duration' => $morningService->default_time,
                        'end_at' => $morningEnd->format('Y-m-d H:i:s'),
                        'comment' => fake()->sentence(),
                        'trajet_time' => rand(10, 60),
                        'trajet_distance' => fake()->randomFloat(2, 1, 100),
                    ]);

                    WAPetGCAppointment::create([
                        'id' => Str::uuid(),
                        'tech_id' => $tech->id,
                        'service_id' => $afternoonService->id,
                        'client_fname' => fake()->firstName(),
                        'client_lname' => fake()->lastName(),
                        'client_adresse' => fake()->streetAddress(),
                        'client_zip_code' => fake()->postcode(),
                        'client_city' => fake()->city(),
                        'client_phone' => '0' . rand(6, 7) . fake()->numerify('########'),
                        'start_at' => $afternoonStart->format('Y-m-d H:i:s'),
                        'duration' => $afternoonService->default_time,
                        'end_at' => $afternoonEnd->format('Y-m-d H:i:s'),
                        'comment' => fake()->sentence(),
                        'trajet_time' => rand(10, 60),
                        'trajet_distance' => fake()->randomFloat(2, 1, 100),
                    ]);
                }

                $date->addDay(); // Passer au jour suivant
            }
        }
    }
}
