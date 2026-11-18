<?php

use Cofrac2\Deploy\Mymar\MymarRosterActivation as Activation;
use Cofrac2\Deploy\Mymar\MymarRosterRetirement as Retirement;

require_once __DIR__.'/../../tools/deploy/mymar/roster/MymarRosterActivation.php';
require_once __DIR__.'/../../tools/deploy/mymar/roster/MymarRosterRetirement.php';

function retirementConfigs(): array
{
    $pairs = [['source_technician_id' => 5, 'cofrac_user_id' => 101], ['source_technician_id' => 6, 'cofrac_user_id' => 102]];
    $gateway = ['version' => 1, 'read_enabled' => true, 'writes_enabled' => false, 'technical_author_id' => 900,
        'technician_ids' => [5, 6], 'database_user' => 'cofrac_mymar_br_12345678',
        'database_password' => 'Mb3!'.str_repeat('a', 64), 'token' => str_repeat('0123456789abcdef', 4)];

    return ['occupations' => ['technician_ids' => [5, 6]],
        'reader' => ['format' => 'cofrac.mymar-booking-reader', ...$gateway],
        'writer' => ['format' => 'cofrac.mymar-actions-gateway', ...$gateway, 'writes_enabled' => true, 'database_user' => 'cofrac_mymar_rw_12345678'],
        'pilot' => ['format' => 'cofrac.mymar-observation-pilot', 'version' => 1, 'sync_enabled' => true, 'display_enabled' => true,
            'blocking_enabled' => false, 'generation' => str_repeat('a', 32), 'bindings' => $pairs],
        'client' => ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => true,
            'port' => 19848, 'generation' => '12345678-1234-4234-8234-123456789abc', 'bindings' => $pairs]];
}

function retirementRequest(): array
{
    return ['source_technician_id' => 6, 'cofrac_user_id' => 102, 'expected_before' => 2];
}

function retirementTargetRows(): array
{
    return [['section' => 'user', 'id' => 101, 'role' => 2, 'admin' => 0, 'external' => 0, 'deleted' => 0],
        ['section' => 'user', 'id' => 102, 'role' => 2, 'admin' => 0, 'external' => 0, 'deleted' => 1],
        ['section' => 'inflight', 'count' => 0], ['section' => 'complete', 'read_only' => 1]];
}

it('only revokes the exact pair in all five scopes and preserves credentials generations and gates', function () {
    $before = retirementConfigs();
    $plan = Retirement::prepare($before, retirementRequest());
    $after = $plan['configs'];
    expect($plan['already_applied'])->toBeFalse()->and($plan['remaining'])->toBe([$before['client']['bindings'][0]]);
    foreach (['occupations', 'reader', 'writer'] as $name) {
        expect($after[$name]['technician_ids'])->toBe([5]);
        $after[$name]['technician_ids'] = $before[$name]['technician_ids'];
    }
    foreach (['pilot', 'client'] as $name) {
        expect($after[$name]['bindings'])->toBe([$before[$name]['bindings'][0]]);
        $after[$name]['bindings'] = $before[$name]['bindings'];
    }
    expect($after)->toBe($before)
        ->and(Retirement::prepare($plan['configs'], retirementRequest())['already_applied'])->toBeTrue();
    expect(fn () => Activation::prepare($before, $plan['remaining']))->toThrow(RuntimeException::class);
});

it('refuses changed identities invalid requests inconsistent gates and unexpected scope sizes', function (Closure $mutate) {
    [$configs, $request] = $mutate(retirementConfigs(), retirementRequest());
    expect(fn () => Retirement::prepare($configs, $request))->toThrow(RuntimeException::class);
})->with([
    fn ($c, $r) => [$c, [...$r, 'source_technician_id' => 5]],
    fn ($c, $r) => [$c, [...$r, 'cofrac_user_id' => 101]],
    fn ($c, $r) => [$c, [...$r, 'source_technician_id' => '6']],
    fn ($c, $r) => [$c, [...$r, 'expected_before' => 1]],
    fn ($c, $r) => [$c, [...$r, 'expected_before' => 3]],
    function ($c, $r) {
        $c['reader']['technician_ids'] = [5];

        return [$c, $r];
    },
    function ($c, $r) {
        $c['writer']['writes_enabled'] = false;

        return [$c, $r];
    },
]);

it('requires a complete read-only audit and a deleted target while every remaining technician is eligible', function () {
    Retirement::validateTargets(retirementTargetRows(), [['source_technician_id' => 5, 'cofrac_user_id' => 101]], 102);
    $sql = Retirement::sql([['source_technician_id' => 5, 'cofrac_user_id' => 101]], 102);
    expect($sql)->toContain('START TRANSACTION READ ONLY', 'cofrac2production.users', 'IN (101,102)', "'dispatching'", "'uncertain'", 'ROLLBACK;')
        ->not->toMatch('/\b(?:UPDATE|INSERT|DELETE|ALTER|GRANT|MYMAR\.)\b/i');
});

it('never retires a restored account hides another invalid account or interrupts unsettled MAR commands', function (Closure $mutate) {
    $rows = $mutate(retirementTargetRows());
    expect(fn () => Retirement::validateTargets($rows, [['source_technician_id' => 5, 'cofrac_user_id' => 101]], 102))
        ->toThrow(RuntimeException::class);
})->with([
    function ($r) {
        $r[1]['deleted'] = 0;

        return $r;
    },
    function ($r) {
        $r[1]['role'] = 0;

        return $r;
    },
    function ($r) {
        $r[0]['deleted'] = 1;

        return $r;
    },
    function ($r) {
        $r[0]['admin'] = 1;

        return $r;
    },
    function ($r) {
        $r[0]['external'] = 1;

        return $r;
    },
    function ($r) {
        $r[2]['count'] = 1;

        return $r;
    },
    function ($r) {
        $r[3]['read_only'] = 0;

        return $r;
    },
    fn ($r) => array_slice($r, 0, 3),
    fn ($r) => [$r[0], $r[2], $r[3]],
    fn ($r) => [...$r, $r[0]],
]);

it('never changes application code services accounts permissions or business rows', function () {
    $root = __DIR__.'/../../tools/deploy/mymar/roster/';
    $shell = file_get_contents($root.'retire-link.sh');
    $probe = file_get_contents($root.'retire-runtime-probe.php');
    expect($shell)->not->toMatch('/systemctl\s+(?:restart|reload|stop|disable|enable)\b|\bgit\s+(?:pull|reset)\b|\bartisan\s+(?:migrate|db:seed)\b/')
        ->and($probe)->not->toContain('->post(', '->put(', '->patch(', '->delete(', '::dispatch(', '->send(');
});
