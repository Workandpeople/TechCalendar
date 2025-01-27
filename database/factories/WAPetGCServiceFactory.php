<?php

namespace Database\Factories;

use App\Models\WAPetGCService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WAPetGCServiceFactory extends Factory
{
    protected $model = WAPetGCService::class;

    public function definition()
    {
        return [
            'id' => Str::uuid()->toString(),
            'type' => $this->faker->randomElement(['MAR', 'COFRAC', 'AUDIT']),
            'name' => $this->faker->words(2, true),
            'default_time' => $this->faker->numberBetween(30, 120),
        ];
    }
}
