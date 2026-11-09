<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use App\Models\User;
use App\Services\Crm\DossierLocation;
use App\Services\PlanningAddressGeocoder;
use Illuminate\Validation\ValidationException;

class MymarBookingPlanning
{
    public function __construct(private MymarPendingBookings $pending, private MymarActionSettings $settings, private PlanningAddressGeocoder $geocoder) {}

    public function resolve(int $id): array
    {
        $config = $this->settings->load();
        if (! config('crm.v2_enabled') || ! ($config['writes_enabled'] ?? false)) {
            throw ValidationException::withMessages(['mymar_dossier_id' => 'Le placement MAR n’est pas activé.']);
        }
        $item = $this->pending->find($id);
        if ($item === null || ! MymarPendingBookings::isAfterWorksRequest($item)) {
            throw ValidationException::withMessages(['mymar_dossier_id' => 'Seules les demandes MAR après travaux en prise de RDV peuvent être placées ici. Actualisez la liste.']);
        }

        return $this->planningRequest($item, $config);
    }

    public function replacement(User $actor, int $rdvId): array
    {
        $source = app(MymarBookingActions::class)->scheduled($actor, $rdvId);
        preg_match('/\b(\d{5})\b\s*(.*)$/u', $source['address'], $locality);

        return $this->planningRequest([
            'dossier_id' => $source['dossier_id'], 'expected_revision' => $source['expected_revision'],
            'first_name' => '', 'last_name' => $source['customer'], 'phone' => '', 'email' => null,
            'address' => $source['address'], 'postal_code' => $locality[1] ?? '', 'city' => $locality[2] ?? '',
            'latitude' => null, 'longitude' => null,
        ], $this->settings->load(), $source);
    }

    private function planningRequest(array $item, array $config, ?array $replacement = null): array
    {
        $service = app(MymarAfterWorksService::class)->resolve();
        $duration = $replacement['duration_minutes'] ?? app(MymarAfterWorksService::class)->duration($service);
        $point = DossierLocation::point($item['latitude'], $item['longitude']);
        $precision = 'unknown';
        if ($point === null) {
            $located = $this->geocoder->geocode($item['address'], $item['postal_code'], $item['city']);
            $point = DossierLocation::point($located['latitude'] ?? null, $located['longitude'] ?? null);
            $precision = $located['precision'] ?? 'unknown';
        }
        if ($point === null) {
            throw ValidationException::withMessages(['mymar_dossier_id' => 'La position du dossier MAR doit être corrigée dans MYMAR avant son placement.']);
        }
        $label = 'Visite après travaux';

        return ['id' => 'mymar-'.$item['dossier_id'], 'source' => 'mymar', 'is_mymar' => true, 'is_lot' => false, 'is_manual' => false,
            'mymar_dossier_id' => $item['dossier_id'], 'mymar_rdv_id' => $replacement['rdv_id'] ?? null, 'expected_revision' => $item['expected_revision'],
            'first_name' => $item['first_name'], 'last_name' => $item['last_name'],
            'customer_name' => trim($item['last_name'].' '.$item['first_name']), 'phone' => $item['phone'], 'email' => $item['email'],
            'address' => $item['address'], 'postal_code' => $item['postal_code'], 'city' => $item['city'],
            'department_code' => DossierLocation::departmentCode($item['postal_code']), ...$point, 'location_precision' => $precision,
            'service' => ['id' => $service->id, 'type' => 'MAR', 'name' => $service->name, 'average_duration_minutes' => $duration],
            'service_name' => $label, 'documents' => [], 'comments' => [], 'preferred_starts_at' => $replacement['starts_at'] ?? null,
            'mymar_technician_ids' => array_column($config['bindings'], 'cofrac_user_id')];
    }
}
