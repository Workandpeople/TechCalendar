<?php

function coffracAuditConnection(): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = class_exists(\Pdo\Sqlite::class)
        ? new \Pdo\Sqlite('sqlite::memory:', options: $options)
        : new PDO('sqlite::memory:', options: $options);
    $registerFunction = method_exists($pdo, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
    $pdo->{$registerFunction}('CHAR_LENGTH', fn ($value) => $value === null ? null : mb_strlen($value), 1);
    $pdo->{$registerFunction}('DATABASE', fn () => 'coffrac_test', 0);
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, TABLE_ROWS, TABLE_SCHEMA)');
    $pdo->exec("INSERT INTO information_schema.TABLES VALUES ('dossiers', 'BASE TABLE', 'InnoDB', 'utf8mb3', 4, 'coffrac_test')");

    $tables = [
        'status' => 'id, name, autorisation, status, orders, has_mail, mail_content',
        'user_type' => 'id, name, autorisation, autorisation_types, status',
        'fiche_type' => 'id, name, price, status, showDetailChantier, showEntreprise, is_audit, rapport',
        'migrations' => 'id, migration, batch',
        'users' => 'id, type, actif, is_archived, email, password, remember_token',
        'dossiers' => 'id, dossier_unique, status_id, adress, ville, zip, client_name, technicien_id, delegataire_id, client_id, entreprise_id',
        'dossier_fiches' => 'id, dossier_id, fiche_id, status_id, is_favorable, rapport',
        'dossier_fiche_rdv' => 'id, dossier_id, dossier_fiche, date_rdv, heure, rdv_duree',
        'dossier_document' => 'id, is_private, is_delegataire, author_id, path',
        'dossier_comment' => 'id, is_private, text',
        'dossier_historique' => 'id',
        'dossier_fiche_facturation' => 'id',
        'dossier_letige' => 'id',
        'rdv_extern' => 'id',
        'planings' => 'id',
        'lot_dossier' => 'id',
        'entreprise' => 'id',
        'fiche_document' => 'id',
        'media' => 'id',
        'clients' => 'id',
    ];

    foreach ($tables as $table => $columns) {
        $pdo->exec("CREATE TABLE {$table} ({$columns})");
    }

    return $pdo;
}

test('coffrac reference audit reports empty tables without exposing arbitrary configuration', function () {
    $collect = require __DIR__.'/../../tools/coffrac/collect-reference-audit.php';
    $report = $collect(coffracAuditConnection(), ['rapport_avis' => ['SATISFAISANT'], 'api_token' => 'private-token']);

    expect($report['complete'])->toBeTrue()
        ->and($report['consistent_snapshot'])->toBeFalse()
        ->and($report['workflow_configuration'])->toBe(['rapport_avis' => ['SATISFAISANT']])
        ->and($report['sections']['dossier_quality']['rows'][0]['total'])->toBe(0)
        ->and($report['sections']['dossier_quality']['rows'][0]['missing_address'])->toBe(0)
        ->and($report['sections']['tables']['rows'][0]['table_name'])->toBe('dossiers');
});

test('coffrac reference audit detects multiplicity and mapping risks using aggregates only', function () {
    $pdo = coffracAuditConnection();
    $pdo->exec("INSERT INTO status (id, name) VALUES (2, 'RDV ATTENTE VISITE')");
    $pdo->exec("INSERT INTO fiche_type (id, name, rapport) VALUES (1, 'BAR EN 101', 'PRIVATE_FORM_ANSWER')");
    $pdo->exec("INSERT INTO users (id, type, actif, is_archived, email, password, remember_token) VALUES
        (1, 5, 1, 0, 'private@example.com', 'PRIVATE_PASSWORD', 'PRIVATE_TOKEN'),
        (2, 5, 1, 0, ' PRIVATE@example.com ', 'PRIVATE_PASSWORD', 'PRIVATE_TOKEN')");
    $pdo->exec("INSERT INTO dossiers (id, dossier_unique, client_name, technicien_id) VALUES
        (1, 'PRIVATE_REFERENCE', 'PRIVATE_CUSTOMER', 1),
        (2, 'PRIVATE_REFERENCE', 'PRIVATE_CUSTOMER', 999)");
    $pdo->exec("INSERT INTO dossier_fiches (id, dossier_id, fiche_id, status_id, is_favorable, rapport) VALUES
        (10, 1, 1, 2, 0, 'PRIVATE_REPORT_ANSWER'), (11, 1, 1, 999, 1, 'PRIVATE_REPORT_ANSWER')");
    $pdo->exec("INSERT INTO dossier_fiche_rdv (id, dossier_id, dossier_fiche, date_rdv, heure, rdv_duree) VALUES
        (20, 1, 10, '2026-10-20', '14:00', 1), (21, 2, 10, NULL, NULL, 0)");
    $pdo->exec("INSERT INTO dossier_document (id, is_private, is_delegataire, author_id, path) VALUES
        (1, 1, 1, 999, 'PRIVATE_FILE.pdf')");
    $pdo->exec("INSERT INTO dossier_comment (id, is_private, text) VALUES (1, 1, 'PRIVATE_COMMENT')");
    $pdo->exec("UPDATE status SET mail_content = 'PRIVATE_MAIL'");

    $before = $pdo->query('SELECT * FROM dossier_fiche_rdv')->fetchAll();
    $collect = require __DIR__.'/../../tools/coffrac/collect-reference-audit.php';
    $report = $collect($pdo);
    $sections = $report['sections'];

    expect($report['complete'])->toBeTrue()
        ->and($sections['duplicate_dossier_references']['rows'][0]['duplicate_groups'])->toBe(1)
        ->and($sections['duplicate_normalized_emails']['rows'][0]['duplicate_groups'])->toBe(1)
        ->and($sections['missing_operation_references']['rows'][0]['statuses'])->toBe(1)
        ->and($sections['missing_dossier_actors']['rows'][0]['technicians'])->toBe(1)
        ->and($sections['missing_document_actors']['rows'][0]['missing_authors'])->toBe(1)
        ->and($sections['appointment_quality']['rows'][0]['conflicting_dossier'])->toBe(1)
        ->and($sections['appointment_quality']['rows'][0]['missing_schedule'])->toBe(1)
        ->and($sections['appointment_quality']['rows'][0]['invalid_duration'])->toBe(1)
        ->and($sections['operation_multiplicity']['rows'])->toBe([
            ['operation_count' => 0, 'dossiers' => 1], ['operation_count' => 2, 'dossiers' => 1],
        ])
        ->and($sections['appointment_multiplicity']['rows'])->toBe([
            ['appointment_count' => 0, 'operations' => 1], ['appointment_count' => 2, 'operations' => 1],
        ])
        ->and(json_encode($report))->not->toContain('PRIVATE_', 'private@example.com')
        ->and($pdo->query('SELECT * FROM dossier_fiche_rdv')->fetchAll())->toBe($before);
});

test('coffrac reference audit flags partial results instead of treating query errors as zero', function () {
    $pdo = coffracAuditConnection();
    $pdo->exec('DROP TABLE status');
    $collect = require __DIR__.'/../../tools/coffrac/collect-reference-audit.php';
    $report = $collect($pdo);

    expect($report['complete'])->toBeFalse()
        ->and($report['sections']['statuses'])->toBe(['status' => 'error', 'sqlstate' => 'HY000'])
        ->and($report['sections']['service_forms']['status'])->toBe('ok');
});

test('coffrac reference audit only sends bounded selects and suppresses raw database errors', function () {
    $pdo = new class extends PDO
    {
        public array $queries = [];

        public function __construct() {}

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $this->queries[] = $query;

            throw new PDOException('PRIVATE_DATABASE_ERROR containing private@example.com');
        }
    };
    $collect = require __DIR__.'/../../tools/coffrac/collect-reference-audit.php';
    $report = $collect($pdo);

    expect($pdo->queries)->not->toBeEmpty()
        ->and($report['complete'])->toBeFalse()
        ->and(json_encode($report))->not->toContain('PRIVATE_DATABASE_ERROR', 'private@example.com');

    foreach ($pdo->queries as $query) {
        expect($query)->toStartWith('SELECT /*+ MAX_EXECUTION_TIME(10000) */ ')
            ->not->toMatch('/;|FOR\s+UPDATE|LOCK\s+IN\s+SHARE|INTO\s+OUTFILE|INTO\s+DUMPFILE/i');
    }
});
