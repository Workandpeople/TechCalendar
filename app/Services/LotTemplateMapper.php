<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class LotTemplateMapper
{
    public const HEADERS = [
        "REFERENCE interne de l'opération",
        "NOM du bénéficiaire de l'opération",
        "PRENOM du bénéficiaire de l'opération",
        "ADRESSE de l'opération",
        'CODE POSTAL (sans cedex)',
        'VILLE',
        'numéro de téléphone du bénéficiaire',
        'Adresse de courriel du bénéficiaire',
        "RAISON SOCIALE du bénéficiaire de l'opération",
        "ADRESSE du siège social du bénéficiaire de l'opération",
        'CODE POSTAL (sans cedex) 2',
        'VILLE 2',
        'SIREN du professionnel',
        'RAISON SOCIALE du professionnel',
    ];

    public function normalize(Collection $rows, ?string $requestedLotName = null, ?string $lotType = null): array
    {
        if ($rows->isEmpty()) {
            throw new RuntimeException('Le fichier ne contient aucun dossier.');
        }

        $headers = array_keys($rows->first()['data']);
        $this->validateHeaders($headers);

        $appointments = $rows->map(function (array $row) use ($headers): array {
            if (array_keys($row['data']) !== $headers) {
                throw new RuntimeException('Les colonnes du fichier changent en cours de lecture. Utilise le modèle TechCalendar.');
            }

            $values = array_values($row['data']);
            $text = static fn (int $index): ?string => trim((string) ($values[$index] ?? '')) ?: null;
            $postalCode = $this->postalCode($text(4));
            $phone = preg_replace('/[\s.()-]+/', '', $text(6) ?? '');
            if (preg_match('/^[1-9]\d{8}$/', $phone)) {
                $phone = '0'.$phone;
            }
            $siren = preg_replace('/\s+/', '', $text(12) ?? '');
            $name = $text(8) ?: trim(implode(' ', array_filter([$text(2), $text(1)])));
            $mapped = [
                'row_number' => (int) $row['row_number'],
                'external_reference' => $text(0),
                'internal_reference' => $text(0),
                'customer_last_name' => $text(1),
                'customer_first_name' => $text(2),
                'customer_name' => $name ?: 'Client à qualifier',
                'address' => $text(3),
                'address_line' => $text(3),
                'postal_code' => $postalCode,
                'city' => $text(5),
                'customer_phone' => $phone ?: null,
                'customer_email' => $text(7),
                'company_name' => $text(8),
                'beneficiary_address' => $text(9),
                'beneficiary_postal_code' => $this->postalCode($text(10)),
                'beneficiary_city' => $text(11),
                'installer_siren' => $siren ?: null,
                'installer_name' => $text(13),
                'site_name' => null,
                'department_code' => $postalCode ? (str_starts_with($postalCode, '97') ? substr($postalCode, 0, 3) : substr($postalCode, 0, 2)) : null,
                'confidence' => null,
                'raw_payload' => $row['data'],
                'import_format' => 'techcalendar_v1',
            ];
            $mapped['warnings'] = $this->warningsFor($mapped);

            return $mapped;
        })->values()->all();

        return [
            'lot_name' => $requestedLotName,
            'appointments' => $appointments,
            'rejected_rows' => [],
            'summary' => 'Import du modèle TechCalendar : correspondance fixe des colonnes.',
        ];
    }

    public function warningsFor(array $appointment): array
    {
        $warnings = [];
        if (blank($appointment['company_name'] ?? null) && blank($appointment['customer_first_name'] ?? null) && blank($appointment['customer_last_name'] ?? null)) {
            $warnings[] = 'Nom ou raison sociale du bénéficiaire absent.';
        }
        if (blank($appointment['address'] ?? null)) {
            $warnings[] = 'Adresse de l’inspection absente.';
        }
        foreach (['postal_code' => 'inspection', 'beneficiary_postal_code' => 'siège'] as $field => $label) {
            if (filled($appointment[$field] ?? null) && ! preg_match('/^\d{5}$/', $appointment[$field])) {
                $warnings[] = 'Code postal '.$label.' invalide.';
            }
        }
        if (filled($appointment['installer_siren'] ?? null) && ! preg_match('/^\d{9}$/', $appointment['installer_siren'])) {
            $warnings[] = 'Le SIREN de l’installateur doit contenir 9 chiffres.';
        }
        if (filled($appointment['customer_email'] ?? null) && ! filter_var($appointment['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $warnings[] = 'Adresse email du bénéficiaire invalide.';
        }
        $limits = [
            'internal_reference' => [255, 'référence interne'],
            'customer_first_name' => [120, 'prénom du bénéficiaire'],
            'customer_last_name' => [120, 'nom du bénéficiaire'],
            'company_name' => [255, 'raison sociale du bénéficiaire'],
            'installer_name' => [255, 'raison sociale de l’installateur'],
            'customer_email' => [255, 'email du bénéficiaire'],
            'address' => [255, 'adresse de l’inspection'],
            'beneficiary_address' => [500, 'adresse du siège'],
            'city' => [120, 'ville de l’inspection'],
            'beneficiary_city' => [120, 'ville du siège'],
        ];
        foreach ($limits as $field => [$max, $label]) {
            if (mb_strlen((string) ($appointment[$field] ?? '')) > $max) {
                $warnings[] = 'Valeur trop longue pour « '.$label.' » (maximum '.$max.' caractères).';
            }
        }

        return $warnings;
    }

    private function validateHeaders(array $headers): void
    {
        foreach (self::HEADERS as $index => $expected) {
            if ($this->headerKey($headers[$index] ?? '') !== $this->headerKey($expected)) {
                throw new RuntimeException(sprintf(
                    'Le fichier ne respecte pas le modèle TechCalendar. Colonne %s attendue : « %s » ; reçue : « %s ». Conserve l’ordre des 14 colonnes du modèle.',
                    chr(65 + $index), preg_replace('/ 2$/', '', $expected), $headers[$index] ?? '(absente)',
                ));
            }
        }
        if (count($headers) !== count(self::HEADERS)) {
            throw new RuntimeException('Le modèle TechCalendar doit contenir exactement 14 colonnes, de A à N.');
        }
    }

    private function headerKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value)));
    }

    private function postalCode(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{1,5}$/', $value) ? str_pad($value, 5, '0', STR_PAD_LEFT) : $value;
    }
}
