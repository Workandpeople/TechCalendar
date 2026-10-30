<?php

namespace App\Services\Crm;

use Illuminate\Database\Eloquent\Builder;

final class DossierSearch
{
    public const INTERNAL_FIELDS = [
        'reference', 'legacy_reference', 'legacy_label', 'legacy_lot_name', 'company_name', 'first_name', 'last_name',
        'site_name', 'email', 'phone', 'address', 'postal_code', 'city', 'installer_name', 'installer_siren',
    ];

    public const EXTERNAL_FIELDS = [
        'reference', 'company_name', 'last_name', 'first_name', 'legacy_label',
        'legacy_lot_name', 'address', 'postal_code', 'city',
    ];

    private const BENEFICIARY_FIELDS = ['company_name', 'first_name', 'last_name', 'legacy_label'];

    public static function apply(Builder $query, string $term, array $fields = self::INTERNAL_FIELDS): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $literal = '%'.self::escape($term).'%';
        $parts = preg_split('/[\s\p{Z},;]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = '%'.implode('%', array_map(self::escape(...), $parts)).'%';
        $combinations = [self::concatenate($query, ['address', 'postal_code', 'city'])];
        if (in_array('first_name', $fields, true) && in_array('last_name', $fields, true)) {
            $combinations[] = self::concatenate($query, ['first_name', 'last_name']);
            $combinations[] = self::concatenate($query, ['last_name', 'first_name']);
        }

        $query->where(function (Builder $matching) use ($fields, $literal, $parts, $combinations, $words): void {
            foreach ($fields as $field) {
                $pattern = count($parts) > 1 && in_array($field, self::BENEFICIARY_FIELDS, true) ? $words : $literal;
                $matching->orWhereRaw("dossiers.{$field} LIKE ? ESCAPE '!'", [$pattern]);
            }
            // Displayed addresses and full beneficiary names can span several stored columns.
            if (count($parts) > 1) {
                foreach ($combinations as $expression) {
                    $matching->orWhereRaw("{$expression} LIKE ? ESCAPE '!'", [$words]);
                }
            }
        });
    }

    private static function concatenate(Builder $query, array $fields): string
    {
        $columns = [];
        foreach ($fields as $field) {
            if ($columns !== []) {
                $columns[] = "' '";
            }
            $columns[] = "COALESCE(dossiers.{$field}, '')";
        }

        return $query->getConnection()->getDriverName() === 'sqlite'
            ? '('.implode(' || ', $columns).')' : 'CONCAT('.implode(', ', $columns).')';
    }

    private static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
