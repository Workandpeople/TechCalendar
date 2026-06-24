<?php

namespace Database\Seeders;

use App\Models\ExternalServiceAlias;
use App\Models\Service;
use App\Services\CoffracAppointmentService;
use Illuminate\Database\Seeder;

class CoffracServiceAliasSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const ALIASES_BY_TECHCALENDAR_SERVICE = [
        'AGRI TH 117' => [
            'AGRI TH 117',
            'SAV AGRI TH 117',
            'VIso Controle AGRI 117',
        ],
        'BAR EN 101' => [
            'BAR EN 101 Isolation en déroulé',
            'BAR EN 101 Isolation en combles perdus',
        ],
        'BAR EN 103' => [
            'BAR EN 103 ISOLATION D’UN PLANCHER',
        ],
        'BAR TH 125 TERTIAIRE' => [
            'BAT-TH-125',
        ],
        'BAR TH 127' => [
            'BAR TH 127 (VMC simple flux hygroréglable)',
        ],
        'BAR TH 145 APRES TRAVAUX' => [
            'BAR 145 TRAVAUX',
        ],
        'BAR TH 145 AUDIT' => [
            'BAR 145 AUDIT',
        ],
        'BAR TH 160' => [
            'BAR-TH-160 Isolation d’un réseau hydraulique de chauffage',
        ],
        'BAR TH 161' => [
            'BAR-TH-161 Isolation de points singuliers d’un réseau',
        ],
        'BAR TH 171' => [
            'BAR TH 104 air/eau / aprés 01/04/22',
            'BAR-TH-171 PAC AIR/EAU',
            'BAR-TH-171 (2026)',
        ],
        'BAR TH 177 P1 AVANT TRAVAUX' => [
            'BAR-TH-177-P1 Rénovation globale d’un bâtiment résidentiel collectif en phase 1',
        ],
        'BAR TH 177 P2' => [
            "BAR TH 177_P2 Rénovation d'ampleur d'un batiment résidentiel collectif après travaux",
        ],
        'BAT EQ 127 (VOLONTAIRE)' => [
            'BAT EQ 127',
            'SAV BAT EQ 127',
            'Viso Contrôle BAT EQ 127',
            'contrôle volontaire',
        ],
        'BAT TH 146' => [
            'BAT-TH-146 Isolation d’un réseau hydraulique de chauffage',
        ],
        'BAT TH 155' => [
            'BAT-TH-155 Isolation de points singuliers d’un réseau',
        ],
        'RES EC 104' => [
            'RES EC 104 LUMINAIRE',
            'SAV - RES EC 104',
            'PREV LED',
        ],
        'RES EC 104 2025' => [
            'RES EC 104 (01/01/25)',
            'SAV RES EC 104 2025',
        ],
    ];

    /**
     * Ces libellés Coffrac restent non liés car la correspondance métier est ambiguë
     * ou aucune prestation TechCalendar cible n'existe encore.
     *
     * @var list<string>
     */
    private const UNMAPPED_COFFRAC_SERVICES = [
        'BAT EN 101 Isolation en rampant de toiture',
        'BAR EN 102 ISOLATION DES MURS',
        'th 164 /2021',
        'th 164 /22 / audit',
        'th 164 /22 / travaux',
        'Audit',
        'BAT EN 102',
        'BAT EN 103 Isolation du plancher bas',
        'BAT TH 116 GTB Gestion Technique du Bâtiment',
        'BAR TH 113',
        'BAR-TH-143 système solaire combiné (SSC)',
        'BAR-TH-159 Pompe à chaleur hybride individuelle',
        'RES-CH-107 Isolation de points singuliers sur un réseau de chaleur',
        'BAR-TH-174 Rénovation d’ampleur d’une maison individuelle',
        'PREV DESTRAT',
        'BAR-EN-105 Isolation des toitures terrasses',
        'BAR-TH-172 Pompe à chaleur de type eau/eau ou sol/eau',
        'IND-UT-121',
        'BAR TH 175 APRES TRAVAUX',
        'AGRI EQ 108',
        'EQ 108',
        'IND UT 131',
        'PREVISITE BAT TH 163',
        'PREVISITE VMC TERTIAIRE',
        'PREVISITE VMC COLLECTIF',
    ];

    public function run(): void
    {
        $servicesByNormalizedName = Service::query()
            ->where('type', Service::TYPE_COFFRAC)
            ->get(['id', 'name'])
            ->keyBy(fn (Service $service): string => ExternalServiceAlias::normalizeValue($service->name));
        $createdOrUpdatedAliases = 0;
        $missingTargets = [];

        foreach (self::ALIASES_BY_TECHCALENDAR_SERVICE as $serviceName => $aliases) {
            $service = $servicesByNormalizedName->get(ExternalServiceAlias::normalizeValue($serviceName));

            if (! $service) {
                $missingTargets[] = $serviceName;

                continue;
            }

            foreach ($aliases as $alias) {
                ExternalServiceAlias::query()->updateOrCreate(
                    [
                        'source' => CoffracAppointmentService::SOURCE,
                        'normalized_external_type' => ExternalServiceAlias::normalizeValue(Service::TYPE_COFFRAC),
                        'normalized_external_name' => ExternalServiceAlias::normalizeValue($alias),
                    ],
                    [
                        'service_id' => $service->id,
                        'external_type' => Service::TYPE_COFFRAC,
                        'external_name' => $alias,
                    ],
                );

                $createdOrUpdatedAliases++;
            }
        }

        $this->command?->info(sprintf('%d alias Coffrac synchronisé(s).', $createdOrUpdatedAliases));

        if ($missingTargets !== []) {
            $this->command?->warn('Prestations TechCalendar absentes, alias ignorés: '.implode(', ', $missingTargets));
        }

        $this->command?->warn('Libellés Coffrac volontairement non liés: '.implode(', ', self::UNMAPPED_COFFRAC_SERVICES));
    }
}
