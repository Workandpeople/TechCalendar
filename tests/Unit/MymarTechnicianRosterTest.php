<?php

use Cofrac2\Deploy\Mymar\MymarTechnicianRoster as Roster;

require_once __DIR__.'/../../tools/deploy/mymar/roster/MymarTechnicianRoster.php';

function mymarRosterSource(int $id, string $name, ?string $email = null, array $changes = []): array
{
    return array_replace(['section' => 'source', 'id' => $id, 'name' => $name, 'email_key' => $email === null ? null : hash('sha256', $email), 'active' => 1, 'terrain' => 1], $changes);
}

function mymarRosterTarget(int $id, string $name, ?string $email = null, array $changes = []): array
{
    return array_replace(['section' => 'target', 'id' => $id, 'name' => $name, 'email_key' => $email === null ? null : hash('sha256', $email),
        'role' => 2, 'active' => 1, 'admin' => 0, 'external' => 0, 'gps_present' => 1], $changes);
}

function mymarRosterRows(array $sources = [], array $targets = []): array
{
    $rows = [['section' => 'server', 'version' => '8.0.46', 'port' => 3306, 'read_only' => 1],
        ['section' => 'counts', 'sources' => count($sources), 'targets' => count($targets)],
        ['section' => 'clock', 'utc' => '2026-11-02T14:00:00Z', 'read_only' => 1], ['section' => 'complete', 'complete' => true]];
    foreach ((new ReflectionClass(Roster::class))->getConstant('REQUIRED') as $key => $fields) {
        $rows[] = ['section' => 'table', 'key' => $key, 'engine' => 'InnoDB'];
        foreach ($fields as $field) {
            $rows[] = ['section' => 'column', 'key' => $key, 'name' => $field];
        }
    }

    return [...$rows, ...$sources, ...$targets, ['section' => 'service', 'id' => 10, 'name' => 'VISITE APRES TRAVAUX', 'duration' => 60],
        ['section' => 'grant', 'user_id' => 101, 'service_id' => 10], ['section' => 'department', 'user_id' => 101, 'code' => '59']];
}

function mymarRosterPlan(array $sources, array $targets, array $existing = [], array $approved = []): array
{
    return Roster::plan(Roster::snapshot(mymarRosterRows($sources, $targets), new DateTimeImmutable('2026-11-02T14:00:01Z')), $existing, $approved);
}

it('plans unique full names regardless of order accents and department suffixes without exporting email fingerprints', function () {
    $result = mymarRosterPlan([mymarRosterSource(1, 'MARTIN Élodie', 'one'), mymarRosterSource(2, 'Camille Petit', 'two')],
        [mymarRosterTarget(101, 'Élodie MARTIN (59,62)', 'one'), mymarRosterTarget(102, 'Petit CAMILLE', 'new-two')]);
    expect(array_column($result['links'], 'cofrac_user_id'))->toBe([101, 102])
        ->and(array_column($result['links'], 'basis'))->toBe(['unique_email', 'unique_full_name'])
        ->and($result['links'][0])->toMatchArray(['mar_qualified' => true, 'departments' => ['59'], 'gps_present' => true])
        ->and($result['summary']['mapped_but_not_mar_qualified'])->toBe(1)
        ->and($result)->toMatchArray(['configuration_writes' => false, 'account_writes' => false, 'services_changed' => false, 'can_affect_planning' => false])
        ->and(json_encode($result))->not->toContain(hash('sha256', 'one'), 'email_key');
});

it('preserves existing mappings and explicitly approved identities without creating or reactivating accounts', function () {
    $sources = [mymarRosterSource(1, 'Alex'), mymarRosterSource(2, 'Moreau Theo')];
    $targets = [mymarRosterTarget(101, 'Alex MARTIN'), mymarRosterTarget(102, 'Theo MOREAU')];
    $result = mymarRosterPlan($sources, $targets, [['source_technician_id' => 1, 'cofrac_user_id' => 101]],
        [['source_technician_id' => 2, 'source_name' => 'Theo MOREAU', 'cofrac_user_id' => 102, 'cofrac_name' => 'Theo MOREAU']]);
    expect(array_column($result['links'], 'basis'))->toBe(['existing_validated_mapping', 'operator_confirmed_identity']);
    $targets[0]['active'] = 0;
    $result = mymarRosterPlan($sources, $targets, [['source_technician_id' => 1, 'cofrac_user_id' => 101]]);
    expect($result['summary']['existing_mapping_issues'])->toBe(1)->and(array_column($result['links'], 'cofrac_user_id'))->not->toContain(101);
});

it('does not guess on shared emails conflicting emails partial names or homonyms', function (array $sources, array $targets) {
    $result = mymarRosterPlan($sources, $targets);
    expect($result['links'])->toBe([])->and($result['summary']['source_identities_to_review'])->toBe(count($sources));
})->with([
    [[mymarRosterSource(1, 'Alex Martin')], [mymarRosterTarget(101, 'Alex Martin'), mymarRosterTarget(102, 'Martin Alex')]],
    [[mymarRosterSource(1, 'Alex Martin', 'shared')], [mymarRosterTarget(101, 'Alex Martin', 'shared'), mymarRosterTarget(102, 'Different Person', 'shared')]],
    [[mymarRosterSource(1, 'Alex Martin', 'wrong')], [mymarRosterTarget(101, 'Alex Martin', 'correct'), mymarRosterTarget(102, 'Different Person', 'wrong')]],
    [[mymarRosterSource(1, 'Alex')], [mymarRosterTarget(101, 'Alex Martin')]],
    [[mymarRosterSource(1, 'Alex Martin', 'shared'), mymarRosterSource(2, 'Other Person', 'shared')], [mymarRosterTarget(101, 'Alex Martin', 'shared')]],
]);

it('links unique emails even when MYMAR only stores a first name or surname', function () {
    $result = mymarRosterPlan([mymarRosterSource(1, 'Serigne (59)', 'one'), mymarRosterSource(2, 'DHERY', 'two')],
        [mymarRosterTarget(101, 'Serigne MBOW', 'one'), mymarRosterTarget(102, 'David DHERY', 'two')]);
    expect(array_column($result['links'], 'cofrac_user_id'))->toBe([101, 102])
        ->and(array_column($result['links'], 'basis'))->toBe(['unique_email', 'unique_email']);
});

it('normalizes department suffixes but never guesses a partial identity without a unique email', function () {
    $result = mymarRosterPlan([mymarRosterSource(1, 'JULIEN CULIÉ 81'), mymarRosterSource(2, 'Said 59')],
        [mymarRosterTarget(101, 'Julien CULIÉ'), mymarRosterTarget(102, 'Hachemi SAID')]);
    expect(array_column($result['links'], 'cofrac_user_id'))->toBe([101])
        ->and(array_column($result['review'], 'source_technician_id'))->toBe([2]);
});

it('can distinguish homonyms only when both emails are unique and the full identity matches', function () {
    $result = mymarRosterPlan([mymarRosterSource(1, 'Alex Martin', 'one'), mymarRosterSource(2, 'Martin Alex', 'two')],
        [mymarRosterTarget(101, 'Alex Martin', 'one'), mymarRosterTarget(102, 'Alex Martin', 'two')]);
    expect(array_column($result['links'], 'cofrac_user_id'))->toBe([101, 102]);
});

it('handles MySQL JSON boolean expressions without accepting truthy strings as profile flags', function () {
    $result = mymarRosterPlan([mymarRosterSource(1, 'Alex Martin', 'one', ['active' => true, 'terrain' => true])],
        [mymarRosterTarget(101, 'Alex Martin', 'one', ['active' => true, 'admin' => false, 'external' => false, 'gps_present' => true])]);
    expect(array_column($result['links'], 'cofrac_user_id'))->toBe([101]);
    expect(fn () => mymarRosterPlan([mymarRosterSource(1, 'Alex Martin', 'one', ['active' => 'false'])],
        [mymarRosterTarget(101, 'Alex Martin', 'one')]))->toThrow(RuntimeException::class);
});

it('never includes disabled nontechnician administrative or external accounts', function (array $change) {
    $result = mymarRosterPlan([mymarRosterSource(1, 'Alex Martin', 'one')], [mymarRosterTarget(101, 'Alex Martin', 'one', $change)]);
    expect($result['links'])->toBe([])->and($result['summary']['active_cofrac_technicians'])->toBe(0);
})->with([[['active' => 0]], [['role' => 1]], [['role' => 3]], [['admin' => 1]], [['external' => 1]]]);

it('never remaps an occupied target or an approved identity that changed', function () {
    $sources = [mymarRosterSource(1, 'Other Name'), mymarRosterSource(2, 'Alex Martin')];
    $targets = [mymarRosterTarget(101, 'Alex Martin')];
    $existing = [['source_technician_id' => 1, 'cofrac_user_id' => 101]];
    $result = mymarRosterPlan($sources, $targets, $existing);
    expect($result['summary']['proposed_links'])->toBe(1)->and($result['review'][0]['reason'])->toBe('target_already_linked');
    expect(fn () => mymarRosterPlan($sources, $targets, $existing,
        [['source_technician_id' => 2, 'source_name' => 'Alex Martin', 'cofrac_user_id' => 101, 'cofrac_name' => 'Alex Martin']]))->toThrow(RuntimeException::class);
});

it('requires a complete fresh readonly snapshot with expected schemas and no duplicates', function (Closure $change) {
    $rows = mymarRosterRows([mymarRosterSource(1, 'Alex Martin')], [mymarRosterTarget(101, 'Alex Martin')]);
    expect(fn () => Roster::snapshot($change($rows), new DateTimeImmutable('2026-11-02T14:00:01Z')))->toThrow(RuntimeException::class);
})->with([
    fn ($rows) => array_filter($rows, fn ($r) => $r['section'] !== 'complete'),
    fn ($rows) => [...$rows, $rows[0]],
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'server' ? [...$r, 'read_only' => 0] : $r, $rows),
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'table' ? [...$r, 'engine' => 'MyISAM'] : $r, $rows),
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'counts' ? [...$r, 'targets' => 99] : $r, $rows),
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'clock' ? [...$r, 'utc' => '2026-10-30T14:00:00Z'] : $r, $rows),
]);

it('only generates bounded readonly SQL with salted email fingerprints and no credentials or business dossiers', function () {
    $sql = Roster::sql(str_repeat('a', 64));
    expect($sql)->toContain('START TRANSACTION READ ONLY;', 'max_execution_time=1500', 'LIMIT 1001', 'SHA2(CONCAT(')
        ->not->toMatch('/(?:^|;)\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|GRANT|PURGE)\b/im')
        ->not->toContain('password', 'dossiers', 'remember_token');
    expect(fn () => Roster::sql("'; DROP DATABASE MYMAR;"))->toThrow(RuntimeException::class);
});
