<?php

namespace App\Services\Coffrac;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Retain source opinions without manufacturing lots, validated controls or satisfaction rates. */
final class NativeLotSatisfactionReview
{
    public function rehearse(ProductionDataset $dataset): array
    {
        NativeImportTarget::assertAllowed();
        if (DB::table('lots')->exists() || DB::table('lot_appointments')->exists()
            || DB::table('dossier_operations')->whereNotNull('control_outcome')->exists()) {
            throw new RuntimeException('Lots ou avis natifs deja presents : rapprochement manuel requis.');
        }
        $lots = $dataset->keyed('lot_dossier');
        $dossiers = $dataset->keyed('dossiers');
        $operations = [];
        $report = ['dataset_sha256' => $dataset->fingerprint,
            'source_lots' => count($lots), 'source_dossiers' => count($dossiers),
            'dossiers_with_explicit_lot_id' => 0, 'dossiers_with_free_lot_label_only' => 0,
            'source_lot_links_to_review' => [], 'source_lots_without_linked_dossiers' => array_keys($lots),
            'dossier_opinions' => [], 'operation_opinions' => [], 'operation_report_payloads_retained' => 0,
            'questionnaire_answers_interpreted' => false, 'native_lots_created' => 0,
            'native_lot_rows_created' => 0, 'native_control_results_changed' => 0,
            'lot_labels_used_as_relationships' => false, 'historical_opinions_applied' => false,
            'native_mapping_review_required' => false];
        foreach ($dataset->rows('dossier_fiches') as $row) {
            $operations[$row['dossier_id']][] = $row['id'];
            if (isset($row['rapport']) && $row['rapport'] !== '') {
                $report['operation_report_payloads_retained']++;
            }
            if (array_key_exists('is_favorable', $row) && $row['is_favorable'] !== null) {
                $report['operation_opinions'][] = ['source_operation_id' => $row['id'], 'source_dossier_id' => $row['dossier_id'],
                    'source_value_sha256' => hash('sha256', json_encode($row['is_favorable'], JSON_THROW_ON_ERROR)),
                    'opinion' => match ($row['is_favorable']) {
                        true, 1, '1' => 'satisfactory', false, 0, '0' => 'unsatisfactory', default => null,
                    }, 'review_required' => true];
            }
        }
        $linkedLots = [];
        foreach ($dossiers as $row) {
            $lot = $row['lot_id'] ?? null;
            if ($lot !== null && $lot !== 0) {
                $report['dossiers_with_explicit_lot_id']++;
                if (is_int($lot) && isset($lots[$lot])) {
                    $linkedLots[$lot] = true;
                }
                $report['source_lot_links_to_review'][] = ['source_dossier_id' => $row['id'], 'source_lot_id' => $lot,
                    'source_lot_exists' => is_int($lot) && isset($lots[$lot]),
                    'source_operation_ids' => $operations[$row['id']] ?? []];
            } elseif (is_string($row['numero_lot'] ?? null) && trim($row['numero_lot']) !== '') {
                $report['dossiers_with_free_lot_label_only']++;
            }
            $value = $row['avis_satisfaction'] ?? null;
            if ($value !== null && $value !== '') {
                $report['dossier_opinions'][] = ['source_dossier_id' => $row['id'],
                    'source_operation_ids' => $operations[$row['id']] ?? [], 'source_lot_id' => $lot,
                    'opinion' => match ($value) {
                        'SATISFAISANT' => 'satisfactory', 'NON SATISFAISANT' => 'unsatisfactory', default => null,
                    }, 'source_value_sha256' => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)),
                    'review_required' => true];
            }
        }
        $report['source_lots_without_linked_dossiers'] = array_values(array_diff(array_keys($lots), array_keys($linkedLots)));
        $report['native_mapping_review_required'] = $lots !== [] || $report['source_lot_links_to_review'] !== []
            || $report['dossier_opinions'] !== [] || $report['operation_opinions'] !== [];

        return $report;
    }
}
