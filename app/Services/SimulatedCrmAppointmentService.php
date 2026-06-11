<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Collection;

class SimulatedCrmAppointmentService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(int $limit = 15, bool $shuffle = false): Collection
    {
        $services = Service::query()
            ->get(['id', 'type', 'name', 'average_duration_minutes'])
            ->keyBy(fn (Service $service): string => $service->type.'|'.$service->name);

        $appointments = collect($this->appointments());

        if ($shuffle) {
            $appointments = $appointments->shuffle();
        }

        return $appointments
            ->take($limit)
            ->map(function (array $appointment) use ($services): array {
                $service = $appointment['service_key']
                    ? $services->get($appointment['service_key'])
                    : null;

                unset($appointment['service_key']);

                return [
                    ...$appointment,
                    'service' => $service ? [
                        'id' => $service->id,
                        'type' => $service->type,
                        'name' => $service->name,
                        'average_duration_minutes' => $service->average_duration_minutes,
                    ] : null,
                ];
            })
            ->values();
    }

    public function find(string $id): ?array
    {
        return $this->pending(20)->firstWhere('id', $id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function appointments(): array
    {
        return [
            [
                'id' => 'crm-audit-lyon-001',
                'source' => 'CRM AuditPro',
                'first_name' => 'Camille',
                'last_name' => 'Martin',
                'phone' => '06 12 34 80 69',
                'address' => '20 Rue Bellecordiere, 69002 Lyon',
                'department_code' => '69',
                'latitude' => 45.7569,
                'longitude' => 4.8332,
                'service_key' => Service::TYPE_AUDIT.'|Audit qualite site client',
            ],
            [
                'id' => 'crm-mar-bordeaux-002',
                'source' => 'CRM Controle+',
                'first_name' => 'Julien',
                'last_name' => 'Bernard',
                'phone' => '06 22 45 33 10',
                'address' => '12 Cours de l\'Intendance, 33000 Bordeaux',
                'department_code' => '33',
                'latitude' => 44.8423,
                'longitude' => -0.5773,
                'service_key' => Service::TYPE_MAR.'|Verification marquage MAR',
            ],
            [
                'id' => 'crm-open-nantes-003',
                'source' => 'CRM Legacy',
                'first_name' => 'Nora',
                'last_name' => 'Petit',
                'phone' => '06 48 76 44 21',
                'address' => '8 Place Royale, 44000 Nantes',
                'department_code' => '44',
                'latitude' => 47.2142,
                'longitude' => -1.5586,
                'service_key' => null,
            ],
            [
                'id' => 'crm-coffrac-paris-004',
                'source' => 'CRM Qualite',
                'first_name' => 'Sophie',
                'last_name' => 'Moreau',
                'phone' => '06 91 75 75 12',
                'address' => '4 Rue de Rivoli, 75004 Paris',
                'department_code' => '75',
                'latitude' => 48.8555,
                'longitude' => 2.3572,
                'service_key' => Service::TYPE_COFFRAC.'|Controle initial COFFRAC',
            ],
            [
                'id' => 'crm-audit-toulouse-005',
                'source' => 'CRM AuditPro',
                'first_name' => 'Hugo',
                'last_name' => 'Durand',
                'phone' => '06 31 88 42 19',
                'address' => '6 Place du Capitole, 31000 Toulouse',
                'department_code' => '31',
                'latitude' => 43.6045,
                'longitude' => 1.444,
                'service_key' => Service::TYPE_AUDIT.'|Audit flash documentaire',
            ],
            [
                'id' => 'crm-coffrac-lille-006',
                'source' => 'CRM Qualite',
                'first_name' => 'Lea',
                'last_name' => 'Rousseau',
                'phone' => '06 54 21 78 59',
                'address' => '15 Grand Place, 59000 Lille',
                'department_code' => '59',
                'latitude' => 50.6368,
                'longitude' => 3.0635,
                'service_key' => Service::TYPE_COFFRAC.'|Renouvellement accréditation COFFRAC',
            ],
            [
                'id' => 'crm-mar-marseille-007',
                'source' => 'CRM Controle+',
                'first_name' => 'Mehdi',
                'last_name' => 'Lefevre',
                'phone' => '06 73 41 25 90',
                'address' => '38 Rue Saint-Ferreol, 13001 Marseille',
                'department_code' => '13',
                'latitude' => 43.2951,
                'longitude' => 5.3774,
                'service_key' => Service::TYPE_MAR.'|Controle periodique MAR',
            ],
            [
                'id' => 'crm-audit-strasbourg-008',
                'source' => 'CRM AuditPro',
                'first_name' => 'Alice',
                'last_name' => 'Garnier',
                'phone' => '06 82 14 63 77',
                'address' => '16 Place de la Cathedrale, 67000 Strasbourg',
                'department_code' => '67',
                'latitude' => 48.5819,
                'longitude' => 7.7508,
                'service_key' => Service::TYPE_AUDIT.'|Audit de suivi process',
            ],
            [
                'id' => 'crm-open-rennes-009',
                'source' => 'CRM Legacy',
                'first_name' => 'Ines',
                'last_name' => 'Faure',
                'phone' => '06 39 18 65 44',
                'address' => '3 Rue de la Monnaie, 35000 Rennes',
                'department_code' => '35',
                'latitude' => 48.1118,
                'longitude' => -1.6829,
                'service_key' => null,
            ],
            [
                'id' => 'crm-coffrac-nice-010',
                'source' => 'CRM Qualite',
                'first_name' => 'Antoine',
                'last_name' => 'Mercier',
                'phone' => '06 67 92 10 31',
                'address' => '1 Avenue Jean Medecin, 06000 Nice',
                'department_code' => '06',
                'latitude' => 43.7006,
                'longitude' => 7.2684,
                'service_key' => Service::TYPE_COFFRAC.'|Audit interne preparatoire COFFRAC',
            ],
            [
                'id' => 'crm-mar-grenoble-011',
                'source' => 'CRM Controle+',
                'first_name' => 'Manon',
                'last_name' => 'Chevalier',
                'phone' => '06 24 57 83 16',
                'address' => '5 Place Grenette, 38000 Grenoble',
                'department_code' => '38',
                'latitude' => 45.1916,
                'longitude' => 5.7281,
                'service_key' => Service::TYPE_MAR.'|Mise en conformite MAR',
            ],
            [
                'id' => 'crm-audit-dijon-012',
                'source' => 'CRM AuditPro',
                'first_name' => 'Baptiste',
                'last_name' => 'Lambert',
                'phone' => '06 58 11 37 92',
                'address' => '11 Rue de la Liberte, 21000 Dijon',
                'department_code' => '21',
                'latitude' => 47.3216,
                'longitude' => 5.0415,
                'service_key' => Service::TYPE_AUDIT.'|Audit qualite site client',
            ],
            [
                'id' => 'crm-open-reims-013',
                'source' => 'CRM Legacy',
                'first_name' => 'Clara',
                'last_name' => 'Noel',
                'phone' => '06 77 43 29 05',
                'address' => '2 Place Drouet d\'Erlon, 51100 Reims',
                'department_code' => '51',
                'latitude' => 49.2559,
                'longitude' => 4.0252,
                'service_key' => null,
            ],
            [
                'id' => 'crm-coffrac-angers-014',
                'source' => 'CRM Qualite',
                'first_name' => 'Theo',
                'last_name' => 'Robin',
                'phone' => '06 35 74 12 68',
                'address' => '24 Rue Lenepveu, 49100 Angers',
                'department_code' => '49',
                'latitude' => 47.4717,
                'longitude' => -0.5516,
                'service_key' => Service::TYPE_COFFRAC.'|Controle initial COFFRAC',
            ],
            [
                'id' => 'crm-mar-caen-015',
                'source' => 'CRM Controle+',
                'first_name' => 'Sarah',
                'last_name' => 'Masson',
                'phone' => '06 28 90 54 72',
                'address' => '12 Rue Saint-Pierre, 14000 Caen',
                'department_code' => '14',
                'latitude' => 49.1832,
                'longitude' => -0.3695,
                'service_key' => Service::TYPE_MAR.'|Verification marquage MAR',
            ],
        ];
    }
}
