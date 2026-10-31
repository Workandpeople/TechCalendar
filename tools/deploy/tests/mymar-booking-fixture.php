<?php

declare(strict_types=1);

use App\Services\Mymar\MymarBookingCommand;
use Cofrac2\Deploy\Mymar\MymarBookingFailure;
use Cofrac2\Deploy\Mymar\MymarBookingSource;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess;

require '/workspace/app/Services/Mymar/MymarBookingCommand.php';
require '/workspace/app/Services/Mymar/MymarObservation.php';
require '/workspace/app/Services/Mymar/MymarObservationPolicy.php';
foreach (['MymarBookingFailure', 'MymarBookingSqlAccess', 'MymarBookingSource'] as $class) {
    require '/workspace/tools/deploy/mymar/booking/'.$class.'.php';
}
set_exception_handler(function (Throwable $error): void {
    if ($error instanceof MymarBookingFailure) {
        file_put_contents('/fixtures/adapter-error.log', json_encode($error->diagnostic, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    exit(1);
});
$dsn = 'mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4';
$root = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$password = 'Mb3!'.hash('sha256', 'synthetic-booking-sql-only');
if ($argv[1] === 'prepare') {
    $root->exec(MymarBookingSqlAccess::createSql('cofrac_mymar_rw_abcdef01', $password));
    assert($root->query("SELECT account_locked FROM mysql.user WHERE User='cofrac_mymar_rw_abcdef01'")->fetchColumn() === 'Y');
    $root->exec("ALTER USER 'cofrac_mymar_rw_abcdef01'@'localhost' ACCOUNT UNLOCK");
    exit;
}
class BookingFixturePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            return parent::prepare($query, $options);
        } catch (PDOException $error) {
            file_put_contents('/fixtures/sql-errors.log', $error->getMessage()."\n", FILE_APPEND);
            throw $error;
        }
    }
}
$database = new BookingFixturePdo($dsn, 'cofrac_mymar_rw_abcdef01', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
class BookingFixtureStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (PDOException $error) {
            // Synthetic fixture only; never part of the production adapter or delivery.
            file_put_contents('/fixtures/sql-errors.log', $error->getMessage()."\n", FILE_APPEND);
            throw $error;
        }
    }
}
$database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BookingFixtureStatement::class]);
$source = new MymarBookingSource($database, 99, [5, 6, 7, 9], enabled: true, writesEnabled: true);
$now = new DateTimeImmutable('2026-10-31T09:00:00+00:00');

function command(MymarBookingSource $source, int $dossier, string $uuid, array $override = []): MymarBookingCommand
{
    $items = array_column($source->pending(0, 100)['items'], null, 'dossier_id');

    return MymarBookingCommand::fromArray(array_replace(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
        'request_id' => $uuid, 'action' => 'place', 'dossier_id' => $dossier,
        'expected_revision' => $items[$dossier]['expected_revision'], 'cofrac_actor_id' => 101,
        'technician_id' => 5, 'starts_at' => '2026-11-02T10:40:00+01:00', 'duration_minutes' => 120], $override));
}

function expectFailure(callable $call, string $reason): void
{
    try {
        $call();
        throw new LogicException('Expected failure: '.$reason);
    } catch (MymarBookingFailure $error) {
        assert($error->reason === $reason, $error->reason.' != '.$reason);
    }
}

function snapshot(PDO $root): array
{
    return $root->query('CHECKSUM TABLE MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_historiques,MYMAR.clients,MYMAR.users,cofrac_mymar_integration.booking_commands,cofrac2production.users')->fetchAll(PDO::FETCH_ASSOC);
}

if ($argv[1] === 'race') {
    $payload = json_decode(file_get_contents($argv[2]), true, 8, JSON_THROW_ON_ERROR);
    try {
        echo json_encode($source->execute(MymarBookingCommand::fromArray($payload), $now), JSON_THROW_ON_ERROR);
    } catch (MymarBookingFailure $error) {
        echo json_encode(['error' => $error->reason], JSON_THROW_ON_ERROR);
    }
    exit;
}

if ($argv[1] === 'verify-race') {
    $first = json_decode(file_get_contents('/fixtures/race-first.json'), true, 8, JSON_THROW_ON_ERROR);
    $second = json_decode(file_get_contents('/fixtures/race-second.json'), true, 8, JSON_THROW_ON_ERROR);
    assert((($first['source_committed'] ?? false) && ($second['error'] ?? null) === 'booking_source_changed')
        || (($second['source_committed'] ?? false) && ($first['error'] ?? null) === 'booking_source_changed'));
    assert((int) $root->query('SELECT COUNT(*) FROM MYMAR.dossier_rdv WHERE dossier_id=24 AND is_old=0')->fetchColumn() === 1);
    assert((int) $root->query('SELECT COUNT(*) FROM cofrac_mymar_integration.booking_commands WHERE source_dossier_id=24')->fetchColumn() === 1);
    echo "Concurrent source revisions: one confirmed placement, one rejected, one current appointment.\n";
    exit;
}

$page = $source->pending(0, 2);
assert(array_column($page['items'], 'dossier_id') === [20, 21] && $page['next_after'] === 21);
assert($page['items'][0]['phase'] === 'after_works' && $page['items'][1]['phase'] === 'initial');
assert($page['items'][0]['latitude'] === 48.86 && $page['items'][1]['latitude'] === null);
assert(! str_contains(json_encode($page), 'PASSWORD_CANARY'));
$command = command($source, 20, '11111111-1111-4111-8111-111111111111');
$result = $source->execute($command, $now);
assert($result['source_committed'] === true && $result['status_id'] === 16 && $result['starts_at'] === '2026-11-02T10:40:00+01:00');
assert($result === $source->execute($command, $now));
assert($source->result($command->payload['request_id']) === $result);
assert($source->result('99999999-9999-4999-8999-999999999999') === null);
$stored = $root->query('SELECT date_rdv,duree,author_id,is_old FROM MYMAR.dossier_rdv WHERE id='.$result['rdv_id'])->fetch(PDO::FETCH_ASSOC);
assert($stored === ['date_rdv' => '2026-11-02 10:40:00', 'duree' => 2, 'author_id' => 99, 'is_old' => 0]);
assert((int) $root->query('SELECT is_old FROM MYMAR.dossier_rdv WHERE id=1')->fetchColumn() === 1);
assert((int) $root->query('SELECT author_id FROM MYMAR.dossier_historiques WHERE dossier_id=20')->fetchColumn() === 99);
assert(str_contains($root->query('SELECT text FROM MYMAR.dossier_historiques WHERE dossier_id=20')->fetchColumn(), 'compte #101'));
$before = snapshot($root);
$changed = MymarBookingCommand::fromArray([...$command->payload, 'technician_id' => 6]);
expectFailure(fn () => $source->execute($changed, $now), 'booking_idempotency_conflict');
assert($before === snapshot($root));

$initial = command($source, 21, '22222222-2222-4222-8222-222222222222', ['starts_at' => '2026-11-03T09:00:00+01:00', 'duration_minutes' => 60]);
assert($source->execute($initial, $now)['status_id'] === 3);
assert((int) $root->query('SELECT is_old FROM MYMAR.dossier_rdv WHERE id=2')->fetchColumn() === 1);

foreach ([[7, 'booking_technician_unavailable'], [9, 'booking_technician_unavailable'], [8, 'booking_technician_outside_scope'], [6, 'booking_source_slot_occupied']] as [$technician, $reason]) {
    $candidate = command($source, 25, '33333333-3333-4333-8333-333333333333', ['technician_id' => $technician]);
    $before = snapshot($root);
    expectFailure(fn () => $source->execute($candidate, $now), $reason);
    assert($before === snapshot($root));
}
$stale = command($source, 25, '33333333-3333-4333-8333-333333333333');
$root->exec("UPDATE MYMAR.clients SET adress='Changed elsewhere' WHERE id=1");
$before = snapshot($root);
expectFailure(fn () => $source->execute($stale, $now), 'booking_source_changed');
assert($before === snapshot($root));

foreach ([25 => 2, 26 => 15] as $dossierId => $expectedStatus) {
    $base = command($source, $dossierId, $dossierId === 25 ? '44444444-4444-4444-8444-444444444444' : '55555555-5555-4555-8555-555555555555')->payload;
    unset($base['technician_id'], $base['starts_at'], $base['duration_minutes']);
    $problem = MymarBookingCommand::fromArray([...$base, 'action' => 'problem', 'comment' => 'Beneficiaire absent', 'reason' => 'Absence', 'recall_at' => '2026-11-03T10:00:00+01:00']);
    $beforeRdvs = $root->query('CHECKSUM TABLE MYMAR.dossier_rdv')->fetchAll(PDO::FETCH_ASSOC);
    assert($source->execute($problem, $now)['status_id'] === $expectedStatus);
    assert($beforeRdvs === $root->query('CHECKSUM TABLE MYMAR.dossier_rdv')->fetchAll(PDO::FETCH_ASSOC));
    assert($root->query('SELECT commentaire_status FROM MYMAR.dossiers WHERE id='.$dossierId)->fetchColumn() === 'Beneficiaire absent');
    assert($root->query('SELECT date_rappel FROM MYMAR.dossiers WHERE id='.$dossierId)->fetchColumn() === '2026-11-03 10:00:00');
}

$atomic = command($source, 27, '66666666-6666-4666-8666-666666666666', ['starts_at' => '2026-11-06T09:00:00+01:00']);
$root->exec("CREATE TRIGGER MYMAR.fixture_history_failure BEFORE INSERT ON MYMAR.dossier_historiques FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PRIVATE_SQL_ERROR_CANARY'");
$before = snapshot($root);
expectFailure(fn () => $source->execute($atomic, $now), 'booking_storage_unavailable');
assert($before === snapshot($root));
$root->exec('DROP TRIGGER MYMAR.fixture_history_failure');

$unknown = command($source, 28, '77777777-7777-4777-8777-777777777777');
$root->exec('UPDATE MYMAR.dossier_rdv SET duree=NULL WHERE id=4');
$before = snapshot($root);
expectFailure(fn () => $source->execute($unknown, $now), 'booking_source_availability_unknown');
assert($before === snapshot($root));
$root->exec('UPDATE MYMAR.dossier_rdv SET duree=2 WHERE id=4');

// Durable commands for two independent processes simulating concurrent planners.
$first = command($source, 24, '88888888-8888-4888-8888-888888888888', ['starts_at' => '2026-11-10T09:00:00+01:00']);
$second = MymarBookingCommand::fromArray([...$first->payload, 'request_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'starts_at' => '2026-11-11T09:00:00+01:00']);
file_put_contents('/fixtures/race-first-command.json', json_encode($first->payload, JSON_THROW_ON_ERROR));
file_put_contents('/fixtures/race-second-command.json', json_encode($second->payload, JSON_THROW_ON_ERROR));
echo "Pending pagination, both phases, Paris time, source overlaps, idempotence, history and rollback: passed.\n";
