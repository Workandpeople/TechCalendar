<?php

namespace Database\Factories;

use App\Models\WAPetGCUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WAPetGCUserFactory extends Factory
{
    protected $model = WAPetGCUser::class;

    public function definition()
    {
        return [
            'id' => Str::uuid()->toString(),
            'nom' => $this->faker->lastName,
            'prenom' => $this->faker->firstName,
            'email' => $this->faker->unique()->safeEmail,
            'password' => bcrypt('password'),
            'role' => 'tech', // Default role, override in seeders if necessary
        ];
    }

    public function admin()
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function assistante()
    {
        return $this->state(fn () => ['role' => 'assistante']);
    }

    public function customAdmin()
    {
        return $this->state(fn () => [
            'email' => 'contact@lucas-dinnichert.fr',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }
}
