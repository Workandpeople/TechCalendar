<?php

use App\Services\Coffrac\TechCalendarPlanningSnapshot;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

require_once __DIR__.'/../Fixtures/TechCalendarPlanningFixture.php';

uses(TestCase::class);

beforeEach(function () {
    $this->planningDirectory = techCalendarPlanningFixture();
    $this->planningSha = hash_file('sha256', $this->planningDirectory.'/references.json.gz');
});

afterEach(function () {
    File::deleteDirectory($this->planningDirectory);
});

it('reads a private planning-only snapshot with an externally pinned hash and exact typed relations', function () {
    $snapshot = new TechCalendarPlanningSnapshot($this->planningDirectory, $this->planningSha);
    expect($snapshot->fingerprint)->toBe($this->planningSha)
        ->and($snapshot->rows('users')[0]['id'])->toBe(901)
        ->and($snapshot->rows('department_user'))->toHaveCount(2);
    expect(fn () => $snapshot->rows('mail_templates'))->toThrow(RuntimeException::class, 'non autorisee');
    expect(fn () => new TechCalendarPlanningSnapshot($this->planningDirectory, str_repeat('a', 64)))
        ->toThrow(RuntimeException::class, 'Empreinte planning');
});

it('rejects unsafe sources or malformed schemas even when the file was resealed', function (string $case) {
    $path = $this->planningDirectory.'/references.json.gz';
    $data = json_decode(gzdecode(file_get_contents($path)), true);
    match ($case) {
        'demo' => $data['app_environment'] = 'demo',
        'demo database' => $data['source_database'] = 'techcalendar_v2_demo_simulation',
        'wrong project' => $data['source_project'] = '/var/www/TechCalendar-v2',
        'old format' => $data['format'] = 'techcalendar.reference-snapshot',
        'incomplete' => $data['complete'] = false,
        'timestamp' => $data['exported_at'] = 'not a date',
        'count' => $data['tables']['users']['count'] = 5,
        'missing table' => $data['tables'] = array_diff_key($data['tables'], ['departments' => true]),
        'unapproved table' => $data['tables']['credentials'] = [],
        'secret column' => $data['tables']['users']['rows'][0]['password'] = 'NEVER-IMPORT',
        'wrong profile' => $data['tables']['users']['rows'][0]['role'] = 0,
        'duplicate department' => $data['tables']['departments']['rows'][1]['code'] = '01',
        'invalid id' => $data['tables']['users']['rows'][0]['id'] = '9e2',
        'orphan user' => $data['tables']['department_user']['rows'][0]['user_id'] = 999,
        'orphan department' => $data['tables']['department_user']['rows'][0]['department_code'] = '99',
        'duplicate relation' => $data['tables']['department_user']['rows'][1]['department_code'] = '75',
    };
    writeTechCalendarPlanningFixture($this->planningDirectory, $data);
    expect(fn () => new TechCalendarPlanningSnapshot($this->planningDirectory, hash_file('sha256', $path)))
        ->toThrow(RuntimeException::class);
})->with(['demo', 'demo database', 'wrong project', 'old format', 'incomplete', 'timestamp', 'count', 'missing table',
    'unapproved table', 'secret column', 'wrong profile', 'duplicate department', 'invalid id', 'orphan user', 'orphan department', 'duplicate relation']);

it('rejects public or symbolic files before reading their contents', function (string $case) {
    $path = $this->planningDirectory.'/references.json.gz';
    if ($case === 'public directory') {
        chmod($this->planningDirectory, 0750);
    } elseif ($case === 'public file') {
        chmod($path, 0644);
    } else {
        rename($path, $this->planningDirectory.'/target');
        symlink('target', $path);
    }
    expect(fn () => new TechCalendarPlanningSnapshot($this->planningDirectory, $this->planningSha))
        ->toThrow(RuntimeException::class);
})->with(['public directory', 'public file', 'symbolic file']);

it('reads the complete settings contract while keeping nontechnicians out of planning identities', function () {
    File::deleteDirectory($this->planningDirectory);
    $this->planningDirectory = techCalendarPlanningFixture([], true);
    $snapshot = new TechCalendarPlanningSnapshot($this->planningDirectory, hash_file('sha256', $this->planningDirectory.'/references.json.gz'));
    expect($snapshot->hasFullSettings())->toBeTrue()
        ->and($snapshot->rows('users'))->toHaveCount(2)
        ->and($snapshot->technicians())->toHaveCount(1)
        ->and($snapshot->rows('external_service_aliases'))->toHaveCount(1)
        ->and($snapshot->rows('technician_absences'))->toBe([]);
});

it('rejects unapproved settings columns and orphaned supplementary relations', function (string $case) {
    File::deleteDirectory($this->planningDirectory);
    $this->planningDirectory = techCalendarPlanningFixture([], true);
    $path = $this->planningDirectory.'/references.json.gz';
    $data = json_decode(gzdecode(file_get_contents($path)), true);
    match ($case) {
        'SMTP secret' => $data['tables']['mail_senders']['columns'][] = 'mail_password',
        'credential' => $data['tables']['users']['rows'][0]['password'] = 'NEVER-IMPORT',
        'alias' => $data['tables']['external_service_aliases']['rows'][0]['service_id'] = 999,
        'grant user' => $data['tables']['service_user']['rows'][0]['user_id'] = 999,
        'grant service' => $data['tables']['service_user']['rows'][0]['service_id'] = 999,
    };
    writeTechCalendarPlanningFixture($this->planningDirectory, $data);
    expect(fn () => new TechCalendarPlanningSnapshot($this->planningDirectory, hash_file('sha256', $path)))
        ->toThrow(RuntimeException::class);
})->with(['SMTP secret', 'credential', 'alias', 'grant user', 'grant service']);
