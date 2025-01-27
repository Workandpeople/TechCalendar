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
        // Créer les utilisateurs (techniciens, assistantes, admins)
        WAPetGCUser::factory(0)->create(); // 50 techniciens
        WAPetGCUser::factory(0)->assistante()->create(); // 10 assistantes
        WAPetGCUser::factory(0)->admin()->create(); // 10 admins
        WAPetGCUser::factory()->customAdmin()->create(); // Specific admin user

        // Créer des services
        $services = WAPetGCService::factory(0)->create();

        // Créer les techniciens avec des rendez-vous associés
        WAPetGCTech::factory(0)
            ->has(
                WAPetGCAppointment::factory(0)->state(function () use ($services) {
                    return [
                        'service_id' => $services->random()->id, // Associer un service existant
                    ];
                }),
                'appointments'
            )
            ->create();
    }
}
