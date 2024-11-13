<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition()
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(), // Associe automatiquement un utilisateur
            'role' => $this->faker->randomElement(['administrateur', 'assistante', 'technicien']),
        ];
    }

    public function administrateur()
    {
        return $this->state([
            'role' => 'administrateur',
        ]);
    }

    public function assistante()
    {
        return $this->state([
            'role' => 'assistante',
        ]);
    }

    public function technicien()
    {
        return $this->state([
            'role' => 'technicien',
        ]);
    }
}