<?php

// Disposable Linux/MySQL fixture only. No production host or credentials.
declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarRosterActivation as Files;
use Cofrac2\Deploy\Mymar\MymarRosterRetirement as Retirement;

if (! is_file('/.dockerenv') || ($argv[1] ?? null) !== 'verify') {
    throw new RuntimeException('fixture.invalid_invocation');
}
require '/workspace/vendor/autoload.php';
require '/workspace/tools/deploy/mymar/roster/MymarRosterActivation.php';
require '/workspace/tools/deploy/mymar/roster/MymarRosterRetirement.php';

$original = json_decode(file_get_contents('/fixtures/original-configs.json'), true, 32, JSON_THROW_ON_ERROR);
$expected = Retirement::prepare(array_map(fn ($file) => json_decode($file['content'], true, 16, JSON_THROW_ON_ERROR), $original),
    ['source_technician_id' => 6, 'cofrac_user_id' => 102, 'expected_before' => 2]);
$current = Files::readFiles();
foreach ($current as $name => $file) {
    assert(json_decode($file['content'], true, 16, JSON_THROW_ON_ERROR) === $expected['configs'][$name]);
    foreach (['uid', 'gid', 'mode'] as $field) {
        assert($file[$field] === $original[$name][$field]);
    }
}
$run = $argv[2];
foreach (['audit', 'summary', 'warm-cache', 'final-cache'] as $file) {
    $json = file_get_contents($run.'/'.$file.'.json');
    $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    assert($data['business_writes'] === false && $data['messages_sent'] === false);
    foreach (['CANARY', 'beneficiary@example.test', '3 rue de test', 'Mb3!', str_repeat('0123456789abcdef', 4)] as $secret) {
        assert(! str_contains($json, $secret));
    }
    if (in_array($file, ['warm-cache', 'final-cache'], true)) {
        assert($data['ready'] === true && $data['cache_ready'] === true && $data['mapped_technicians'] === 1 && $data['periods'] >= 1);
    }
    if ($file === 'summary') {
        assert($data['complete'] === true && $data['cache_ready'] === true && $data['remaining_technicians'] === 1);
        assert($data['accounts_changed'] === false && $data['active_commands'] === 0);
    }
    assert(fileowner($run.'/'.$file.'.json') === 0 && (fileperms($run.'/'.$file.'.json') & 0777) === 0600);
}
