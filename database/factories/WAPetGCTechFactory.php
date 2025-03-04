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
            'user_id' => WAPetGCUser::factory(), // Génère automatiquement un utilisateur
            'phone' => $this->generateFrenchPhoneNumber(),
            'adresse' => $this->generateFrenchAddress(),
            'zip_code' => $this->faker->numerify('#####'), // Génère un code postal à 5 chiffres
            'city' => $this->faker->city,
            'default_start_at' => '08:00',
            'default_end_at' => '18:00',
            'default_rest_time' => 60, // en minutes
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
