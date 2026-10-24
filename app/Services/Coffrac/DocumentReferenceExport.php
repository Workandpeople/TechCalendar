<?php

namespace App\Services\Coffrac;

use Generator;

/** Export identifiers and ACLs, never credentials, comments or report questionnaires. */
final class DocumentReferenceExport
{
    public static function rows(ProductionDataset $dataset): Generator
    {
        $dossiers = [];
        foreach ($dataset->rows('dossiers') as $row) {
            $dossiers[$row['id']] = true;
        }
        foreach (['dossier_document', 'fiche_document', 'media'] as $table) {
            foreach ($dataset->rows($table) as $row) {
                yield self::reference($table, 'path', $row, $dossiers);
            }
        }
        foreach ($dataset->rows('dossier_fiches') as $row) {
            // "rapport" contains questionnaire JSON, not a filename.
            foreach (['rapport_pdf', 'signature'] as $field) {
                if (($row[$field] ?? null) !== null && $row[$field] !== '') {
                    yield self::reference('dossier_fiches', $field, $row, $dossiers);
                }
            }
        }
    }

    private static function reference(string $table, string $field, array $row, array $dossiers): array
    {
        $ownsDossier = in_array($table, ['dossier_document', 'dossier_fiches'], true);
        $id = $ownsDossier && is_int($row['dossier_id'] ?? null) && $row['dossier_id'] > 0 ? $row['dossier_id'] : null;
        $public = $table === 'dossier_document' && in_array($row['is_private'] ?? null, [false, 0, '0'], true);

        return ['source_table' => $table, 'source_id' => $row['id'], 'source_field' => $field,
            'dossier_id' => $id, 'owner_exists' => $id !== null && isset($dossiers[$id]),
            'path_base64' => is_string($row[$field] ?? null) ? base64_encode($row[$field]) : null,
            'client_visible' => $public,
            'delegataire_visible' => $public && in_array($row['is_delegataire'] ?? null, [true, 1, '1'], true),
            'payload_sha256' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }
}
