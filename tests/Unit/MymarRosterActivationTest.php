<?php

use Cofrac2\Deploy\Mymar\MymarRosterActivation as Activation;

require_once __DIR__.'/../../tools/deploy/mymar/roster/MymarRosterActivation.php';

function mymarRosterConfigurations(): array
{
    $bindings = [['source_technician_id' => 1, 'cofrac_user_id' => 101]];
    $gateway = ['version' => 1, 'read_enabled' => true, 'writes_enabled' => false, 'technical_author_id' => 900,
        'technician_ids' => [1], 'database_user' => 'cofrac_mymar_br_12345678', 'database_password' => 'Mb3!'.str_repeat('a', 64),
        'token' => str_repeat('0123456789abcdef', 4)];

    return ['occupations' => ['technician_ids' => [1]],
        'reader' => ['format' => 'cofrac.mymar-booking-reader', ...$gateway],
        'writer' => ['format' => 'cofrac.mymar-actions-gateway', ...$gateway, 'writes_enabled' => true, 'database_user' => 'cofrac_mymar_rw_12345678'],
        'pilot' => ['format' => 'cofrac.mymar-observation-pilot', 'version' => 1, 'sync_enabled' => true, 'display_enabled' => true,
            'blocking_enabled' => false, 'generation' => str_repeat('a', 32), 'bindings' => $bindings],
        'client' => ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => true,
            'port' => 19848, 'generation' => '12345678-1234-4234-8234-123456789abc', 'bindings' => $bindings]];
}

it('expands all five scopes without altering credentials generations SQL accounts or feature gates', function () {
    $before = mymarRosterConfigurations();
    $new = [['source_technician_id' => 2, 'cofrac_user_id' => 102], ...$before['client']['bindings']];
    $after = Activation::prepare($before, $new);
    $sorted = array_reverse($new);
    foreach (['occupations', 'reader', 'writer'] as $name) {
        expect($after[$name]['technician_ids'])->toBe([1, 2]);
        $after[$name]['technician_ids'] = $before[$name]['technician_ids'];
    }
    foreach (['pilot', 'client'] as $name) {
        expect($after[$name]['bindings'])->toBe($sorted);
        $after[$name]['bindings'] = $before[$name]['bindings'];
    }
    expect($after)->toBe($before)->and(Activation::prepare($before, $before['client']['bindings']))->toBe($before);
});

it('never enables previously disabled placement gates', function () {
    $configs = mymarRosterConfigurations();
    $configs['writer']['writes_enabled'] = $configs['client']['writes_enabled'] = false;
    $after = Activation::prepare($configs, [...$configs['client']['bindings'], ['source_technician_id' => 2, 'cofrac_user_id' => 102]]);
    expect($after['client']['writes_enabled'])->toBeFalse()->and($after['writer']['writes_enabled'])->toBeFalse();
});

it('refuses remapping removal duplicates wrong types or an inconsistent preexisting scope', function (Closure $mutate) {
    $configs = mymarRosterConfigurations();
    $pairs = [...$configs['client']['bindings'], ['source_technician_id' => 2, 'cofrac_user_id' => 102]];
    [$configs, $pairs] = $mutate($configs, $pairs);
    expect(fn () => Activation::prepare($configs, $pairs))->toThrow(RuntimeException::class);
})->with([
    fn ($c, $p) => [$c, [$p[1]]],
    fn ($c, $p) => [$c, [['source_technician_id' => 1, 'cofrac_user_id' => 102]]],
    fn ($c, $p) => [$c, [...$p, $p[1]]],
    fn ($c, $p) => [$c, [...$p, ['source_technician_id' => 3, 'cofrac_user_id' => 102]]],
    fn ($c, $p) => [$c, [['source_technician_id' => '1', 'cofrac_user_id' => 101]]],
    function ($c, $p) {
        $c['reader']['technician_ids'] = [2];

        return [$c, $p];
    },
    function ($c, $p) {
        $c['occupations']['technician_ids'] = [1, 1];

        return [$c, $p];
    },
    function ($c, $p) {
        $c['pilot']['bindings'][0]['cofrac_user_id'] = 102;

        return [$c, $p];
    },
    function ($c, $p) {
        $c['writer']['writes_enabled'] = false;

        return [$c, $p];
    },
    function ($c, $p) {
        $c['reader']['writes_enabled'] = true;

        return [$c, $p];
    },
    function ($c, $p) {
        $c['client']['guard_enabled'] = false;

        return [$c, $p];
    },
    function ($c, $p) {
        $c['pilot']['sync_enabled'] = false;

        return [$c, $p];
    },
    function ($c, $p) {
        $c['writer']['technical_author_id'] = 2;

        return [$c, $p];
    },
]);

it('only permits writes to five private integration metadata files and no application or database files', function () {
    expect(Activation::PATHS)->toHaveCount(5);
    foreach (Activation::PATHS as $path) {
        expect($path)->toStartWith('/')->not->toContain('/home/ubuntu/www/MYMAR', '/var/www/cofrac2', '.env');
    }
    $script = file_get_contents(__DIR__.'/../../tools/deploy/mymar/roster/activate.sh');
    expect($script)->not->toMatch('/systemctl\s+(?:restart|reload|stop|disable|enable)\b|\bgit_app\s+(?:pull|reset)\b|\bartisan\s+(?:migrate|db:seed)\b/')
        ->and(file_get_contents(__DIR__.'/../../tools/deploy/mymar/roster/runtime-probe.php'))->not->toContain('->post(', '::dispatch(', '->send(');
});
