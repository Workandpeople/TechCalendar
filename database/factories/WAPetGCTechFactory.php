<?php

namespace Database\Factories;

use App\Models\WAPetGCTech;
use App\Models\WAPetGCUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WAPetGCTechFactory extends Factory
{
    protected $model = WAPetGCTech::class;

    public function definition()
    {
        return [
            'id' => Str::uuid()->toString(),
            'user_id' => WAPetGCUser::factory(),
            'phone' => $this->generateFrenchPhoneNumber(),
            'adresse' => $this->generateFrenchAddress(),
            'zip_code' => $this->faker->numerify('#####'),
            'city' => $this->faker->city,
            'default_start_at' => '08:00',
            'default_end_at' => '18:00',
            'default_rest_time' => 60,
        ];
    }

    /**
     * Génère un numéro de téléphone français valide (commençant par 06 ou 07).
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
        $streetTypes = ['rue', 'avenue', 'boulevard', 'allée'];
        $streetType = $this->faker->randomElement($streetTypes);
        $streetNames = ['Martin', 'Dupont', 'Durand', 'Lefebvre', 'Moreau', 'Lambert', 'Robert', 'Richard', 'Petit', 'Roux'];
        $streetName = $this->faker->randomElement($streetNames);
        return "$number $streetType de $streetName";
    }
}
