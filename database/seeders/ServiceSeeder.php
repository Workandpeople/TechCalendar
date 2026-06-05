<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $services = [
            ['type' => Service::TYPE_COFFRAC, 'name' => 'Controle initial COFFRAC', 'average_duration_minutes' => 180],
            ['type' => Service::TYPE_COFFRAC, 'name' => 'Renouvellement accréditation COFFRAC', 'average_duration_minutes' => 240],
            ['type' => Service::TYPE_COFFRAC, 'name' => 'Audit interne preparatoire COFFRAC', 'average_duration_minutes' => 150],

            ['type' => Service::TYPE_MAR, 'name' => 'Verification marquage MAR', 'average_duration_minutes' => 90],
            ['type' => Service::TYPE_MAR, 'name' => 'Mise en conformite MAR', 'average_duration_minutes' => 120],
            ['type' => Service::TYPE_MAR, 'name' => 'Controle periodique MAR', 'average_duration_minutes' => 75],

            ['type' => Service::TYPE_AUDIT, 'name' => 'Audit qualite site client', 'average_duration_minutes' => 210],
            ['type' => Service::TYPE_AUDIT, 'name' => 'Audit de suivi process', 'average_duration_minutes' => 150],
            ['type' => Service::TYPE_AUDIT, 'name' => 'Audit flash documentaire', 'average_duration_minutes' => 60],
        ];

        foreach ($services as $service) {
            Service::query()->updateOrCreate(
                [
                    'type' => $service['type'],
                    'name' => $service['name'],
                ],
                [
                    'average_duration_minutes' => $service['average_duration_minutes'],
                ]
            );
        }
    }
}
