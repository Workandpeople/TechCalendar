<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'id' => (string) Str::uuid(),
            'nom' => $this->faker->lastName,
            'prenom' => $this->faker->firstName,
            'email' => $this->faker->unique()->safeEmail,
            'password' => bcrypt('password'),  // Mot de passe par défaut
            'telephone' => $this->faker->phoneNumber,
            'adresse' => $this->faker->streetAddress,
            'code_postal' => $this->faker->postcode,
            'ville' => $this->faker->city,
            'default_start_at' => $this->faker->time('H:i:s', '08:00'),
            'default_end_at' => $this->faker->time('H:i:s', '17:00'),
            'default_traject_time' => $this->faker->numberBetween(15, 60), // minutes
            'default_rest_time' => $this->faker->numberBetween(30, 60), // minutes
        ];
    }
}