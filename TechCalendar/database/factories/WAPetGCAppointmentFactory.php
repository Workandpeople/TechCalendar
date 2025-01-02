<?php

namespace Database\Factories;

use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WAPetGCAppointmentFactory extends Factory
{
    protected $model = WAPetGCAppointment::class;

    public function definition()
    {
        $startAt = $this->faker->dateTimeBetween('2025-01-01', '2025-12-31');

        return [
            'id' => Str::uuid()->toString(),
            'tech_id' => WAPetGCTech::factory(),
            'client_fname' => $this->faker->firstName,
            'client_lname' => $this->faker->lastName,
            'client_adresse' => $this->faker->address,
            'client_zip_code' => $this->faker->postcode,
            'client_city' => $this->faker->city,
            'client_phone' => $this->faker->phoneNumber,
            'start_at' => $startAt,
            'duration' => $this->faker->numberBetween(30, 120),
            'end_at' => $startAt->modify('+1 hour'),
            'comment' => $this->faker->sentence,
            'trajet_time' => $this->faker->numberBetween(10, 60),
            'trajet_distance' => $this->faker->randomFloat(2, 1, 100),
        ];
    }
}