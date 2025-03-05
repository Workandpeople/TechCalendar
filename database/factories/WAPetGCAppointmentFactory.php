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
        $startAt = Carbon::create(2025, 3, rand(1, 31), rand(8, 16), rand(0, 59), 0);
        $duration = $this->faker->randomElement([30, 60, 90, 120]);
        $endAt = (clone $startAt)->addMinutes($duration);

        return [
            'id' => Str::uuid()->toString(),
            'tech_id' => WAPetGCTech::inRandomOrder()->first()->id ?? WAPetGCTech::factory(),
            'service_id' => WAPetGCService::inRandomOrder()->first()->id ?? WAPetGCService::factory(),
            'client_fname' => $this->faker->firstName,
            'client_lname' => $this->faker->lastName,
            'client_adresse' => $this->generateFrenchAddress(),
            'client_zip_code' => $this->faker->postcode,
            'client_city' => $this->faker->city,
            'client_phone' => $this->generateFrenchPhoneNumber(),
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'duration' => $duration,
            'end_at' => $endAt->format('Y-m-d H:i:s'),
            'comment' => $this->faker->sentence,
            'trajet_time' => $this->faker->numberBetween(10, 60),
            'trajet_distance' => $this->faker->randomFloat(2, 1, 100),
        ];
    }

    private function generateFrenchPhoneNumber()
    {
        return '0' . $this->faker->randomElement(['6', '7']) . $this->faker->numerify('########');
    }

    private function generateFrenchAddress()
    {
        return $this->faker->numberBetween(1, 999) . ' ' . $this->faker->randomElement(['rue', 'avenue', 'boulevard']) . ' ' . $this->faker->streetName;
    }
}
