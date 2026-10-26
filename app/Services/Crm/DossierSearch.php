<?php

namespace App\Services\Crm;

use Illuminate\Database\Eloquent\Builder;

final class DossierSearch
{
    public const INTERNAL_FIELDS = [
        'reference', 'legacy_reference', 'legacy_label', 'legacy_lot_name', 'company_name', 'first_name', 'last_name',
        'site_name', 'email', 'phone', 'address', 'postal_code', 'city', 'installer_name', 'installer_siren',
    ];

    public static function apply(Builder $query, string $term, array $fields = self::INTERNAL_FIELDS): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $literal = '%'.self::escape($term).'%';
        $parts = preg_split('/[\s\p{Z},;]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $addressPattern = '%'.implode('%', array_map(self::escape(...), $parts)).'%';
        $columns = ["COALESCE(dossiers.address, '')", "' '", "COALESCE(dossiers.postal_code, '')", "' '", "COALESCE(dossiers.city, '')"];
        $address = $query->getConnection()->getDriverName() === 'sqlite'
            ? '('.implode(' || ', $columns).')' : 'CONCAT('.implode(', ', $columns).')';

        $query->where(function (Builder $matching) use ($fields, $literal, $parts, $address, $addressPattern): void {
            foreach ($fields as $field) {
                $matching->orWhereRaw("dossiers.{$field} LIKE ? ESCAPE '!'", [$literal]);
            }
            // A pasted full address spans three columns; tolerate punctuation and spacing between its parts.
            if (count($parts) > 1) {
                $matching->orWhereRaw("{$address} LIKE ? ESCAPE '!'", [$addressPattern]);
            }
        });
    }

    private static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
