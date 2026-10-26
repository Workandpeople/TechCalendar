<?php

namespace App\Services\Crm;

use Illuminate\Database\Eloquent\Builder;

final class DossierStatusCatalog
{
    public const STATUSES = [
        'pending' => ['label' => 'Prise de RDV', 'tone' => 'blue', 'values' => ['PRISE DE RDV']],
        'problem' => ['label' => 'Problème rendez-vous', 'tone' => 'red', 'values' => ['Problème rendez- vous', 'PROBLÈME RENDEZ-VOUS']],
        'awaiting_visit' => ['label' => 'RDV en attente de visite', 'tone' => 'amber', 'values' => ['RDV ATTENTE VISITE']],
        'visited' => ['label' => 'RDV visité', 'tone' => 'teal', 'values' => ['RDV VISITE']],
        'rt_control' => ['label' => 'Contrôle RT', 'tone' => 'blue', 'values' => ['CONTROLE RT']],
        'non_compliant' => ['label' => 'Non-conformités', 'tone' => 'red', 'values' => ['NON CONFORMITES']],
        'recontrol' => ['label' => 'Recontrôle', 'tone' => 'amber', 'values' => ['RECONTROLE']],
        // Keep the raw invoiced value for reconciliation, but display it as the final business state.
        'report' => ['label' => 'Rapport Genius', 'tone' => 'teal', 'values' => ['RAPPORT GENIUS', 'DOSSIERS FACTURER']],
        'invoiced' => ['label' => 'Dossiers facturés', 'tone' => 'green', 'values' => ['DOSSIERS FACTURER']],
        'cancelled' => ['label' => 'Dossier annulé', 'tone' => 'slate', 'values' => ['DOSSIER ANNULE']],
        // Keep the URL key for old bookmarks and source reconciliation.
        'unknown' => ['label' => 'Dossiers supprimés', 'tone' => 'slate', 'values' => []],
    ];

    public static function describe(?string $value): array
    {
        foreach (self::STATUSES as $key => $status) {
            if (in_array($value, $status['values'], true)) {
                return ['key' => $key, ...$status];
            }
        }

        return ['key' => 'unknown', ...self::STATUSES['unknown']];
    }

    public static function manualStates(): array
    {
        return array_diff_key(self::STATUSES, array_flip(['invoiced', 'unknown']));
    }

    public static function filter(Builder $query, string $status): void
    {
        if ($status !== 'unknown') {
            $query->whereIn('legacy_status', self::STATUSES[$status]['values']);

            return;
        }

        $values = array_merge(...array_column(self::STATUSES, 'values'));
        $query->where(fn ($query) => $query->whereNull('legacy_status')->orWhereNotIn('legacy_status', $values));
    }

    /** Group by the same category even when several historical spellings coexist. */
    public static function categorySql(): array
    {
        $sql = 'CASE';
        $bindings = [];
        foreach (self::STATUSES as $key => $status) {
            if ($status['values'] === []) {
                continue;
            }
            $sql .= ' WHEN dossier_operations.legacy_status IN ('.implode(',', array_fill(0, count($status['values']), '?')).') THEN ?';
            $bindings = [...$bindings, ...$status['values'], $key];
        }

        return [$sql." ELSE 'unknown' END", $bindings];
    }
}
