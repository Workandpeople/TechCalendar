<?php

use App\Services\Mymar\MymarActionSettings;
use Cofrac2\Deploy\Mymar\MymarActionsConfiguration;
use Cofrac2\Deploy\Mymar\MymarActionsHttpPlan;

require_once __DIR__.'/../../tools/deploy/mymar/runtime/MymarRuntimePlan.php';
require_once __DIR__.'/../../tools/deploy/mymar/actions/MymarActionsHttpPlan.php';
require_once __DIR__.'/../../tools/deploy/mymar/actions/MymarActionsConfiguration.php';

it('isolates writer services without touching shared Apache or the SELECT-only reader', function () {
    $files = MymarActionsHttpPlan::files();
    expect(array_keys($files))->toBe(['apache.conf', 'php-fpm.conf', 'cofrac-mymar-actions-fpm.service', 'cofrac-mymar-actions-http.service']);
    expect($files['apache.conf'])->toContain('Listen 127.0.0.1:19848', 'Require local', 'LimitRequestBody 16384', '/code/entry.php');
    expect(implode("\n", $files))->toContain('PrivateTmp=true', 'ProtectSystem=strict', 'NoNewPrivileges=true', 'MYMAR_ACTIONS_CONFIG')
        ->not->toContain('19846', '19847', '/etc/apache2', 'MYMAR_GATEWAY_SCOPE', '/var/lib/cofrac-mymar-gateway');
});

function validMymarActionsSettings(): array
{
    return ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => true,
        'port' => 19848, 'generation' => '11111111-1111-4111-8111-111111111111',
        'bindings' => [['source_technician_id' => 5, 'cofrac_user_id' => 101]]];
}

it('accepts only the isolated actions port with unique approved integer identities', function () {
    $config = validMymarActionsSettings();
    expect(MymarActionSettings::validate($config))->toBe($config);
    $config['writes_enabled'] = false;
    expect(MymarActionSettings::validate($config))->toBe($config);
});

it('projects real approved identity proofs to the exact runtime format without relaxing its validation', function () {
    $binding = validMymarActionsSettings()['bindings'][0] + ['source_identity_sha256' => str_repeat('a', 64), 'target_identity_sha256' => str_repeat('b', 64)];
    $broken = validMymarActionsSettings();
    $broken['bindings'] = [$binding];
    expect(fn () => MymarActionSettings::validate($broken))->toThrow(RuntimeException::class, 'mymar.invalid_actions_mapping');
    $broken['bindings'] = MymarActionsConfiguration::bindings([$binding]);
    expect(MymarActionSettings::validate($broken)['bindings'])->toBe(validMymarActionsSettings()['bindings']);
    expect(json_encode($broken))->not->toContain('identity_sha256');
});

it('rejects incomplete or ambiguous prepared identities before projecting runtime configuration', function (string $case) {
    $binding = ['source_technician_id' => 5, 'cofrac_user_id' => 101, 'source_identity_sha256' => str_repeat('a', 64), 'target_identity_sha256' => str_repeat('b', 64)];
    $bindings = [$binding];
    match ($case) {
        'missing_proof' => $bindings = [array_diff_key($binding, ['source_identity_sha256' => true])],
        'extra_field' => $bindings = [$binding + ['unexpected' => true]],
        'invalid_proof' => $bindings[0]['target_identity_sha256'] = '../identity',
        'duplicate_source' => $bindings[] = array_replace($binding, ['cofrac_user_id' => 102]),
        'duplicate_target' => $bindings[] = array_replace($binding, ['source_technician_id' => 6]),
        'string_id' => $bindings[0]['source_technician_id'] = '5',
        'not_a_list' => $bindings = ['binding' => $binding],
        'empty' => $bindings = [],
    };
    expect(fn () => MymarActionsConfiguration::bindings($bindings))->toThrow(RuntimeException::class, 'mymar.invalid_prepared_bindings');
})->with(['missing_proof', 'extra_field', 'invalid_proof', 'duplicate_source', 'duplicate_target', 'string_id', 'not_a_list', 'empty']);

it('rejects ambiguous or unsafe action configuration before any HTTP access', function (string $case) {
    $config = validMymarActionsSettings();
    match ($case) {
        'no_guard' => $config['guard_enabled'] = false,
        'reader_port' => $config['port'] = 19847,
        'credentials' => $config['database_password'] = 'must-not-be-in-runtime',
        'empty' => $config['bindings'] = [],
        'duplicate' => $config['bindings'][] = $config['bindings'][0],
        'string_id' => $config['bindings'][0]['cofrac_user_id'] = '101',
        'unversioned' => $config['version'] = 2,
        'bad_generation' => $config['generation'] = '../config',
    };
    expect(fn () => MymarActionSettings::validate($config))->toThrow(RuntimeException::class);
})->with(['no_guard', 'reader_port', 'credentials', 'empty', 'duplicate', 'string_id', 'unversioned', 'bad_generation']);
