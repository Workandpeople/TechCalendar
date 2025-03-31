<?php

namespace Database\Factories;

use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon;

class WAPetGCAppointmentFactory extends Factory
{
    protected $model = WAPetGCAppointment::class;

    public function definition()
    {
        // Création d'une date de début aléatoire en mars 2025 entre 8h et 16h
        $startAt = Carbon::create(2025, 3, $this->faker->numberBetween(1, 31), $this->faker->numberBetween(8, 16), $this->faker->numberBetween(0, 59), 0);
        // Durée choisie parmi 30, 60, 90 ou 120 minutes
        $duration = $this->faker->randomElement([30, 60, 90, 120]);
        $endAt = (clone $startAt)->addMinutes($duration);

        return [
            'id'                => Str::uuid()->toString(),
            'tech_id'           => WAPetGCTech::inRandomOrder()->first()->id ?? WAPetGCTech::factory(),
            'service_id'        => WAPetGCService::inRandomOrder()->first()->id ?? WAPetGCService::factory(),
            'client_fname'      => $this->faker->firstName,
            'client_lname'      => $this->faker->lastName,
            'client_adresse'    => $this->generateFrenchAddress(),
            'client_zip_code'   => $this->faker->numerify('#####'),
            'client_city'       => $this->faker->city,
            'client_phone'      => $this->generateFrenchPhoneNumber(),
            'start_at'          => $startAt->format('Y-m-d H:i:s'),
            'duration'          => $duration,
            'end_at'            => $endAt->format('Y-m-d H:i:s'),
            'comment'           => $this->faker->sentence,
            'trajet_time'       => $this->faker->numberBetween(10, 60),
            'trajet_distance'   => $this->faker->randomFloat(2, 1, 100),
        ];
    }

    /**
     * Génère un numéro de téléphone français valide (commençant par 06 ou 07).
     * Exemple : "0612345678"
     */
    private function generateFrenchPhoneNumber()
    {
        return '0' . $this->faker->randomElement(['6', '7']) . $this->faker->numerify('########');
    }

    /**
     * Génère une adresse au format français.
     * Exemple : "123 rue de Martin"
     */
    private function generateFrenchAddress()
    {
        $number = $this->faker->numberBetween(1, 999);
        $streetTypes = ['rue', 'avenue', 'boulevard', 'allée', 'impasse'];
        $streetType = $this->faker->randomElement($streetTypes);
        // Liste de noms de rue typiques en France
        $streetNames = ['Martin', 'Dupont', 'Durand', 'Lefebvre', 'Moreau', 'Lambert', 'Robert', 'Richard', 'Petit', 'Roux'];
        $streetName = $this->faker->randomElement($streetNames);
        return "$number $streetType de $streetName";
    }
}
