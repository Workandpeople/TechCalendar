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
            'user_id' => WAPetGCUser::factory(), // Automatically create a user
            'phone' => $this->faker->phoneNumber,
            'adresse' => $this->faker->address,
            'zip_code' => $this->faker->postcode,
            'city' => $this->faker->city,
            'default_start_at' => '08:00',
            'default_end_at' => '18:00',
            'default_rest_time' => 60, // in minutes
        ];
    }
}