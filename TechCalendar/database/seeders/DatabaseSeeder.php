<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\WAPetGCUser;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Utilisateur spécifique
        WAPetGCUser::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'nom' => 'Dinnichert',
            'prenom' => 'Lucas',
            'email' => 'contact@lucas-dinnichert.fr',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // Générer des utilisateurs aléatoires si nécessaire
        WAPetGCUser::factory(10)->create();
    }
}