<?php

declare(strict_types=1);

use App\Services\Mymar\MymarVt2Command;
use Cofrac2\Deploy\Mymar\MymarDocumentFailure;
use Cofrac2\Deploy\Mymar\MymarDocumentFiles;
use Cofrac2\Deploy\Mymar\MymarDocumentSource;
use Cofrac2\Deploy\Mymar\MymarDocumentSqlAccess;

require '/workspace/vendor/autoload.php';
class SyntheticDocumentStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (PDOException $error) {
            fwrite(STDERR, 'FIXTURE_SQL_CODE='.($error->errorInfo[1] ?? 0).' QUERY='.$this->queryString.PHP_EOL);
            throw $error;
        }
    }
}
class SyntheticDocumentPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            return parent::prepare($query, $options);
        } catch (PDOException $error) {
            fwrite(STDERR, 'FIXTURE_PREPARE_CODE='.($error->errorInfo[1] ?? 0).' QUERY='.$query.PHP_EOL);
            throw $error;
        }
    }
}
foreach (['Failure', 'Policy', 'Files', 'Source', 'SqlAccess'] as $file) {
    require '/workspace/tools/deploy/mymar/documents/MymarDocument'.$file.'.php';
}
$db = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$now = new DateTimeImmutable('2026-11-05T10:00:00Z');
$pdf = "%PDF-1.4\nSynthetic VT2 ONLY\n%%EOF\n";
if (($argv[1] ?? '') === 'race') {
    $command = MymarVt2Command::fromArray(json_decode(file_get_contents($argv[2]), true, 8, JSON_THROW_ON_ERROR));
    $writer = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4', 'cofrac_mymar_dw_abcdef01', 'Md4!'.str_repeat('a', 64),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $files = new MymarDocumentFiles('/fixtures/uploads', '/fixtures/staging', 0);
    $source = new MymarDocumentSource($writer, $files, [5, 6, 7], 99, true, true);
    try {
        $result = ['confirmed' => true, 'result' => $source->deposit($command, $now)];
    } catch (MymarDocumentFailure $error) {
        $result = ['confirmed' => false, 'reason' => $error->reason];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if (($argv[1] ?? '') === 'verify-race') {
    $results = array_map(static fn ($path) => json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR), ['/fixtures/race-one.json', '/fixtures/race-two.json']);
    assert(count(array_filter($results, static fn ($result) => $result['confirmed'])) === 1);
    assert(array_values(array_filter($results, static fn ($result) => ! $result['confirmed']))[0]['reason'] === 'documents_source_changed');
    assert((int) $db->query('SELECT COUNT(*) FROM MYMAR.dossier_documents WHERE dossier_id=21')->fetchColumn() === 2);
    assert((int) $db->query('SELECT COUNT(*) FROM MYMAR.dossier_historiques WHERE dossier_id=21')->fetchColumn() === 1);
    assert((int) $db->query('SELECT COUNT(*) FROM cofrac_mymar_integration.document_commands WHERE source_dossier_id=21')->fetchColumn() === 1);
    echo "Concurrence : un seul depot valide, seconde demande refusee sans doublon.\n";
    exit;
}
mkdir('/fixtures/uploads', 0700);
mkdir('/fixtures/staging', 0700);
file_put_contents('/fixtures/outside.pdf', '%PDF-PRIVATE OUTSIDE');
foreach (['invoice', 'amo', 'identity', 'quote', 'other'] as $name) {
    file_put_contents('/fixtures/uploads/'.$name.'.pdf', '%PDF-Synthetic '.$name.' document');
}
symlink('/fixtures/outside.pdf', '/fixtures/uploads/link.pdf');
$files = new MymarDocumentFiles('/fixtures/uploads', '/fixtures/staging', 0);
$source = new MymarDocumentSource($db, $files, [5, 6, 7], 99, true, true);
$password = 'Md4!'.str_repeat('a', 64);
foreach ([false, true] as $writer) {
    $user = 'cofrac_mymar_'.($writer ? 'dw' : 'dr').'_abcdef01';
    foreach (array_filter(explode(";\n", MymarDocumentSqlAccess::createSql($user, $password, $writer))) as $sql) {
        $db->exec($sql);
    }
    assert($db->query("SELECT account_locked FROM mysql.user WHERE User='$user'")->fetchColumn() === 'Y');
    // Unlock only disposable fixture accounts. Production preparation must leave them locked.
    $db->exec("ALTER USER '$user'@'localhost' ACCOUNT UNLOCK");
}
$connect = static fn (string $user): PDO => new SyntheticDocumentPdo('mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4', $user, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$readerDb = $connect('cofrac_mymar_dr_abcdef01');
$writerDb = $connect('cofrac_mymar_dw_abcdef01');
$writerDb->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SyntheticDocumentStatement::class]);
$source = new MymarDocumentSource($writerDb, $files, [5, 6, 7], 99, true, true);
$reader = new MymarDocumentSource($readerDb, $files, [5, 6, 7], 99, true);
$denied = static function (Closure $action, string $reason): void {
    try {
        $action();
    } catch (MymarDocumentFailure $error) {
        assert($error->reason === $reason, $error->reason.' !== '.$reason);

        return;
    }
    throw new RuntimeException('Expected denial: '.$reason);
};
$make = static function (array $changes = []) use ($source, $pdf): MymarVt2Command {
    $catalogue = $source->catalogue(40, 5);

    return MymarVt2Command::fromArray(array_replace(['format' => 'cofrac.mymar-vt2-command', 'version' => 1,
        'request_id' => '11223344-5566-4788-99aa-'.bin2hex(random_bytes(6)), 'cofrac_actor_id' => 101,
        'dossier_id' => 20, 'rdv_id' => 40, 'technician_id' => 5, 'expected_revision' => $catalogue['expected_revision'],
        'sha256' => hash('sha256', $pdf), 'bytes' => strlen($pdf)], $changes));
};
$stage = static function (MymarVt2Command $command) use ($files, $pdf): void {
    $input = fopen('php://temp', 'w+b');
    fwrite($input, $pdf);
    rewind($input);
    $files->stage($input, $command);
    fclose($input);
};
$count = static fn (string $table): int => (int) $db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
$status = static fn (): int => (int) $db->query('SELECT status_id FROM MYMAR.dossiers WHERE id=20')->fetchColumn();

$catalogue = $reader->catalogue(40, 5);
assert(array_column($catalogue['documents'], 'id') === [4, 1]);
assert(! str_contains(json_encode($catalogue), 'path') && ! str_contains(json_encode($catalogue), 'AMO'));
$stream = $source->open(40, 5, 1);
assert(str_contains(stream_get_contents($stream), 'invoice'));
fclose($stream);
foreach ([2, 3, 5, 6, 7, 8, 9] as $id) {
    $denied(fn () => $source->open(40, 5, $id), 'documents_file_unavailable');
}
$genericDocuments = [
    [10, 'FACTURE', '1793961996_FACTURE MR ET MME BENEFICIARY.pdf', 'invoice'],
    [11, 'devis', '1793961996_DEVIS SIGNE BENEFICIARY.pdf', 'quote'],
    [12, 'FACTURE', '1793961996_FACTURE AMO BENEFICIARY.pdf', null],
    [13, 'Facture AMO', '1793961996_FACTURE MR ET MME BENEFICIARY.pdf', null],
    [14, 'Nouveau devis', '1793961996_Nouveau devis _BENEFICIARY (2).pdf', 'quote'],
    [15, 'FACTURE', '1793961996_DEVIS SIGNE BENEFICIARY.pdf', 'invoice'],
    [16, 'FACTURE', '1793961996_scan.pdf', 'invoice'],
    [17, 'Devis retour ANAH', '1793961996_scan2.pdf', 'quote'],
    [18, 'Piece jointe', '1793961996_Facture2026-003.pdf', 'invoice'],
    [19, 'FACTURE + DEVIS', '1793961996_regroupement.pdf', 'invoice'],
    [20, null, '1793961996_DEVIS2026.pdf', 'quote'],
    [21, 'FACTURE', '1793961996_FACTURE_RIB.pdf', null],
    [22, 'Facture acompte travaux', '1793961996_scan3.pdf', 'invoice'],
];
$insert = $db->prepare('INSERT INTO MYMAR.dossier_documents (id,dossier_id,author_id,path,nom,is_public) VALUES (?,20,99,?,?,1)');
foreach ($genericDocuments as [$id, $label, $filename]) {
    file_put_contents('/fixtures/uploads/'.$filename, '%PDF-Synthetic generic document');
    $insert->execute([$id, $filename, $label]);
}
$listed = $reader->catalogue(40, 5)['documents'];
$expected = array_column(array_reverse(array_values(array_filter($genericDocuments, static fn ($row) => $row[3] !== null))), 0);
assert(array_column($listed, 'id') === [...$expected, 4, 1]);
assert(array_values(array_filter($listed, static fn ($row) => $row['id'] === 20))[0]['name'] === 'Devis');
foreach ($genericDocuments as [$id, $label, $filename, $kind]) {
    if ($kind === null) {
        $denied(fn () => $reader->open(40, 5, $id), 'documents_file_unavailable');
    } else {
        $stream = $reader->open(40, 5, $id);
        assert(str_contains(stream_get_contents($stream), 'Synthetic generic document'));
        fclose($stream);
        assert(array_values(array_filter($listed, static fn ($row) => $row['id'] === $id))[0]['kind'] === $kind);
    }
}
$db->exec("UPDATE MYMAR.dossier_documents SET nom='Facture AMO' WHERE id=16");
$denied(fn () => $reader->open(40, 5, 16), 'documents_file_unavailable');
$db->exec('DELETE FROM MYMAR.dossier_documents WHERE id BETWEEN 10 AND 22');
echo "Factures et devis : tous les depots correspondants, titres et fichiers generiques acceptes ; AMO et pieces sensibles refuses meme apres modification.\n";
$denied(fn () => $source->catalogue(40, 6), 'documents_appointment_not_found');
$denied(fn () => $source->catalogue(40, 999), 'documents_appointment_not_found');
$db->exec('UPDATE MYMAR.users SET enabled=0 WHERE id=5');
$denied(fn () => $source->open(40, 5, 1), 'documents_appointment_not_found');
$db->exec('UPDATE MYMAR.users SET enabled=1 WHERE id=5');
$db->exec('UPDATE MYMAR.dossiers SET technicien_id=6 WHERE id=20');
$denied(fn () => $source->open(40, 5, 1), 'documents_appointment_not_found');
$db->exec('UPDATE MYMAR.dossiers SET technicien_id=5 WHERE id=20');
$db->exec('INSERT INTO MYMAR.dossier_rdv VALUES (42,20,\'2026-11-04 10:00:00\',0,NULL)');
$denied(fn () => $source->catalogue(40, 5), 'documents_source_changed');
$db->exec('DELETE FROM MYMAR.dossier_rdv WHERE id=42');
echo "Catalogue minimal, droits courants, pieces AMO/identite et chemins dangereux refuses.\n";

$disabled = new MymarDocumentSource($db, $files, [5], 99);
$denied(fn () => $disabled->catalogue(40, 5), 'documents_disabled');
$denied(fn () => $disabled->deposit($make(), $now), 'documents_upload_disabled');
assert($count('cofrac_mymar_integration.document_commands') === 0);

$command = $make();
$stage($command);
$db->exec("UPDATE MYMAR.dossiers SET updated_at='2026-11-05 08:01:00' WHERE id=20");
$denied(fn () => $source->deposit($command, $now), 'documents_source_changed');
assert($count('MYMAR.dossier_documents') === 9 && $status() === 16);
assert($count('cofrac_mymar_integration.document_commands') === 0);

$command = $make();
$stage($command);
$denied(fn () => $source->deposit($command, new DateTimeImmutable('2026-11-01T10:00:00Z')), 'documents_visit_not_started');
assert($status() === 16 && $count('MYMAR.dossier_historiques') === 0);
$db->exec('UPDATE MYMAR.users SET enabled=1 WHERE id=99');
$denied(fn () => $source->deposit($command, $now), 'documents_workflow_changed');
$db->exec('UPDATE MYMAR.users SET enabled=0 WHERE id=99');
$db->exec("UPDATE MYMAR.status SET name='Depot fini' WHERE id=19");
$denied(fn () => $source->deposit($command, $now), 'documents_workflow_changed');
$db->exec("UPDATE MYMAR.status SET name='DEPOT (solde)' WHERE id=19");

// Force a failure after the document INSERT: all source rows and the journal must roll back.
$db->exec('ALTER TABLE MYMAR.dossier_historiques ADD CONSTRAINT synthetic_failure CHECK (status_id<>19)');
$denied(fn () => $source->deposit($command, $now), 'documents_source_unavailable');
assert($status() === 16 && $count('MYMAR.dossier_documents') === 9 && $count('MYMAR.dossier_historiques') === 0);
assert($count('cofrac_mymar_integration.document_commands') === 0);
assert($source->result($command->payload['request_id']) === null);
$db->exec('ALTER TABLE MYMAR.dossier_historiques DROP CHECK synthetic_failure');
echo "Echec tardif : document, statut, historique et accuse annules ensemble.\n";

$result = $source->deposit($command, $now);
assert($result['status_id'] === 19 && $result['source_committed'] === true && $status() === 19);
assert($count('MYMAR.dossier_documents') === 10 && $count('MYMAR.dossier_historiques') === 1);
$document = $db->query('SELECT * FROM MYMAR.dossier_documents ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assert($document['is_public'] === 0 && $document['is_validated'] === 0 && $document['author_id'] === 99);
assert(str_contains($document['nom'], 'VT2') && is_file('/fixtures/uploads/'.$document['path']));
assert($source->deposit($command, $now) == $result && $source->result($command->payload['request_id']) == $result);
assert($count('MYMAR.dossier_documents') === 10 && $count('MYMAR.dossier_historiques') === 1);
$changed = MymarVt2Command::fromArray(array_replace($command->payload, ['cofrac_actor_id' => 102]));
$denied(fn () => $source->deposit($changed, $now), 'documents_idempotency_conflict');
assert($count('MYMAR.dossier_documents') === 10);
$db->exec("GRANT SELECT ON MYMAR.* TO 'cofrac_mymar_dw_abcdef01'@'localhost'");
$denied(fn () => $source->catalogue(40, 5), 'documents_unsafe_grants');
$db->exec("REVOKE SELECT ON MYMAR.* FROM 'cofrac_mymar_dw_abcdef01'@'localhost'");
$db->exec('ALTER TABLE cofrac_mymar_integration.document_commands ENGINE=MyISAM');
$denied(fn () => $source->catalogue(40, 5), 'documents_schema_refused');
$db->exec('ALTER TABLE cofrac_mymar_integration.document_commands ENGINE=InnoDB');
assert((int) $db->query('SELECT COUNT(*) FROM MYMAR.users WHERE password=\'PASSWORD_CANARY\'')->fetchColumn() === 4);
assert(file_get_contents('/fixtures/outside.pdf') === '%PDF-PRIVATE OUTSIDE');
foreach ([$readerDb, $writerDb] as $limitedDb) {
    foreach (['SELECT password FROM MYMAR.users', 'DELETE FROM MYMAR.dossier_documents WHERE id=1',
        'UPDATE MYMAR.dossier_rdv SET is_old=1 WHERE id=40', 'UPDATE MYMAR.dossier_documents SET path=\'evil.pdf\' WHERE id=1',
        'UPDATE MYMAR.users SET enabled=1 WHERE id=99'] as $sql) {
        try {
            $limitedDb->exec($sql);
            throw new RuntimeException('Unexpected grant: '.$sql);
        } catch (PDOException $error) {
            assert(in_array($error->errorInfo[1] ?? null, [1142, 1143], true));
        }
    }
}
echo "Depot 19 confirme, portee privee MYMAR et auteur technique ; reprise sans doublon, identites conservees.\n";
$revision = $source->catalogue(41, 6)['expected_revision'];
foreach (['one', 'two'] as $suffix) {
    $command = $make(['dossier_id' => 21, 'rdv_id' => 41, 'technician_id' => 6, 'expected_revision' => $revision]);
    $stage($command);
    file_put_contents('/fixtures/race-'.$suffix.'-command.json', json_encode($command->payload, JSON_THROW_ON_ERROR));
}
