<?php

namespace App\Services\Coffrac;

use App\Services\Crm\ClientAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Read-only matching against the existing archive; never duplicate native documents. */
final class NativeDocumentAudit
{
    public function rehearse(ProductionDataset $dataset, DocumentReconciliationPlan $plan): array
    {
        NativeImportTarget::assertAllowed();
        $run = DB::table('coffrac_import_runs')->sole();
        if ($run->fingerprint !== $dataset->fingerprint || $plan->report['dataset_sha256'] !== $dataset->fingerprint
            || DB::table('legacy_records')->where('source_table', 'dossier_document')->count() !== $dataset->manifest['tables']['dossier_document']['count']) {
            throw new RuntimeException('Archive native incompatible avec le plan documentaire.');
        }

        return DB::transaction(function () use ($dataset, $plan): array {
            $report = ['dataset_sha256' => $dataset->fingerprint, 'plan_sha256' => $plan->fingerprint,
                'archived_document_records_checked' => 0, 'snapshot_linked_references' => 0,
                'unavailable_dossier_documents_retained' => 0, 'non_dossier_references_retained_for_review' => 0,
                'audiences' => ['internal' => 0, 'client' => 0, 'shared' => 0],
                'native_database_links_match_plan' => false, 'native_documents_created' => 0,
                'native_records_changed' => 0, 'original_files_read' => false, 'serving_enabled' => false, 'final_cutover_ready' => false];
            $batch = [];
            foreach ($plan->rows() as $row) {
                if ($row['source_table'] !== 'dossier_document') {
                    $report['non_dossier_references_retained_for_review']++;

                    continue;
                }
                $batch[$row['source_id']] = $row;
                if (count($batch) === 400) {
                    $this->checkBatch($batch, $report);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->checkBatch($batch, $report);
            }
            if ($report['archived_document_records_checked'] !== $dataset->manifest['tables']['dossier_document']['count']
                || $report['snapshot_linked_references'] !== $plan->report['linked_records']) {
                throw new RuntimeException('Controle natif documentaire incomplet.');
            }
            $report['native_database_links_match_plan'] = true;

            return $report;
        });
    }

    private function checkBatch(array $batch, array &$report): void
    {
        $records = DB::table('legacy_records')->where('source_table', 'dossier_document')
            ->whereIn('source_id', array_keys($batch))->get()->keyBy('source_id');
        $parents = DB::table('dossiers')->whereIn('id', array_unique(array_filter(array_column($batch, 'dossier_id'))))->pluck('id')->flip();
        foreach ($batch as $sourceId => $row) {
            $record = $records->get($sourceId);
            $parent = $row['owner_exists'] ? $row['dossier_id'] : null;
            if (! $record || $record->dossier_id !== $parent || ($parent !== null && ! $parents->has($parent))
                || $record->hidden_at !== null || $record->client_visible_override !== null || $record->delegataire_visible_override !== null) {
                throw new RuntimeException('Appartenance ou visibilite documentaire native differente.');
            }
            $payload = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
            $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $flags = app(ClientAccess::class)->legacyDocumentVisibility($payload);
            if (! hash_equals($row['payload_sha256'], $digest)
                || $flags !== ['client_visible' => $row['client_visible'], 'delegataire_visible' => $row['delegataire_visible']]) {
                throw new RuntimeException('Metadonnees ou droits documentaires natifs differents.');
            }
            $report['archived_document_records_checked']++;
            $report[$row['status'] === 'linked' ? 'snapshot_linked_references' : 'unavailable_dossier_documents_retained']++;
            $audience = ! $flags['client_visible'] ? 'internal' : ($flags['delegataire_visible'] ? 'shared' : 'client');
            $report['audiences'][$audience]++;
        }
    }
}
