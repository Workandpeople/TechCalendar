<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminEmail = (string) env('ADMIN_MAIL');
        $adminPassword = (string) env('ADMIN_PASSWORD');

        if ($adminEmail === '' || $adminPassword === '') {
            throw new RuntimeException('ADMIN_MAIL et ADMIN_PASSWORD doivent etre definis dans le fichier .env.');
        }

        User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'first_name' => 'Admin',
                'last_name' => 'Genius',
                'password' => Hash::make($adminPassword),
                'must_change_password' => false,
                'role' => 0,
                'admin' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
