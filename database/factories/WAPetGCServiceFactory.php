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
            'name' => ucfirst($this->faker->word) . ' ' . ucfirst($this->faker->word),
            'default_time' => $this->faker->randomElement([30, 60, 90, 120]),
        ];
    }
}
