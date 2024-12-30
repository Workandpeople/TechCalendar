<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WAPetGCUser>
 */
class WAPetGCUserFactory extends Factory
{
    protected $model = \App\Models\WAPetGCUser::class;

    public function definition()
    {
        return [
            'id' => Str::uuid()->toString(),
            'nom' => $this->faker->lastName(),
            'prenom' => $this->faker->firstName(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('password'), // Default password
            'role' => $this->faker->randomElement(['tech', 'assistante', 'admin']),
            
        ];
    }

    public function admin()
    {
        return $this->state([
            'role' => 'admin',
        ]);
    }
}