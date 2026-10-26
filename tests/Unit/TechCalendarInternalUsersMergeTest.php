<?php

use Cofrac2\Deploy\TechCalendarInternalUsersMerge;
use Cofrac2\Deploy\TechCalendarInternalUsersPlan;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../tools/deploy/lib/TechCalendarInternalUsersPlan.php';
require_once __DIR__.'/../../tools/deploy/lib/TechCalendarInternalUsersMerge.php';
require_once __DIR__.'/../Fixtures/TechCalendarInternalUsersFixture.php';

function internalUsersMergeDatabase(array $source, array $target): PDO
{
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($target as $table => $rows) {
        $columns = array_keys($rows[0] ?? array_fill_keys($source['tables'][$table]['columns'], null));
        if ($table === 'users') {
            $columns = array_values(array_unique([...$columns, 'must_change_password', 'notification_mail_enabled',
                'notification_push_enabled', 'portal_enabled', 'external_client_id']));
        } elseif (in_array($table, ['service_user', 'department_user'], true)) {
            $columns = array_values(array_unique([...$columns, 'created_at', 'updated_at']));
        }
        $definitions = [];
        foreach ($columns as $column) {
            $definitions[] = match (true) {
                $column === 'id' => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
                $column === 'email' => 'email TEXT UNIQUE COLLATE NOCASE',
                in_array($column, ['role', 'admin', 'can_manage_billing', 'must_change_password', 'notification_mail_enabled',
                    'notification_push_enabled', 'portal_enabled', 'external_client_id', 'user_id', 'service_id'], true) => '"'.$column.'" INTEGER',
                default => '"'.$column.'" TEXT',
            };
        }
        if (in_array($table, ['service_user', 'department_user'], true)) {
            $definitions[] = 'FOREIGN KEY (user_id) REFERENCES users(id)';
        }
        if ($table === 'service_user') {
            $definitions[] = 'FOREIGN KEY (service_id) REFERENCES services(id)';
            $definitions[] = 'UNIQUE(user_id,service_id)';
        }
        if ($table === 'department_user') {
            $definitions[] = 'UNIQUE(user_id,department_code)';
        }
        $pdo->exec('CREATE TABLE '.$table.' ('.implode(',', $definitions).')');
        foreach ($rows as $row) {
            $query = $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`', array_keys($row)).'`) VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
            $query->execute(array_values($row));
        }
    }
    foreach (['sessions', 'mobile_access_tokens', 'mobile_push_tokens'] as $table) {
        $pdo->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, user_id INTEGER, payload TEXT)');
        $pdo->exec("INSERT INTO $table VALUES (1,77,'target_session'),(2,88,'admin_session'),(3,99,'other_session')");
    }
    foreach (['dossiers', 'appointments', 'dossier_documents', 'jobs'] as $table) {
        $pdo->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, payload TEXT)');
        $pdo->exec("INSERT INTO $table VALUES (1,'DO_NOT_TOUCH_BUSINESS_DATA')");
    }
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/internal-users-merge-'.bin2hex(random_bytes(6));
    mkdir($this->directory, 0700);
    [$this->source, $this->target] = internalUsersPlanFixture();
    $this->pdo = internalUsersMergeDatabase($this->source, $this->target);
    $this->snapshot = function (): TechCalendarInternalUsersPlan {
        $file = $this->directory.'/references.json.gz';
        file_put_contents($file, gzencode(json_encode($this->source, JSON_THROW_ON_ERROR)));
        chmod($file, 0600);

        return new TechCalendarInternalUsersPlan($file, hash_file('sha256', $file));
    };
    $this->approve = function (): array {
        $snapshot = ($this->snapshot)();
        $this->pdo->beginTransaction();
        try {
            $approved = $snapshot->inspect($this->pdo);
        } finally {
            $this->pdo->rollBack();
        }

        return [$snapshot, $approved];
    };
    $this->state = function (): array {
        $state = [];
        foreach (['users', 'service_user', 'department_user', 'sessions', 'mobile_access_tokens', 'mobile_push_tokens',
            'dossiers', 'appointments', 'dossier_documents', 'jobs'] as $table) {
            $state[$table] = $this->pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }

        return $state;
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('copies source profiles and assignments without changing existing passwords, permissions, IDs, admin or unrelated records', function () {
    [$snapshot, $approved] = ($this->approve)();
    $before = ($this->state)();
    $report = (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null);
    $after = ($this->state)();
    $user = $after['users'][0];
    expect($user['id'])->toBe(77)->and($user['first_name'])->toBe('Tech')->and($user['role'])->toBe(2)
        ->and($user['admin'])->toBe(0)->and($user['can_manage_billing'])->toBe(1)
        ->and($user['deleted_at'])->toBeNull()->and($user['password'])->toBe($before['users'][0]['password'])
        ->and($user['remember_token'])->toBeNull()
        ->and($after['users'][1])->toBe($before['users'][1])
        ->and($after['users'][2])->toBe($before['users'][2])
        ->and(array_column($after['department_user'], 'department_code'))->toBe(['01'])
        ->and(array_column($after['service_user'], 'service_id'))->toBe([500, 501])
        ->and($report['existing_password_hashes_preserved'])->toBe(3)
        ->and($report['revoked'])->toBe(['sessions' => 1, 'mobile_access_tokens' => 1, 'mobile_push_tokens' => 1])
        ->and(array_column($after['sessions'], 'user_id'))->toBe([88, 99]);
    foreach (['dossiers', 'appointments', 'dossier_documents', 'jobs'] as $table) {
        expect($after[$table])->toBe($before[$table]);
    }
});

it('creates new internal accounts with distinct unshared passwords, no invitations and source inactive states', function () {
    $this->source['tables']['users']['rows'][0]['email'] = 'new-tech@example.fr';
    $new = array_replace($this->source['tables']['users']['rows'][0], ['id' => 15, 'email' => 'inactive@example.fr', 'deleted_at' => '2026-10-25 17:00:00']);
    $this->source['tables']['users']['rows'][] = $new;
    $this->source['tables']['users']['count']++;
    [$snapshot, $approved] = ($this->approve)();
    $report = (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null);
    $users = $this->pdo->query('SELECT * FROM users WHERE id>99 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    expect($report['created'])->toBe(2)->and($users)->toHaveCount(2)
        ->and($users[0]['password'])->not->toBe($users[1]['password'])
        ->and(password_get_info($users[0]['password'])['algoName'])->toBe('bcrypt')
        ->and($users[0]['admin'])->toBe(0)->and($users[0]['must_change_password'])->toBe(1)
        ->and($users[0]['notification_mail_enabled'])->toBe(0)->and($users[0]['notification_push_enabled'])->toBe(0)
        ->and($users[0]['portal_enabled'])->toBe(0)->and($users[1]['deleted_at'])->not->toBeNull()
        ->and($report['outbound_deliveries'])->toBe(0);
    expect(json_encode($report))->not->toContain($users[0]['password']);
});

it('deactivates existing users as requested and preserves their original inactive date on subsequent merges', function () {
    $this->source['tables']['users']['rows'][0]['deleted_at'] = '2026-10-25 18:00:00';
    $this->pdo->exec('UPDATE users SET deleted_at=NULL,role=2 WHERE id=77');
    [$snapshot, $approved] = ($this->approve)();
    $report = (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null);
    expect($report['deactivated'])->toBe(1)
        ->and($this->pdo->query('SELECT deleted_at FROM users WHERE id=77')->fetchColumn())->toBe('2026-10-25 18:00:00');
    $this->source['tables']['users']['rows'][0]['deleted_at'] = '2026-10-25 19:00:00';
    [$snapshot, $approved] = ($this->approve)();
    (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null);
    expect($this->pdo->query('SELECT deleted_at FROM users WHERE id=77')->fetchColumn())->toBe('2026-10-25 18:00:00');
});

it('rolls back all user, pivot and session changes when final input verification fails', function () {
    [$snapshot, $approved] = ($this->approve)();
    $before = ($this->state)();
    expect(fn () => (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS,
        fn () => throw new RuntimeException('Changed input')))->toThrow(RuntimeException::class, 'Changed input');
    expect(($this->state)())->toBe($before)->and($this->pdo->inTransaction())->toBeFalse();
});

it('rolls back prior profile updates on a database error during relationship synchronization', function () {
    [$snapshot, $approved] = ($this->approve)();
    $this->pdo->exec("CREATE TRIGGER fixture_failure BEFORE INSERT ON service_user BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
    $before = ($this->state)();
    expect(fn () => (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null))
        ->toThrow(PDOException::class);
    expect(($this->state)())->toBe($before)->and($this->pdo->inTransaction())->toBeFalse();
});

it('rejects stale or altered plans and unapproved decisions before changing any account', function (string $case) {
    [$snapshot, $approved] = ($this->approve)();
    $decisions = TechCalendarInternalUsersMerge::DECISIONS;
    match ($case) {
        'concurrent profile' => $this->pdo->exec("UPDATE users SET first_name='CONCURRENT' WHERE id=77"),
        'concurrent password' => $this->pdo->exec("UPDATE users SET password='NEW_CURRENT_HASH' WHERE id=77"),
        'changed plan' => $approved['users'][0]['profile']['role'] = 0,
        'changed decision' => $decisions['existing_passwords'] = 'replace',
    };
    $before = ($this->state)();
    expect(fn () => (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, $decisions, fn () => null))->toThrow(RuntimeException::class);
    expect(($this->state)())->toBe($before);
})->with(['concurrent profile', 'concurrent password', 'changed plan', 'changed decision']);

it('rejects unresolved active profile issues and does not widen unknown habilitations', function () {
    $this->source['tables']['users']['rows'][0]['day_start_time'] = '23:00:00';
    [$snapshot, $approved] = ($this->approve)();
    $before = ($this->state)();
    expect(fn () => (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null))
        ->toThrow(RuntimeException::class, 'Donnees de profil non resolues');
    expect(($this->state)())->toBe($before);
});

it('keeps unmapped legacy grants in the audit and clears only an inactive invalid primary department without guessing intervention sectors', function () {
    $this->source['tables']['users']['rows'][0]['deleted_at'] = '2026-10-25 17:00:00';
    $this->source['tables']['users']['rows'][0]['department_code'] = '20';
    $this->source['tables']['services']['rows'][] = array_replace($this->source['tables']['services']['rows'][0], ['id' => 30, 'name' => 'Legacy unknown']);
    $this->source['tables']['services']['count']++;
    $this->source['tables']['service_user']['rows'][] = array_replace($this->source['tables']['service_user']['rows'][0], ['id' => 3, 'service_id' => 30]);
    $this->source['tables']['service_user']['count']++;
    [$snapshot, $approved] = ($this->approve)();
    $report = (new TechCalendarInternalUsersMerge)->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null);
    expect($report['warnings'])->toHaveCount(2)
        ->and($this->pdo->query('SELECT department_code FROM users WHERE id=77')->fetchColumn())->toBeNull()
        ->and($this->pdo->query('SELECT department_code FROM department_user WHERE user_id=77')->fetchAll(PDO::FETCH_COLUMN))->toBe(['01'])
        ->and($this->pdo->query('SELECT service_id FROM service_user WHERE user_id=77 ORDER BY service_id')->fetchAll(PDO::FETCH_COLUMN))->toBe([500, 501]);
});

it('is idempotent after a fresh approval and refuses reusing the stale premerge fingerprint', function () {
    [$snapshot, $approved] = ($this->approve)();
    $merge = new TechCalendarInternalUsersMerge;
    $merge->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null);
    $state = ($this->state)();
    expect(fn () => $merge->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null))->toThrow(RuntimeException::class);
    [$snapshot, $approved] = ($this->approve)();
    $report = $merge->apply($this->pdo, $snapshot, $approved, TechCalendarInternalUsersMerge::DECISIONS, fn () => null);
    expect($report['created'])->toBe(0)->and($report['updated'])->toBe(0)
        ->and($report['departments_added'])->toBe(0)->and($report['habilitations_added'])->toBe(0)
        ->and(($this->state)())->toBe($state);
});

it('keeps production tooling outside application boot and requires a private valid backup before applying', function () {
    $php = file_get_contents(__DIR__.'/../../tools/deploy/merge-techcalendar-internal-users.php');
    $shell = file_get_contents(__DIR__.'/../../tools/deploy/merge-techcalendar-internal-users.sh');
    expect($php)->toContain('cofrac2production', 'cofrac2_app', 'hash_file(\'sha256\', $run.\'/database-before.sql.gz\')',
        'Sauvegarde V2 valide obligatoire', 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    expect($shell)->toContain('--single-transaction --skip-lock-tables --no-tablespaces', 'internal-users-merge-receipts', 'FUSIONNER');
    foreach ([$php, $shell] as $code) {
        foreach (['artisan ', 'systemctl ', 'debian.cnf', 'fiche_crm', 'bootstrap/app.php', 'DB::', 'TRUNCATE', 'migrate', 'db:seed'] as $forbidden) {
            expect($code)->not->toContain($forbidden);
        }
    }
    $process = new Process([PHP_BINARY, __DIR__.'/../../tools/deploy/merge-techcalendar-internal-users.php', '--apply']);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput())->toBe('');
});
