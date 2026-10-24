<?php

use App\Services\Coffrac\CoffracDataset;
use App\Services\Coffrac\DocumentReferenceExport;
use App\Services\Coffrac\ProductionDataset;
use Illuminate\Support\Facades\Hash;

function productionCoffracFixture(array $overrides = []): string
{
    $directory = sys_get_temp_dir().'/cofrac2-production-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $tables = array_fill_keys(CoffracDataset::TABLES, []);
    $baseUser = ['prenom' => 'Compte', 'name' => 'Source', 'actif' => 1, 'is_archived' => 0,
        'adresse' => null, 'telephone' => null, 'latitude' => null, 'longitude' => null, 'created_at' => null, 'updated_at' => null];
    foreach ([11 => 1, 12 => 1, 21 => 4, 31 => 5, 41 => 7, 51 => 6] as $id => $type) {
        $tables['users'][] = ['id' => $id, 'type' => $type, 'email' => 'source-'.$id.'@example.test', 'actif' => $id === 12 ? 0 : 1] + $baseUser;
    }
    $tables['fiche_type'] = [['id' => 7, 'name' => 'Prestation source']];
    $tables['status'] = [['id' => 2, 'name' => 'RDV ATTENTE VISITE'], ['id' => 8, 'name' => 'NON CONFORMITES'], ['id' => 11, 'name' => 'DOSSIERS FACTURER']];
    $base = ['dossier_unique' => 'REF', 'client_name' => 'Beneficiaire', 'numero_lot' => null,
        'delegataire_id' => 41, 'client_id' => 31, 'admin_id' => 11, 'author_id' => 11, 'technicien_id' => 21,
        'entreprise_id' => null, 'client_mail' => null, 'telephone' => '', 'mobile' => '', 'adress' => 'Rue de test',
        'ville' => 'Ville', 'zip' => '75001', 'adresse_demandeur' => null, 'ville_demandeur' => null,
        'code_postale_demandeur' => null, 'latitude' => null, 'longitude' => null, 'status_id' => 1,
        'created_at' => '2026-10-01 08:00:00', 'updated_at' => null];
    $tables['dossiers'] = [['id' => 101] + $base, ['id' => 102] + $base, ['id' => 103] + $base];
    $tables['dossier_fiches'] = [
        ['id' => 201, 'dossier_id' => 101, 'fiche_id' => 7, 'status_id' => 2, 'created_at' => null, 'updated_at' => null],
        ['id' => 202, 'dossier_id' => 102, 'fiche_id' => 7, 'status_id' => 8, 'created_at' => null, 'updated_at' => null],
    ];
    $rdv = ['dossier_id' => 101, 'dossier_fiche' => 201, 'date_rdv' => '2026-10-30', 'heure' => '10:00:00.000000',
        'rdv_duree' => 1, 'created_at' => null, 'updated_at' => null];
    $tables['dossier_fiche_rdv'] = [['id' => 301] + $rdv, ['id' => 302, 'heure' => null] + $rdv];
    $tables['dossier_historique'] = [['id' => 401, 'dossier_id' => 102, 'dossier_fiche_id' => 202, 'status_id' => 11,
        'author_id' => 11, 'created_at' => '2026-10-01 09:00:00', 'updated_at' => null, 'commentaire' => 'Ancienne facturation']];
    $tables['dossier_comment'] = [['id' => 501, 'dossier_id' => 101, 'author_id' => 999, 'text' => '<p>Commentaire</p>',
        'is_private' => 1, 'created_at' => null, 'updated_at' => null]];
    $tables['dossier_document'] = [['id' => 601, 'dossier_id' => 101, 'author_id' => 11, 'path' => 'test.pdf',
        'name' => 'Piece', 'is_private' => 1, 'is_delegataire' => 1]];
    $tables = array_replace($tables, $overrides);
    $credentials = array_map(fn ($row) => ['id' => $row['id'], 'password_hash' => Hash::make('Source-password-123')], $tables['users']);
    foreach ([...$tables, 'credentials' => $credentials] as $table => $rows) {
        file_put_contents($directory.'/'.$table.'.jsonl', implode('', array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows)));
        chmod($directory.'/'.$table.'.jsonl', 0600);
    }
    sealProductionCoffracFixture($directory);

    return $directory;
}

function sealProductionCoffracFixture(string $directory): void
{
    $tables = [];
    foreach ([...CoffracDataset::TABLES, 'credentials'] as $table) {
        $tables[$table] = ['count' => count(file($directory.'/'.$table.'.jsonl')), 'sha256' => hash_file('sha256', $directory.'/'.$table.'.jsonl')];
    }
    file_put_contents($directory.'/manifest.json', json_encode(['format' => 'techcalendar.coffrac-production', 'version' => 1,
        'complete' => true, 'dump_sha256' => str_repeat('a', 64), 'final_cutover_ready' => false, 'tables' => $tables], JSON_THROW_ON_ERROR));
    chmod($directory.'/manifest.json', 0600);
}

/** Synthetic sealed metadata for offline tests only; no physical-copy assertion. */
function productionCoffracDocumentPlanFixture(ProductionDataset $dataset, array $statuses = []): string
{
    $directory = sys_get_temp_dir().'/cofrac2-document-plan-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $directory = realpath($directory);
    $links = $issues = $paths = [];
    $audiences = ['internal' => 0, 'client' => 0, 'shared' => 0];
    $linked = 0;
    $counts = [];
    foreach (DocumentReferenceExport::rows($dataset) as $row) {
        $document = $row['source_table'] === 'dossier_document';
        $status = $document ? ($statuses[$row['source_id']] ?? ($row['owner_exists'] ? 'linked' : 'missing_dossier_owner')) : 'attachment_scope_to_review';
        $relative = $document && $row['owner_exists'] ? base64_encode('public/documents/'.base64_decode($row['path_base64'])) : null;
        $sha = in_array($status, ['linked', 'empty_file', 'quarantined_file'], true) ? hash('sha256', $row['path_base64']) : null;
        $links[] = [...$row, 'status' => $status, 'archive_path_base64' => $relative, 'verified_sha256' => $sha];
        if ($status === 'linked') {
            $linked++;
            $paths[$relative] = true;
        } else {
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $issues[] = ['source_table' => $row['source_table'], 'source_id' => $row['source_id'], 'source_field' => $row['source_field'], 'reason' => $status];
        }
        if ($document) {
            $audiences[! $row['client_visible'] ? 'internal' : ($row['delegataire_visible'] ? 'shared' : 'client')]++;
        }
    }
    $report = ['format' => 'cofrac2.document-reconciliation', 'version' => 1, 'complete' => true,
        'dump_sha256' => $dataset->manifest['dump_sha256'], 'dataset_sha256' => $dataset->fingerprint,
        'inventory_sha256' => str_repeat('a', 64), 'verification_sha256' => str_repeat('b', 64), 'references_sha256' => str_repeat('c', 64),
        'records' => count($links), 'linked_records' => $linked, 'unique_linked_files' => count($paths), 'shared_file_references' => $linked - count($paths),
        'unreferenced_files_retained' => 0, 'audiences' => $audiences, 'issues' => $counts, 'native_database_read' => false,
        'database_links_verified' => false, 'serving_enabled' => false, 'final_cutover_ready' => false];
    foreach (['links.jsonl' => $links, 'issues.jsonl' => $issues, 'unreferenced.jsonl' => []] as $name => $rows) {
        file_put_contents($directory.'/'.$name, implode('', array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows)));
        chmod($directory.'/'.$name, 0600);
    }
    file_put_contents($directory.'/report.json', json_encode($report, JSON_THROW_ON_ERROR));
    chmod($directory.'/report.json', 0600);
    sealProductionCoffracDocumentPlanFixture($directory);

    return $directory;
}

function sealProductionCoffracDocumentPlanFixture(string $directory): void
{
    $names = ['links.jsonl', 'issues.jsonl', 'unreferenced.jsonl', 'report.json'];
    file_put_contents($directory.'/SHA256SUMS', implode('', array_map(fn ($name) => hash_file('sha256', $directory.'/'.$name).'  '.$name."\n", $names)));
    chmod($directory.'/SHA256SUMS', 0600);
}
