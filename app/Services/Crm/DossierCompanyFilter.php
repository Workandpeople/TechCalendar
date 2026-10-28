<?php

namespace App\Services\Crm;

final class DossierCompanyFilter
{
    public static function ids(array $filters): array
    {
        $values = array_key_exists('company_ids', $filters) ? ($filters['company_ids'] ?? [])
            : (filled($filters['delegataire_id'] ?? null) ? [$filters['delegataire_id']] : []);

        return array_values(array_unique(array_map(intval(...), $values)));
    }
}
