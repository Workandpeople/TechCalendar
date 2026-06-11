<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if ($this->command?->getLaravel()->environment('production')) {
            $this->call([
                AdminUserSeeder::class,
            ]);

            return;
        }

        $this->call([
            AdminUserSeeder::class,
            ServiceSeeder::class,
            DemoUsersSeeder::class,
            AppointmentSeeder::class,
        ]);
    }
}
