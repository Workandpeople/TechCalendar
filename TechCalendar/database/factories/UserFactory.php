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
        $startTimes = ['07:00:00', '07:30:00', '08:00:00', '08:30:00', '09:00:00'];
        $defaultTrajectTime = $this->faker->numberBetween(120, 180);
        $defaultRestTime = $this->faker->numberBetween(30, 60);
        $start = $this->faker->randomElement($startTimes);
        $end = date('H:i:s', strtotime($start) + ($defaultRestTime + 8 * 60) * 60);

        return [
            'id' => (string) Str::uuid(),
            'nom' => $this->faker->lastName,
            'prenom' => $this->faker->firstName,
            'email' => $this->faker->unique()->safeEmail,
            'password' => bcrypt('password'), // Mot de passe par défaut
            'telephone' => $this->faker->regexify('(06|07)[0-9]{8}'),
            'adresse' => $this->faker->numberBetween(1, 200) . ' ' . $this->faker->randomElement(['rue', 'boulevard', 'avenue']) . ' ' . $this->faker->word,
            'code_postal' => $this->faker->regexify('[0-9]{4}0'),
            'ville' => $this->faker->city,
            'default_start_at' => $start,
            'default_end_at' => $end,
            'default_traject_time' => $defaultTrajectTime, // minutes
            'default_rest_time' => $defaultRestTime, // minutes
        ];
    }

    public function adminData()
    {
        return $this->state(function (array $attributes) {
            return [
                'adresse' => null,
                'telephone' => null,
                'code_postal' => null,
                'ville' => null,
                'default_start_at' => null,
                'default_end_at' => null,
                'default_traject_time' => null,
                'default_rest_time' => null,
            ];
        });
    }
}