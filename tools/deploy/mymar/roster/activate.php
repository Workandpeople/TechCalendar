<?php

declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarRosterActivation as Activation;
use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;
use Cofrac2\Deploy\Mymar\MymarTechnicianRoster as Roster;
use Cofrac2\Deploy\MymarReadOnlyAudit;

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
umask(0077);
$published = false;
$changed = [];
$files = $contents = [];
try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 0 || explode('.', gethostname())[0] !== 'ns365601'
        || $argc !== 3 || ! in_array($argv[1], ['--apply', '--recover'], true)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-roster-activation-[A-Za-z0-9]+$~D', $argv[2])
        || realpath($argv[2]) !== $argv[2] || fileowner($argv[2]) !== 0 || (fileperms($argv[2]) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_roster_activation_invocation');
    }
    $run = $argv[2];
    require __DIR__.'/MymarRosterActivation.php';
    require __DIR__.'/MymarTechnicianRoster.php';
    require __DIR__.'/MymarReadOnlyAudit.php';
    require __DIR__.'/MymarRuntimeState.php';
    require '/var/www/cofrac2/vendor/autoload.php';
    if ($argv[1] === '--recover') {
        $backup = State::read($run.'/before.private.json');
        $planned = State::read($run.'/planned.private.json');
        $current = Activation::readFiles();
        // Once exposed, new technicians may already have real booking commands: never shrink their scope blindly.
        if ($current['client']['content'] !== $backup['client']['content']) {
            throw new RuntimeException('mymar.roster_already_published_or_client_changed');
        }
        foreach (array_reverse(array_keys(Activation::PATHS)) as $name) {
            $expected = hash('sha256', $planned[$name]);
            if ($current[$name]['content'] === $backup[$name]['content']) {
                continue;
            }
            Activation::replace($name, $expected, $backup[$name]['content'], $backup[$name]);
        }
        echo "Preparation interrompue restauree. Aucun compte ni rendez-vous modifie.\n";
        exit;
    }
    $request = State::read(__DIR__.'/REQUEST.json');
    if (($request['format'] ?? null) !== 'cofrac.mymar-roster-activation' || ($request['version'] ?? null) !== 1
        || ! is_array($request['runtime_contract'] ?? null) || count($request['runtime_contract']) < 5) {
        throw new RuntimeException('mymar.invalid_roster_activation_request');
    }
    $proof = [];
    foreach ($request['runtime_contract'] as $relative => $sha) {
        if (! preg_match('~^app/(Models|Services/Mymar)/[A-Za-z]+\.php$~D', $relative) || ! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            throw new RuntimeException('mymar.invalid_roster_runtime_contract');
        }
        $proof['/var/www/cofrac2/'.$relative] = $sha;
    }
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
    State::assertProof($proof);
    $files = Activation::readFiles();
    $configs = array_map(fn ($file) => json_decode($file['content'], true, 16, JSON_THROW_ON_ERROR), $files);
    $confirmed = State::read(__DIR__.'/confirmed-identities.json')['bindings'] ?? [];
    $readPlan = static function () use ($run, $configs, $confirmed): array {
        $raw = State::admin(Roster::sql(bin2hex(random_bytes(32))), $run);
        $rows = array_map(fn ($line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), explode("\n", $raw));

        return Roster::plan(Roster::snapshot($rows, new DateTimeImmutable), $configs['client']['bindings'], $confirmed);
    };
    $plan = $readPlan();
    State::write($run.'/plan.json', $plan);
    if ($plan['existing_mapping_issues'] !== []) {
        throw new RuntimeException('mymar.existing_roster_mapping_invalid');
    }
    $expanded = Activation::prepare($configs, $plan['links']);
    $pairs = $expanded['client']['bindings'];
    foreach (['/etc/cofrac2-mymar-actions/api-token' => $configs['writer']['token'],
        '/etc/cofrac2-mymar-booking/reader-token' => $configs['reader']['token']] as $path => $token) {
        if (realpath($path) !== $path || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0640
            || filesize($path) > 128 || ! hash_equals($token, trim(file_get_contents($path)))) {
            throw new RuntimeException('mymar.roster_token_mismatch');
        }
        $proof[$path] = hash_file('sha256', $path);
    }
    foreach (['/var/lib/cofrac-mymar-gateway/private/config.json', '/etc/cofrac2-mymar/api-token',
        '/etc/cofrac2-mymar-booking/reader.json'] as $path) {
        if (realpath($path) !== $path || ! is_file($path) || filesize($path) > 32768) {
            throw new RuntimeException('mymar.unsafe_roster_configuration');
        }
        $proof[$path] = hash_file('sha256', $path);
    }
    $contents = array_map(fn ($config) => json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n", $expanded);
    // Raw backups, including SQL credentials, never leave the root-only preparation directory.
    State::write($run.'/before.private.json', $files);
    State::write($run.'/planned.private.json', $contents);
    State::write($run.'/proof.private.json', $proof);
    $recover = "#!/usr/bin/env bash\nset -Eeuo pipefail\numask 077\nexport PATH=/usr/sbin:/usr/bin:/sbin:/bin\n"
        ."[ \"\$EUID\" = 0 ] && [ \"\$(hostname -s)\" = ns365601 ] || exit 1\n"
        ."exec 7>/var/lib/cofrac2-production/mymar-actions.lock; flock -n 7 || exit 1\n"
        ."exec 8>/var/lib/cofrac2-production/two-lots-release.lock; flock -n 8 || exit 1\n"
        ."exec 9>/var/lib/cofrac2-production/mymar-readonly-audit.lock; flock -n 9 || exit 1\n"
        ."cd '$run/bundle'; sha256sum -c SHA256SUMS\nphp8.4 ./activate.php --recover '$run'\n";
    if (file_put_contents($run.'/recover-preparation.sh', $recover) !== strlen($recover) || ! chmod($run.'/recover-preparation.sh', 0600)) {
        throw new RuntimeException('mymar.roster_recovery_artifact_failed');
    }
    echo json_encode($plan['summary'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    echo "Elargissement des passerelles privees. Aucun arret de service, compte ou habilitation modifie.\n";
    foreach (['occupations', 'reader', 'writer', 'pilot'] as $name) {
        State::assertProof($proof);
        Activation::replace($name, $files[$name]['sha256'], $contents[$name], $files[$name]);
        $changed[] = $name;
    }
    $expected = json_encode(['expanded' => $pairs, 'current_client' => $configs['client']], JSON_THROW_ON_ERROR);
    $process = proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=3', '90', '/usr/sbin/runuser', '-u', 'cofrac2-app', '--',
        '/usr/bin/env', '-i', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'HOME=/var/lib/cofrac2-app', 'USER=cofrac2-app', 'LOGNAME=cofrac2-app',
        '/usr/bin/php8.4', '-d', 'display_errors=0', '-d', 'zend.exception_ignore_args=1', '-d', 'memory_limit=256M', '--', $expected],
        [0 => ['file', __DIR__.'/runtime-probe.php', 'r'], 1 => ['file', $run.'/cache-probe.json', 'w'], 2 => ['file', $run.'/cache-probe.private.stderr', 'w']], $pipes);
    if (! is_resource($process) || proc_close($process) !== 0 || (State::read($run.'/cache-probe.json')['ready'] ?? false) !== true) {
        throw new RuntimeException('mymar.expanded_roster_cache_not_ready');
    }
    $fresh = $readPlan();
    if ($fresh['existing_mapping_issues'] !== [] || Activation::pairs($fresh['links']) !== $pairs) {
        throw new RuntimeException('mymar.roster_identity_changed_before_publication');
    }
    State::assertProof($proof);
    $current = Activation::readFiles();
    foreach (['occupations', 'reader', 'writer', 'pilot'] as $name) {
        if ($current[$name]['content'] !== $contents[$name]) {
            throw new RuntimeException('mymar.roster_configuration_changed');
        }
    }
    // Commit point. All preceding changes only widen source scopes; existing clients keep their original map.
    Activation::replace('client', $files['client']['sha256'], $contents['client'], $files['client']);
    $published = true;
    State::write($run.'/summary.json', ['format' => 'cofrac.mymar-roster-activation-result', 'version' => 1, 'complete' => true,
        'mapped_technicians' => count($pairs), 'previous_technicians' => count($configs['client']['bindings']),
        'new_links' => count($pairs) - count($configs['client']['bindings']), 'unresolved_source_identities' => count($plan['review']),
        'unlinked_cofrac_technicians' => count($plan['unlinked_cofrac']), 'checked_at_utc' => gmdate(DATE_ATOM),
        'cache_ready' => true, 'accounts_changed' => false, 'qualifications_changed' => false, 'services_changed' => false,
        'mymar_application_changed' => false, 'business_database_writes' => false, 'messages_sent' => false, 'post_requests' => false]);
    echo 'Correspondances actives : '.count($pairs).". Cache des occupations verifie avant ouverture du placement.\n";
} catch (Throwable $error) {
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'mymar.roster_activation_failed';
    if (! $published) {
        foreach (array_reverse($changed) as $name) {
            try {
                Activation::replace($name, hash('sha256', $contents[$name]), $files[$name]['content'], $files[$name]);
            } catch (Throwable) {
                $reason = 'mymar.roster_preparation_recovery_required';
            }
        }
    }
    fwrite(STDERR, 'STOP : '.$reason.'. '.($published ? 'Correspondances deja publiees : ne pas reduire le perimetre sans controle des actions en cours.' : 'Ancien perimetre de placement conserve.').PHP_EOL);
    exit(1);
}
