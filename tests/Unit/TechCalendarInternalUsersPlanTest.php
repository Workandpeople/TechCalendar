<?php

use Cofrac2\Deploy\TechCalendarInternalUsersPlan;
use Illuminate\Filesystem\Filesystem;

require_once __DIR__.'/../../tools/deploy/lib/TechCalendarInternalUsersPlan.php';

require_once __DIR__.'/../Fixtures/TechCalendarInternalUsersFixture.php';

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/internal-users-plan-'.bin2hex(random_bytes(6));
    mkdir($this->directory, 0700);
    [$this->source, $this->target] = internalUsersPlanFixture();
    $this->snapshot = function (): TechCalendarInternalUsersPlan {
        $file = $this->directory.'/references.json.gz';
        file_put_contents($file, gzencode(json_encode($this->source, JSON_THROW_ON_ERROR)));
        chmod($file, 0600);

        return new TechCalendarInternalUsersPlan($file, hash_file('sha256', $file));
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('matches inactive users by normalized email, keeps target IDs and protects the sole super admin without exporting credentials', function () {
    $before = $this->target;
    $report = ($this->snapshot)()->build($this->target);
    $user = $report['users'][0];
    expect($user['target_user_id'])->toBe(77)
        ->and($user['profile']['role'])->toBe(2)
        ->and($user['source_admin_ignored'])->toBeTrue()
        ->and($user['profile'])->not->toHaveKeys(['admin', 'can_manage_billing', 'password', 'remember_token', 'email'])
        ->and($user['password_policy'])->toBe('preserve_cofrac2')
        ->and($user['departments'])->toBe(['01'])
        ->and($user['mapped_service_ids'])->toBe([500, 501])
        ->and($report['users'][1]['action'])->toBe('protected_super_admin')
        ->and($report['users'][1])->not->toHaveKey('profile')
        ->and($report['summary']['restore'])->toBe(1)
        ->and($report['summary']['target_only_untouched'])->toBe(1)
        ->and($report['writes_performed'])->toBeFalse()
        ->and($report['apply_supported'])->toBeFalse()
        ->and($this->target)->toBe($before);
    expect(json_encode($report))->not->toContain('DO_NOT_EXPORT_PASSWORD_HASH')
        ->and(json_encode($report))->not->toContain('DO_NOT_EXPORT_TOKEN');
});

it('rejects external account collisions and duplicate normalized target emails without proposing an overwrite', function (string $case) {
    if ($case === 'external role') {
        $this->target['users'][0]['role'] = 3;
    } elseif ($case === 'external organisation') {
        $this->target['users'][0]['external_client_id'] = 14;
    } else {
        $this->target['users'][] = array_replace($this->target['users'][0], ['id' => 100, 'email' => ' TECH@EXAMPLE.FR ']);
    }
    $entry = ($this->snapshot)()->build($this->target)['users'][0];
    expect($entry['action'])->toBe('review')
        ->and($entry['reason'])->toBe($case === 'duplicate' ? 'ambiguous_target_email' : 'external_account_collision')
        ->and($entry)->not->toHaveKey('profile');
})->with(['external role', 'external organisation', 'duplicate']);

it('marks new accounts for password initialization review and never generates a shared password', function () {
    array_shift($this->target['users']);
    $entry = ($this->snapshot)()->build($this->target)['users'][0];
    expect($entry['action'])->toBe('create')->and($entry['target_user_id'])->toBeNull()
        ->and($entry['password_policy'])->toBe('initialization_requires_review')
        ->and($entry)->not->toHaveKey('password');
});

it('distinguishes source deactivation from restoration and does not alter target-only users', function () {
    $this->source['tables']['users']['rows'][0]['deleted_at'] = '2026-10-25 19:00:00';
    $this->target['users'][0]['deleted_at'] = null;
    $report = ($this->snapshot)()->build($this->target);
    expect($report['summary']['deactivate'])->toBe(1)->and($report['summary']['source_inactive'])->toBe(1)
        ->and($report['summary']['restore'])->toBe(0)->and($report['users'])->toHaveCount(2);
});

it('reviews test identities before creating or overwriting accounts', function () {
    $this->source['tables']['users']['rows'][0]['email'] = 'fixture@example.test';
    $entry = ($this->snapshot)()->build($this->target)['users'][0];
    expect($entry['action'])->toBe('review')->and($entry['reason'])->toBe('test_identity_requires_review');
});

it('prefers exact native labels to broad legacy aliases and never matches numerical IDs alone', function () {
    $this->source['tables']['services']['rows'][] = array_replace($this->source['tables']['services']['rows'][0], ['id' => 30, 'name' => 'SAV Controle']);
    $this->source['tables']['services']['count']++;
    $this->target['services'][] = array_replace($this->target['services'][0], ['id' => 10, 'name' => 'Different']);
    $report = ($this->snapshot)()->build($this->target);
    expect($report['users'][0]['mapped_service_ids'])->toBe([500])
        ->and($report['services']['by_source'][30])->toBe([501])
        ->and($report['services']['unmatched_target'][0]['target_service_id'])->toBe(10)
        ->and($report['services']['matches'][1]['evidence'])->toBe('exact_type_and_name');
});

it('keeps ambiguous, retired and missing service mappings in review rather than silently giving every habilitation', function (string $case) {
    if ($case === 'ambiguous') {
        $this->source['tables']['services']['rows'][] = array_replace($this->source['tables']['services']['rows'][0], ['id' => 11]);
        $this->source['tables']['services']['count']++;
        $this->target['services'] = [$this->target['services'][0]];
    } elseif ($case === 'retired') {
        foreach ($this->target['services'] as &$service) {
            $service['retired_at'] = '2026-10-14 00:00:00';
        }
        unset($service);
    } else {
        $this->target['services'] = [];
    }
    $report = ($this->snapshot)()->build($this->target);
    expect($report['users'][0]['mapped_service_ids'])->toBe([])
        ->and($report['users'][0]['unmapped_source_service_ids'])->toBe([10])
        ->and($report['summary']['users_with_mapping_or_profile_issues'])->toBe(1);
})->with(['ambiguous', 'retired', 'missing']);

it('reviews invalid departments, coordinates and working hours without inventing values', function () {
    $this->source['tables']['users']['rows'][0]['department_code'] = '999';
    $this->source['tables']['users']['rows'][0]['latitude'] = 'not numeric';
    $this->source['tables']['users']['rows'][0]['day_start_time'] = '21:00:00';
    $report = ($this->snapshot)()->build($this->target);
    expect($report['users'][0]['profile_issues'])->toContain('department_code', 'latitude', 'working_hours');
});

it('refuses unapproved source formats, tables, fields, duplicate emails and orphan relations', function (string $case) {
    match ($case) {
        'old CT' => $this->source['source_project'] = '/var/www/TechCalendar',
        'host' => $this->source['source_hostname'] = 'ns365601',
        'database' => $this->source['source_database'] = 'fiche_crm',
        'incomplete' => $this->source['complete'] = false,
        'table' => $this->source['tables']['dossiers'] = [],
        'password' => $this->source['tables']['users']['rows'][0]['password'] = 'secret',
        'email' => $this->source['tables']['users']['rows'][1]['email'] = 'tech@example.fr',
        'external' => $this->source['tables']['users']['rows'][0]['role'] = 3,
        'orphan user' => $this->source['tables']['service_user']['rows'][0]['user_id'] = 999,
        'orphan service' => $this->source['tables']['service_user']['rows'][0]['service_id'] = 999,
        'duplicate pivot' => $this->source['tables']['department_user']['rows'][] = array_replace($this->source['tables']['department_user']['rows'][0], ['id' => 2]),
    };
    if ($case === 'duplicate pivot') {
        $this->source['tables']['department_user']['count']++;
    }
    expect(fn () => ($this->snapshot)())->toThrow(RuntimeException::class);
})->with(['old CT', 'host', 'database', 'incomplete', 'table', 'password', 'email', 'external', 'orphan user', 'orphan service', 'duplicate pivot']);

it('rejects missing, wrong, public or symbolic source files', function (string $case) {
    ($this->snapshot)();
    $file = $this->directory.'/references.json.gz';
    $sha = hash_file('sha256', $file);
    match ($case) {
        'missing' => unlink($file),
        'wrong hash' => $sha = str_repeat('0', 64),
        'public' => chmod($file, 0644),
        'symbolic' => (function () use ($file) {
            rename($file, $file.'.real');
            symlink($file.'.real', $file);
        })(),
    };
    clearstatcache();
    expect(fn () => new TechCalendarInternalUsersPlan($file, $sha))->toThrow(RuntimeException::class);
})->with(['missing', 'wrong hash', 'public', 'symbolic']);

it('requires the existing single active internal seeded super admin', function (string $case) {
    match ($case) {
        'two admins' => $this->target['users'][0]['admin'] = 1,
        'inactive' => $this->target['users'][1]['deleted_at'] = '2026-10-25 00:00:00',
        'external' => $this->target['users'][1]['external_client_id'] = 12,
        'other email' => $this->target['users'][1]['email'] = 'other-admin@example.fr',
    };
    expect(fn () => ($this->snapshot)()->build($this->target))->toThrow(RuntimeException::class);
})->with(['two admins', 'inactive', 'external', 'other email']);

it('can inspect a database with SQL writes disabled and leaves its values unchanged', function () {
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($this->target as $table => $rows) {
        $columns = array_keys($rows[0] ?? ['id' => 1]);
        $pdo->exec('CREATE TABLE '.$table.' ('.implode(',', array_map(fn ($c) => '"'.$c.'" TEXT', $columns)).')');
        $insert = $pdo->prepare('INSERT INTO '.$table.' VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
        foreach ($rows as $row) {
            $insert->execute(array_values($row));
        }
    }
    $changesBefore = $pdo->query('SELECT total_changes()')->fetchColumn();
    $pdo->exec('PRAGMA query_only = ON');
    $pdo->beginTransaction();
    try {
        $report = ($this->snapshot)()->inspect($pdo);
        expect($report['summary']['matched'])->toBe(2)
            ->and($pdo->query('SELECT total_changes()')->fetchColumn())->toBe($changesBefore);
    } finally {
        $pdo->rollBack();
    }
});

it('keeps deployment tooling restricted to planning, the limited V2 SQL user and read-only transactions', function () {
    $php = file_get_contents(__DIR__.'/../../tools/deploy/plan-techcalendar-internal-users.php');
    $shell = file_get_contents(__DIR__.'/../../tools/deploy/plan-techcalendar-internal-users.sh');
    expect($php)->toContain('cofrac2production', 'cofrac2_app', 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', '$pdo->rollBack();');
    foreach ([$php, $shell] as $code) {
        foreach (['artisan ', 'bootstrap/app.php', 'systemctl ', 'debian.cnf', '--apply', 'INSERT INTO', 'UPDATE users', 'DELETE FROM'] as $forbidden) {
            expect($code)->not->toContain($forbidden);
        }
    }
});
