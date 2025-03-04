<?php

namespace Database\Factories;

use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WAPetGCAppointmentFactory extends Factory
{
    protected $model = WAPetGCAppointment::class;

    public function definition()
    {
        // Générer une date entre le lundi et le vendredi
        $startAt = $this->faker->dateTimeBetween('2025-01-01', '2025-12-31');
        while (in_array($startAt->format('N'), [6, 7])) { // Évite samedi (6) et dimanche (7)
            $startAt = $this->faker->dateTimeBetween('2025-01-01', '2025-12-31');
        }

        // Ajuster l'heure entre 8h et 18h
        $startAt->setTime($this->faker->numberBetween(8, 17), $this->faker->numberBetween(0, 59));

        $duration = $this->faker->numberBetween(30, 120); // Durée aléatoire entre 30 et 120 minutes
        $endAt = (clone $startAt)->modify("+{$duration} minutes");

        return [
            'id' => Str::uuid()->toString(),
            'tech_id' => WAPetGCTech::inRandomOrder()->first()->id ?? WAPetGCTech::factory(),
            'service_id' => WAPetGCService::inRandomOrder()->first()->id ?? WAPetGCService::factory(),
            'client_fname' => $this->faker->firstName,
            'client_lname' => $this->faker->lastName,
            'client_adresse' => $this->generateFrenchAddress(),
            'client_zip_code' => $this->faker->numerify('#####'), // Génère un code postal à 5 chiffres
            'client_city' => $this->faker->city,
            'client_phone' => $this->generateFrenchPhoneNumber(),
            'start_at' => $startAt,
            'duration' => $duration,
            'end_at' => $endAt,
            'comment' => $this->faker->sentence,
            'trajet_time' => $this->faker->numberBetween(10, 60),
            'trajet_distance' => $this->faker->randomFloat(2, 1, 100),
        ];
    }

    private function generateFrenchPhoneNumber()
    {
        $prefix = $this->faker->randomElement(['06', '07']);
        $suffix = $this->faker->numerify('########');
        return $prefix . $suffix;
    }

    private function generateFrenchAddress()
    {
        $streetTypes = ['rue', 'avenue', 'boulevard', 'allée', 'chemin', 'place'];
        return $this->faker->numberBetween(1, 999) . ' ' .
               $this->faker->randomElement($streetTypes) . ' ' .
               $this->faker->streetName;
    }
}
