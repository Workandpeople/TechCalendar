<?php

declare(strict_types=1);

// Standalone offline tool: never bootstrap Laravel or load application secrets.
function verifiedCollection(string $directory): array
{
    $expected = ['coffrac.sql.gz', 'server.tsv', 'tables.tsv', 'columns.tsv', 'collection.txt', 'mysqldump.stderr'];
    $checksums = file($directory.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($checksums === false || count($checksums) !== count($expected)) {
        throw new RuntimeException('Manifeste de collecte incomplet.');
    }
    $seen = [];
    foreach ($checksums as $line) {
        if (! preg_match('/^([a-f0-9]{64})  ([a-zA-Z0-9._-]+)$/D', $line, $match)
            || ! in_array($match[2], $expected, true) || isset($seen[$match[2]])) {
            throw new RuntimeException('Entree de manifeste inattendue.');
        }
        $path = $directory.'/'.$match[2];
        if (! is_file($path) || is_link($path) || ! hash_equals($match[1], hash_file('sha256', $path))) {
            throw new RuntimeException('Collecte absente, modifiee ou lien symbolique.');
        }
        $seen[$match[2]] = $match[1];
    }
    $metadata = [];
    foreach (file($directory.'/collection.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (! preg_match('/^([a-z_]+)=([^\r\n]+)$/D', $line, $match) || isset($metadata[$match[1]])) {
            throw new RuntimeException('Metadonnees de collecte invalides.');
        }
        $metadata[$match[1]] = $match[2];
    }
    if (($metadata['source_database'] ?? '') !== 'fiche_crm'
        || ($metadata['collection_type'] ?? '') !== 'online_preparation_snapshot'
        || ($metadata['final_cutover_ready'] ?? '') !== 'no'
        || ! in_array($metadata['data_consistency'] ?? '', ['mixed_engines_not_atomic', 'innodb_transaction_snapshot'], true)
        || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $metadata['collected_at_utc'] ?? '')) {
        throw new RuntimeException('Source ou type de collecte inattendu.');
    }
    $collected = new DateTimeImmutable($metadata['collected_at_utc']);
    if ($collected->format('Y-m-d\\TH:i:s\\Z') !== $metadata['collected_at_utc']
        || ($metadata['non_transactional_tables'] ?? '') !== ($metadata['data_consistency'] === 'mixed_engines_not_atomic' ? 'clients' : 'none')) {
        throw new RuntimeException('Horodatage ou moteurs de collecte incoherents.');
    }
    $metadata['reference_date'] = $collected->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d');
    $metadata['dump_sha256'] = $seen['coffrac.sql.gz'];

    return $metadata;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

try {
    if (($argv[1] ?? '') === '--reference-date' && $argc === 3) {
        echo verifiedCollection($argv[2])['reference_date']."\n";
        exit(0);
    }
    if (($argv[1] ?? '') !== '--seal' || $argc !== 5) {
        throw new RuntimeException('Usage : --reference-date SOURCE ou --seal SOURCE AUDIT_JSONL NEW_REPORT.');
    }
    $metadata = verifiedCollection($argv[2]);
    $handle = fopen($argv[3], 'rb');
    if ($handle === false) {
        throw new RuntimeException('Audit absent.');
    }
    $sections = $counts = [];
    $allowedColumns = [
        'tables' => ['table', 'engine', 'coverage'], 'counts' => ['table', 'rows'],
        'user_types' => ['source_type', 'label', 'type_status', 'status_permissions', 'module_permissions', 'accounts', 'active_not_archived'],
        'password_formats' => ['format', 'accounts'], 'user_quality' => ['metric', 'count'],
        'parent_profiles' => ['child_type', 'parent_type', 'accounts'],
        'statuses' => ['source_id', 'label', 'enabled', 'operations'],
        'relations' => ['metric', 'count'], 'dossier_account_profiles' => ['field', 'source_type', 'dossiers'],
        'appointment_dates' => ['source_table', 'period', 'rows'], 'appointment_quality' => ['metric', 'count'],
        'documents' => ['metric', 'count'], 'document_audiences' => ['private_flag', 'delegataire_flag', 'rows'],
        'comment_audiences' => ['private_flag', 'rows'], 'billing' => ['metric', 'count'], 'catalogue' => ['metric', 'count'],
    ];
    try {
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($row) || ! is_string($row['section'] ?? null)) {
                throw new RuntimeException('Ligne d audit invalide.');
            }
            $section = $row['section'];
            unset($row['section']);
            $columns = $allowedColumns[$section] ?? null;
            if ($columns === null || count($row) !== count($columns) || array_diff(array_keys($row), $columns)) {
                throw new RuntimeException('Section ou champs inattendus : pas de donnees individuelles dans le rapport.');
            }
            if ($section === 'counts') {
                if (! is_string($row['table'] ?? null) || isset($counts[$row['table']])
                    || ! is_int($row['rows'] ?? null) || $row['rows'] < 0) {
                    throw new RuntimeException('Comptage invalide ou duplique.');
                }
                $counts[$row['table']] = $row['rows'];
            } else {
                $sections[$section][] = $row;
            }
        }
    } finally {
        fclose($handle);
    }
    $tables = array_column($sections['tables'] ?? [], 'table');
    if (count($counts) !== 26 || count($tables) !== 26 || count(array_unique($tables)) !== 26
        || array_diff(array_keys($counts), $tables) || array_diff($tables, array_keys($counts))
        || count($sections['user_types'] ?? []) !== 7 || count($sections['relations'] ?? []) < 15
        || count($sections['billing'] ?? []) < 9) {
        throw new RuntimeException('Audit incomplet ou nouveau schema : revue requise.');
    }
    $report = [
        'format' => 'techcalendar.coffrac-production-audit', 'version' => 1,
        'audit_complete' => true, 'native_import_ready' => false, 'final_cutover_ready' => false,
        'prepared_at_utc' => gmdate(DATE_ATOM), 'source' => $metadata,
        'counts' => $counts, 'sections' => $sections,
        'confirmed_account_policy' => [
            'seeded_account_only_super_admin' => true,
            'source_type_1' => ['native_role' => 0, 'admin' => false, 'label' => 'gerant'],
            'other_source_profiles_require_explicit_mapping' => true,
            'preserve_source_inactive_and_archived' => true,
            'reactivate_source_tokens_sessions_or_jobs' => false,
        ],
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (is_link($argv[4]) || file_exists($argv[4])) {
        throw new RuntimeException('Le rapport de destination existe deja.');
    }
    $output = fopen($argv[4], 'x');
    if ($output === false) {
        throw new RuntimeException('Creation du rapport impossible.');
    }
    try {
        if (fwrite($output, $json) !== strlen($json) || ! fflush($output)) {
            throw new RuntimeException('Ecriture du rapport incomplete.');
        }
    } finally {
        fclose($output);
    }
    chmod($argv[4], 0600);
    echo 'Audit verifie : '.count($counts)." tables. Aucun import natif effectue.\n";
} catch (Throwable) {
    // SQL errors or malformed inputs can contain personal data: keep public errors generic.
    fwrite(STDERR, "STOP : collecte, audit ou destination invalide. Aucun rapport valide.\n");
    exit(1);
}
