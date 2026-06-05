<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedManagers();
        $this->seedPlanners();
        $this->seedTechnicians();
    }

    private function seedManagers(): void
    {
        $missing = 2 - User::query()->where('admin', false)->where('role', 0)->count();

        if ($missing > 0) {
            User::factory()->count($missing)->create([
                'role' => 0,
                'admin' => false,
                'must_change_password' => false,
            ]);
        }
    }

    private function seedPlanners(): void
    {
        $missing = 2 - User::query()->where('admin', false)->where('role', 1)->count();

        if ($missing > 0) {
            User::factory()->count($missing)->create([
                'role' => 1,
                'admin' => false,
                'must_change_password' => false,
            ]);
        }
    }

    private function seedTechnicians(): void
    {
        $token = (string) config('services.mapbox.token');
        $serviceIds = Service::query()->orderBy('type')->orderBy('name')->pluck('id')->values();

        if ($token === '') {
            throw new RuntimeException('MAPBOX_TOKEN est requis pour le seed des techniciens avec adresses reelles.');
        }

        $faker = fake('fr_FR');

        foreach ($this->techAddresses() as $index => $address) {
            [$latitude, $longitude, $departmentCode] = $this->geocodeAddress($address, $token);

            $departmentCode = $departmentCode ?: $this->fallbackDepartmentCode($address);

            $technician = User::query()->updateOrCreate(
                ['email' => sprintf('tech%02d@demo.local', $index + 1)],
                [
                    'first_name' => $faker->firstName(),
                    'last_name' => $faker->lastName(),
                    'password' => Hash::make('password'),
                    'must_change_password' => false,
                    'role' => 2,
                    'admin' => false,
                    'phone' => sprintf('06%08d', $index + 10000000),
                    'address' => $address,
                    'department_code' => $departmentCode,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'day_start_time' => '08:00',
                    'day_end_time' => '17:00',
                    'break_duration_minutes' => 60,
                    'email_verified_at' => now(),
                ]
            );

            $technician->services()->sync($this->serviceIdsForTechnician($serviceIds, $index));
            $technician->departments()->sync($this->departmentCodesForTechnician($departmentCode));
        }
    }

    private function serviceIdsForTechnician($serviceIds, int $index): array
    {
        if ($serviceIds->isEmpty()) {
            return [];
        }

        return $serviceIds
            ->filter(fn (int $serviceId, int $serviceIndex): bool => ($serviceIndex + $index) % 3 !== 0)
            ->take(5)
            ->values()
            ->all();
    }

    private function departmentCodesForTechnician(?string $departmentCode): array
    {
        if ($departmentCode === null || $departmentCode === '') {
            return [];
        }

        return array_values(array_unique(array_filter([
            $departmentCode,
            ...($this->nearbyDepartmentCodes()[$departmentCode] ?? []),
        ])));
    }

    private function nearbyDepartmentCodes(): array
    {
        return [
            '06' => ['83'],
            '10' => ['51', '89'],
            '13' => ['83', '84'],
            '14' => ['50', '61'],
            '17' => ['85', '79'],
            '18' => ['45', '58'],
            '21' => ['71', '39'],
            '25' => ['39', '70'],
            '26' => ['38', '07'],
            '28' => ['45', '78'],
            '29' => ['56', '22'],
            '31' => ['81', '82'],
            '33' => ['24', '40'],
            '34' => ['30', '11'],
            '35' => ['56', '44'],
            '37' => ['41', '49'],
            '38' => ['73', '26'],
            '42' => ['69', '43'],
            '44' => ['49', '85'],
            '45' => ['28', '41'],
            '49' => ['44', '37'],
            '51' => ['02', '10'],
            '54' => ['57', '88'],
            '56' => ['29', '35'],
            '57' => ['54', '67'],
            '59' => ['62', '02'],
            '63' => ['03', '42'],
            '64' => ['40', '65'],
            '66' => ['11', '09'],
            '67' => ['68', '57'],
            '68' => ['67', '90'],
            '69' => ['42', '38'],
            '72' => ['49', '53'],
            '73' => ['74', '38'],
            '74' => ['73', '01'],
            '75' => ['92', '93', '94'],
            '76' => ['27', '80'],
            '80' => ['62', '76'],
            '81' => ['31', '12'],
            '83' => ['13', '06'],
            '84' => ['13', '30'],
            '85' => ['44', '17'],
            '86' => ['79', '37'],
            '87' => ['19', '23'],
        ];
    }

    private function geocodeAddress(string $address, string $token): array
    {
        $url = sprintf('https://api.mapbox.com/geocoding/v5/mapbox.places/%s.json', urlencode($address));

        $response = Http::timeout(15)->get($url, [
            'access_token' => $token,
            'country' => 'fr',
            'language' => 'fr',
            'limit' => 1,
        ]);

        if (! $response->ok()) {
            throw new RuntimeException(sprintf('Echec geocodage Mapbox (%s): %s', $response->status(), $address));
        }

        $feature = $response->json('features.0');

        if (! is_array($feature) || ! isset($feature['center'][0], $feature['center'][1])) {
            throw new RuntimeException(sprintf('Aucune coordonnee trouvee pour: %s', $address));
        }

        return [
            (float) $feature['center'][1],
            (float) $feature['center'][0],
            $this->extractDepartmentCodeFromFeature($feature),
        ];
    }

    private function extractDepartmentCodeFromFeature(array $feature): ?string
    {
        $contexts = $feature['context'] ?? [];

        foreach ($contexts as $context) {
            if (! str_starts_with((string) ($context['id'] ?? ''), 'postcode')) {
                continue;
            }

            $postcode = (string) ($context['text'] ?? '');

            if ($postcode !== '') {
                return str_starts_with($postcode, '97') ? substr($postcode, 0, 3) : substr($postcode, 0, 2);
            }
        }

        if (preg_match('/\b(\d{2,3})\d{3}\b/', (string) ($feature['place_name'] ?? ''), $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function fallbackDepartmentCode(string $address): ?string
    {
        $codes = [
            'Paris' => '75',
            'Bordeaux' => '33',
            'Toulouse' => '31',
            'Lyon' => '69',
            'Marseille' => '13',
            'Montpellier' => '34',
            'Clermont-Ferrand' => '63',
            'Angers' => '49',
            'Nantes' => '44',
            'Strasbourg' => '67',
            'Nancy' => '54',
            'Lille' => '59',
            'Caen' => '14',
            'Toulon' => '83',
            'Tours' => '37',
            'Limoges' => '87',
            'Orleans' => '45',
            'Metz' => '57',
            'Avignon' => '84',
            'Quimper' => '29',
            'Brest' => '29',
            'Pau' => '64',
            'Rennes' => '35',
            'Reims' => '51',
            'Dijon' => '21',
            'Amiens' => '80',
            'Annecy' => '74',
            'Chambery' => '73',
            'Le Mans' => '72',
            'Rouen' => '76',
            'Saint-Etienne' => '42',
            'Besancon' => '25',
            'Nice' => '06',
            'Perpignan' => '66',
            'La Rochelle' => '17',
            'Mulhouse' => '68',
            'Colmar' => '68',
            'Grenoble' => '38',
            'Poitiers' => '86',
            'Bayonne' => '64',
            'Biarritz' => '64',
            'La Roche-sur-Yon' => '85',
            'Saint-Malo' => '35',
            'Lorient' => '56',
            'Vannes' => '56',
            'Chartres' => '28',
            'Troyes' => '10',
            'Bourges' => '18',
            'Albi' => '81',
            'Valence' => '26',
        ];

        foreach ($codes as $city => $departmentCode) {
            if (str_contains($address, $city)) {
                return $departmentCode;
            }
        }

        return null;
    }

    private function techAddresses(): array
    {
        return [
            "Place de l'Hotel de Ville, Paris, France",
            "Place de la Bourse, Bordeaux, France",
            "Place du Capitole, Toulouse, France",
            "Place Bellecour, Lyon, France",
            "Vieux-Port, Marseille, France",
            "Place de la Comedie, Montpellier, France",
            "Place de Jaude, Clermont-Ferrand, France",
            "Place du Ralliement, Angers, France",
            "Place Royale, Nantes, France",
            "Place Kleber, Strasbourg, France",
            "Place Stanislas, Nancy, France",
            "Place du Theatre, Lille, France",
            "Place Saint-Pierre, Caen, France",
            "Place de la Liberte, Toulon, France",
            "Place du Marechal Leclerc, Tours, France",
            "Place Gambetta, Limoges, France",
            "Place du Martroi, Orleans, France",
            "Place de la Republique, Metz, France",
            "Place de l'Horloge, Avignon, France",
            "Place Saint-Corentin, Quimper, France",
            "Place Jean Jaures, Brest, France",
            "Place de Verdun, Pau, France",
            "Place de la Mairie, Rennes, France",
            "Place de la Cathedrale, Reims, France",
            "Place de la Mairie, Dijon, France",
            "Place de la Republique, Amiens, France",
            "Place de la Mairie, Annecy, France",
            "Place de la Mairie, Chambery, France",
            "Place des Jacobins, Le Mans, France",
            "Place du General de Gaulle, Rouen, France",
            "Place du Theatre, Saint-Etienne, France",
            "Place de l'Hotel de Ville, Besancon, France",
            "Place de la Liberte, Nice, France",
            "Place de la Victoire, Perpignan, France",
            "Place de la Mairie, La Rochelle, France",
            "Place de la Republique, Mulhouse, France",
            "Place de la Mairie, Colmar, France",
            "Place Victor Hugo, Grenoble, France",
            "Place de l'Hotel de Ville, Poitiers, France",
            "Place de la Mairie, Bayonne, France",
            "Place de la Mairie, Biarritz, France",
            "Place de la Mairie, La Roche-sur-Yon, France",
            "Place de la Mairie, Saint-Malo, France",
            "Place de la Mairie, Lorient, France",
            "Place de la Mairie, Vannes, France",
            "Place de la Mairie, Chartres, France",
            "Place de la Mairie, Troyes, France",
            "Place de la Mairie, Bourges, France",
            "Place de la Mairie, Albi, France",
            "Place de la Mairie, Valence, France",
        ];
    }
}
