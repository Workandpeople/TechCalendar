<?php

declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;
use Cofrac2\Deploy\Mymar\MymarTechnicianRoster as Roster;
use Cofrac2\Deploy\MymarReadOnlyAudit;

ini_set('display_errors', '0');
umask(0077);

try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 0 || explode('.', gethostname())[0] !== 'ns365601'
        || $argc !== 2 || ! preg_match('~^/var/lib/cofrac2-production/mymar-roster-[A-Za-z0-9]+$~D', $argv[1])) {
        throw new RuntimeException('mymar.invalid_roster_invocation');
    }
    require __DIR__.'/MymarTechnicianRoster.php';
    require __DIR__.'/MymarReadOnlyAudit.php';
    require __DIR__.'/MymarRuntimeState.php';
    $run = $argv[1];
    if (realpath($run) !== $run || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700 || disk_free_space($run) < 16 * 1024 ** 2) {
        throw new RuntimeException('mymar.unsafe_roster_directory_or_space');
    }
    $proof = [];
    foreach (['/home/ubuntu/www/MYMAR' => 'MYMAR', '/var/www/cofrac2' => 'cofrac2production'] as $root => $schema) {
        if (! (MymarReadOnlyAudit::identity($root, $schema)['sql_eligible'] ?? false)) {
            throw new RuntimeException('mymar.unconfirmed_local_database');
        }
        foreach (['.env', 'bootstrap/cache/config.php'] as $relative) {
            $path = $root.'/'.$relative;
            if (is_link($path) || (file_exists($path) && realpath($path) !== $path)) {
                throw new RuntimeException('mymar.indirect_roster_configuration');
            }
            $proof[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
    }
    $path = '/etc/cofrac2-mymar-actions/actions.json';
    if (realpath($path) !== $path || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0640) {
        throw new RuntimeException('mymar.unsafe_existing_mapping');
    }
    $proof[$path] = hash_file('sha256', $path);
    $current = State::read($path, 32768);
    if (($current['format'] ?? null) !== 'cofrac.mymar-actions-client' || ($current['version'] ?? null) !== 1
        || ! is_array($current['bindings'] ?? null) || ! array_is_list($current['bindings']) || count($current['bindings']) > 200) {
        throw new RuntimeException('mymar.invalid_existing_mapping');
    }
    $confirmed = State::read(__DIR__.'/confirmed-identities.json')['bindings'] ?? null;
    if (! is_array($confirmed) || ! array_is_list($confirmed) || count($confirmed) > 200) {
        throw new RuntimeException('mymar.invalid_roster_approval');
    }
    $raw = State::admin(Roster::sql(bin2hex(random_bytes(32))), $run);
    $records = array_map(fn ($line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), explode("\n", $raw));
    $snapshot = Roster::snapshot($records, new DateTimeImmutable);
    State::assertProof($proof);
    $report = Roster::plan($snapshot, $current['bindings'], $confirmed);
    State::write($run.'/report.json', $report);
    $approval = ['format' => 'cofrac.mymar-approved-identities', 'version' => 1,
        'bindings' => array_map(fn ($r) => array_intersect_key($r, array_flip(['source_technician_id', 'source_name', 'cofrac_user_id', 'cofrac_name'])), $report['links'])];
    State::write($run.'/proposed-identities.private.json', $approval);
    State::write($run.'/configuration-proof.private.json', $proof);
    echo json_encode($report['summary'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    echo "Plan prepare seulement. Aucune correspondance active modifiee, aucun compte cree ou reactive.\n";
} catch (Throwable $error) {
    $reason = $error instanceof RuntimeException && preg_match('/^mymar\.[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'mymar.roster_collection_failed';
    fwrite(STDERR, 'STOP : '.$reason.'. Aucun detail personnel ni secret affiche.'.PHP_EOL);
    exit(1);
}
