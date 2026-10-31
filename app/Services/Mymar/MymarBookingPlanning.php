<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use App\Services\Crm\DossierLocation;
use App\Services\PlanningAddressGeocoder;
use Illuminate\Validation\ValidationException;

class MymarBookingPlanning
{
    public function __construct(private MymarPendingBookings $pending, private MymarActionSettings $settings, private PlanningAddressGeocoder $geocoder) {}

    public function resolve(int $id, int $duration): array
    {
        $config = $this->settings->load();
        if (! config('crm.v2_enabled') || ! ($config['writes_enabled'] ?? false)) {
            throw ValidationException::withMessages(['mymar_dossier_id' => 'Le placement MAR n’est pas activé.']);
        }
        $item = $this->pending->find($id);
        if ($item === null) {
            throw ValidationException::withMessages(['mymar_dossier_id' => 'Cette demande MAR n’est plus à placer. Actualisez la liste.']);
        }
        $point = DossierLocation::point($item['latitude'], $item['longitude']);
        $precision = 'unknown';
        if ($point === null) {
            $located = $this->geocoder->geocode($item['address'], $item['postal_code'], $item['city']);
            $point = DossierLocation::point($located['latitude'] ?? null, $located['longitude'] ?? null);
            $precision = $located['location_precision'] ?? 'unknown';
        }
        if ($point === null) {
            throw ValidationException::withMessages(['mymar_dossier_id' => 'La position du dossier MAR doit être corrigée dans MYMAR avant son placement.']);
        }
        $label = $item['phase'] === 'after_works' ? 'Visite après travaux' : 'Visite initiale';

        return ['id' => 'mymar-'.$id, 'source' => 'mymar', 'is_mymar' => true, 'is_lot' => false, 'is_manual' => false,
            'mymar_dossier_id' => $id, 'expected_revision' => $item['expected_revision'],
            'first_name' => $item['first_name'], 'last_name' => $item['last_name'],
            'customer_name' => trim($item['last_name'].' '.$item['first_name']), 'phone' => $item['phone'], 'email' => $item['email'],
            'address' => $item['address'], 'postal_code' => $item['postal_code'], 'city' => $item['city'],
            'department_code' => DossierLocation::departmentCode($item['postal_code']), ...$point, 'location_precision' => $precision,
            'service' => ['id' => null, 'type' => 'MAR', 'name' => $label, 'average_duration_minutes' => $duration],
            'service_name' => $label, 'documents' => [], 'comments' => [], 'preferred_starts_at' => null,
            'mymar_technician_ids' => array_column($config['bindings'], 'cofrac_user_id')];
    }
}
