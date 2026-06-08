<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Collection;

class SimulatedCrmAppointmentService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(int $limit = 5): Collection
    {
        $services = Service::query()
            ->get(['id', 'type', 'name', 'average_duration_minutes'])
            ->keyBy(fn (Service $service): string => $service->type.'|'.$service->name);

        return collect($this->appointments())
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
        ];
    }
}
