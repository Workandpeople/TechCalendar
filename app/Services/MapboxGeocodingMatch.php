<?php

namespace App\Services;

use Illuminate\Support\Str;

final class MapboxGeocodingMatch
{
    public function accepts(string $query, array $feature, array $parts): bool
    {
        $center = $feature['center'] ?? null;
        if (! is_array($center) || count($center) < 2 || ! is_numeric($center[0]) || ! is_numeric($center[1])
            || ! is_finite((float) $center[0]) || ! is_finite((float) $center[1])
            || abs((float) $center[0]) > 180 || abs((float) $center[1]) > 90
            || ((float) $center[0] === 0.0 && (float) $center[1] === 0.0)) {
            return false;
        }
        if (($parts['feature_type'] ?? null) !== 'address'
            || ! is_numeric($feature['relevance'] ?? null) || (float) $feature['relevance'] < 0.75
            || blank($parts['street_address'] ?? null) || blank($parts['city'] ?? null)
            || ($feature['properties']['accuracy'] ?? null) === 'approximate') {
            return false;
        }

        // Cadastre references may contain numbers too: the final postal code is authoritative.
        preg_match_all('/\b[0-9]{5}\b/u', $query, $codes, PREG_OFFSET_CAPTURE);
        if ($codes[0] === []) {
            return true;
        }
        [$postcode, $offset] = end($codes[0]);
        if ($postcode !== ($parts['postal_code'] ?? null)) {
            return false;
        }

        $city = trim(explode(',', substr($query, $offset + strlen($postcode)))[0]);
        $city = preg_replace('/\b(?:cedex.*|france)\b/ui', '', $city) ?? $city;
        $city = $this->normalize($city);
        $matchedCity = $this->normalize((string) $parts['city']);

        return $city === '' || $city === $matchedCity
            || str_starts_with($matchedCity, $city.' ') || str_starts_with($city, $matchedCity.' ');
    }

    private function normalize(string $value): string
    {
        return Str::squish(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))) ?? '');
    }
}
