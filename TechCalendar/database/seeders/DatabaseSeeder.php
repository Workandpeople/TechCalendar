<?php

namespace Database\Seeders;

use App\Models\WAPetGCUser;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCService;
use App\Models\WAPetGCAppointment;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // 200 users
        WAPetGCUser::factory(189)->create(); // 180 techniciens + 9 assistantes
        WAPetGCUser::factory(10)->admin()->create();
        WAPetGCUser::factory()->customAdmin()->create(); // Specific admin user
        WAPetGCUser::factory(10)->assistante()->create();

        // 20 services
        WAPetGCService::factory(20)->create();

        // 180 technicians with appointments
        WAPetGCTech::factory(180)->has(
            WAPetGCAppointment::factory(200), 'appointments'
        )->create();
    }
}