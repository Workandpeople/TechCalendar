<?php

namespace App\Services\Crm;

use App\Models\ExternalDelegataire;

final class DossierContacts
{
    public static function details(?ExternalDelegataire $contact): array
    {
        if (! $contact) {
            return [];
        }

        $details = ['name' => $contact->name, 'company_name' => $contact->company_name, 'email' => $contact->email,
            'phone' => $contact->phone, 'address' => $contact->payload['address'] ?? $contact->payload['adresse'] ?? '',
            'postal_code' => $contact->payload['postal_code'] ?? $contact->payload['zip'] ?? '',
            'city' => $contact->payload['city'] ?? $contact->payload['ville'] ?? ''];

        return array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $details);
    }

    public static function fillMissingSnapshots(array $data): array
    {
        foreach (['client', 'delegataire'] as $role) {
            if (array_key_exists($role.'_id', $data) && ! array_key_exists($role.'_contact', $data)) {
                $data[$role.'_contact'] = self::details(ExternalDelegataire::find($data[$role.'_id']));
            }
        }

        return $data;
    }
}
