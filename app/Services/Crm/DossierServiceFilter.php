<?php

namespace App\Services\Crm;

final class DossierServiceFilter
{
    public static function ids(array $filters): array
    {
        $values = array_key_exists('service_ids', $filters) ? ($filters['service_ids'] ?? [])
            : (filled($filters['service_id'] ?? null) ? [$filters['service_id']] : []);

        return array_values(array_unique(array_map(intval(...), $values)));
    }
}
