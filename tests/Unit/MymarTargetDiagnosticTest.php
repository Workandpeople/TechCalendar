<?php

require_once dirname(__DIR__, 2).'/tools/deploy/diagnose-mymar-targets.php';

it('identifies only invalid mapped accounts without exporting active identities or private profile fields', function () {
    $valid = ['id' => 11, 'first_name' => 'Active', 'last_name' => 'Hidden', 'role' => 2,
        'admin' => false, 'external_client_id' => null, 'deleted_at' => null,
        'email' => 'PRIVATE_EMAIL', 'password' => 'PRIVATE_PASSWORD', 'phone' => 'PRIVATE_PHONE'];
    $users = [11 => $valid, 12 => [...$valid, 'id' => 12, 'deleted_at' => '2026-11-18 10:16:00'],
        13 => [...$valid, 'id' => 13, 'role' => 0], 14 => [...$valid, 'id' => 14, 'admin' => true],
        15 => [...$valid, 'id' => 15, 'external_client_id' => 20]];
    $report = MymarTargetDiagnostic::inspect([101 => 11, 102 => 12, 103 => 13, 104 => 14, 105 => 15, 106 => 16], $users);
    expect($report['mapped_technicians'])->toBe(6)->and($report['eligible_technicians'])->toBe(1)
        ->and(array_column($report['invalid_targets'], 'reason'))->toBe([
            'deleted_account', 'role_changed', 'administrator_account', 'external_account', 'missing_account',
        ])->and(array_column($report['invalid_targets'], 'cofrac_user_id'))->toBe([12, 13, 14, 15, 16]);
    expect(json_encode($report))->not->toContain('PRIVATE', 'password', 'email', 'phone');
    expect($report['http_requests'])->toBeFalse()->and($report['business_writes'])->toBeFalse();
});

it('does not report unconfigured or eligible technicians as broken links', function () {
    $row = ['id' => 11, 'first_name' => 'Active', 'last_name' => 'Hidden', 'role' => 2,
        'admin' => false, 'external_client_id' => null, 'deleted_at' => null];
    $report = MymarTargetDiagnostic::inspect([101 => 11], [11 => $row, 12 => [...$row, 'role' => 0]]);
    expect($report['invalid_targets'])->toBe([])->and($report['eligible_technicians'])->toBe(1)
        ->and(json_encode($report))->not->toContain('Active', 'Hidden');
});
