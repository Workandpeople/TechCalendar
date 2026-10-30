<?php

namespace App\Services;

use App\Services\Crm\DossierLocation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

final class PlanningAddressGeocoder
{
    public function __construct(
        private readonly MapboxAddressGeocoder $addresses,
        private readonly FrenchCommuneGeocoder $communes,
    ) {}

    public function geocode(string $address, ?string $postalCode = null, ?string $city = null): array
    {
        $result = $this->addresses->geocode($address);
        if (DossierLocation::point($result['latitude'] ?? null, $result['longitude'] ?? null)) {
            return $result;
        }

        $query = $this->localityQuery($address, $postalCode, $city);
        if ($query === null) {
            return $result;
        }

        try {
            // A postcode alone is usable only if it identifies exactly one commune.
            return $this->communes->geocode($query, allowPostalOnly: true);
        } catch (ConnectionException|ValidationException) {
            return $result;
        }
    }

    private function localityQuery(string $address, ?string $postalCode, ?string $city): ?string
    {
        if (preg_match('/^[0-9]{5}$/D', trim($postalCode ?? ''))) {
            $postalCode = trim($postalCode);
            $name = trim($city ?? '');
        } else {
            preg_match_all('/(?<![\p{L}\p{N}])[0-9]{5}(?![\p{L}\p{N}])/u', $address, $codes, PREG_OFFSET_CAPTURE);
            if ($codes[0] === []) {
                return null;
            }
            // Prefer the final postal segment over earlier cadastral references.
            [$postalCode, $offset] = end($codes[0]);
            $name = trim(explode(',', ltrim(substr($address, $offset + 5), " ,\t\n\r"))[0]);
        }
        $name = trim(preg_replace('/\b(?:cedex.*|france)\b/ui', '', $name) ?? '');

        return trim($postalCode.' '.$name);
    }
}
