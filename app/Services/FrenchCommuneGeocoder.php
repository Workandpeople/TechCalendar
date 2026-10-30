<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FrenchCommuneGeocoder
{
    public function suggestions(string $query): array
    {
        [$postal, $name] = $this->queryParts($query);
        if ($postal === null && mb_strlen($name) < 2) {
            return [];
        }
        $rows = $this->communes($postal, $name);
        $matches = [];
        foreach ($rows as $row) {
            if ($name !== '' && ! str_starts_with($this->normalize($row['nom']), $this->normalize($name))) {
                continue;
            }
            foreach ($postal ? [$postal] : $row['codesPostaux'] as $code) {
                if (! in_array($code, $row['codesPostaux'], true)) {
                    continue;
                }
                $matches[] = $this->describe($row, $code);
            }
        }
        usort($matches, fn ($left, $right) => [$this->normalize($left['city']) !== $this->normalize($name), $left['city'], $left['postal_code']]
            <=> [$this->normalize($right['city']) !== $this->normalize($name), $right['city'], $right['postal_code']]);

        return array_slice($matches, 0, 8);
    }

    public function geocode(string $query, ?string $identifier = null, bool $allowPostalOnly = false): array
    {
        [$postal, $name] = $this->queryParts($query);
        $insee = null;
        if (filled($identifier)) {
            if (! preg_match('/^commune\.([0-9A-Z]{5})_([0-9]{5})$/D', $identifier, $parts)
                || ($postal !== null && $postal !== $parts[2])) {
                throw $this->invalidSelection();
            }
            [, $insee, $postal] = $parts;
        }
        if ($name === '' && $insee === null && (! $allowPostalOnly || $postal === null)) {
            throw $this->invalidSelection();
        }
        $matches = array_values(array_filter($this->communes($postal, $name), fn ($row) => ($insee === null || $row['code'] === $insee) && ($postal === null || in_array($postal, $row['codesPostaux'], true))
            && ($name === '' || $this->normalize($row['nom']) === $this->normalize($name))));
        if (count($matches) !== 1 || ($postal === null && count($matches[0]['codesPostaux']) !== 1)) {
            throw $this->invalidSelection();
        }
        $row = $matches[0];
        $selected = $this->describe($row, $postal ?? $row['codesPostaux'][0]);

        return ['latitude' => (float) $row['centre']['coordinates'][1], 'longitude' => (float) $row['centre']['coordinates'][0],
            'formatted_address' => $selected['label'], 'mapbox_id' => $selected['id'], 'mapbox_confidence' => null,
            'street_address' => '', 'postal_code' => $selected['postal_code'], 'city' => $selected['city'],
            'feature_type' => 'place', 'precision' => 'city', 'provider' => 'geo_api_gouv_fr',
            'warnings' => ['Position approximative : centre de la commune. Adresse exacte à confirmer avant la visite.']];
    }

    private function communes(?string $postal, string $name): array
    {
        // Cache public commune lists, not addresses or dossier/customer information.
        $parameters = $postal !== null ? ['codePostal' => $postal] : ['nom' => $name, 'limit' => 8];
        $key = 'french-communes:v1:'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));

        return Cache::remember($key, now()->addDay(), function () use ($parameters): array {
            $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
                ->get('https://geo.api.gouv.fr/communes', [...$parameters, 'fields' => 'code,nom,codesPostaux,centre,departement', 'format' => 'json']);
            $rows = $response->ok() ? $response->json() : null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 500) {
                throw ValidationException::withMessages(['q' => 'Le référentiel des communes est momentanément indisponible. Réessayez dans un instant.']);
            }

            return array_values(array_filter($rows, $this->validCommune(...)));
        });
    }

    private function validCommune(mixed $row): bool
    {
        if (! is_array($row) || ! is_string($row['code'] ?? null) || ! preg_match('/^[0-9A-Z]{5}$/D', $row['code'])
            || ! is_string($row['nom'] ?? null) || trim($row['nom']) === '' || mb_strlen($row['nom']) > 190
            || ! is_array($row['codesPostaux'] ?? null) || $row['codesPostaux'] === []
            || ($row['centre']['type'] ?? null) !== 'Point') {
            return false;
        }
        foreach ($row['codesPostaux'] as $code) {
            if (! is_string($code) || ! preg_match('/^[0-9]{5}$/D', $code)) {
                return false;
            }
        }
        $point = $row['centre']['coordinates'] ?? null;

        return is_array($point) && array_is_list($point) && count($point) === 2 && is_numeric($point[0]) && is_numeric($point[1])
            && is_finite((float) $point[0]) && is_finite((float) $point[1])
            && abs((float) $point[0]) <= 180 && abs((float) $point[1]) <= 90
            && ((float) $point[0] !== 0.0 || (float) $point[1] !== 0.0);
    }

    private function describe(array $row, string $postal): array
    {
        $department = is_string($row['departement']['nom'] ?? null) ? $row['departement']['nom'] : null;

        return ['id' => 'commune.'.$row['code'].'_'.$postal, 'label' => implode(', ', array_filter([$postal.' '.$row['nom'], $department, 'France'])),
            'street_address' => '', 'postal_code' => $postal, 'city' => $row['nom'], 'feature_type' => 'place'];
    }

    private function queryParts(string $query): array
    {
        $head = trim(explode(',', $query)[0]);
        preg_match_all('/\b[0-9]{5}\b/', $head, $codes);
        $name = trim(preg_replace('/\b[0-9]{5}\b/', '', $head) ?? '');
        if (count($codes[0]) > 1 || preg_match('/[0-9]/', $name)) {
            throw $this->invalidSelection();
        }

        return [$codes[0][0] ?? null, $name];
    }

    private function normalize(string $name): string
    {
        return Str::squish(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($name))) ?? '');
    }

    private function invalidSelection(): ValidationException
    {
        return ValidationException::withMessages(['q' => 'La commune et le code postal ne correspondent pas à une sélection unique. Choisissez la commune dans la liste.']);
    }
}
