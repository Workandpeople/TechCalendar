<?php

namespace Database\Factories;

use App\Models\Prestation;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrestationFactory extends Factory
{
    protected $model = Prestation::class;

    public function definition()
    {
        return [
            'type' => $this->faker->randomElement(['MAR', 'AUDIT', 'COFRAC']),
            'name' => $this->faker->word,
            'default_time' => $this->faker->numberBetween(30, 180), // durée en minutes
        ];
    }
}