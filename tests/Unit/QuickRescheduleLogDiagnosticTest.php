<?php

require_once dirname(__DIR__, 2).'/tools/deploy/diagnose-quick-reschedule.php';

it('only emits allowlisted technical facts for replacement errors without customer data or SQL', function () {
    $log = <<<'LOG'
[2026-11-18 08:17:00] production.ERROR: SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'address' at row 1 (Connection: mysql, SQL: insert into appointments address='CUSTOMER_PRIVATE_ADDRESS') {"userId":42,"exception":"[object] (Illuminate\Database\QueryException(code: 22001): SQLSTATE[22001] password=PRIVATE_SECRET at /var/www/cofrac2/vendor/file.php:1)
#0 /var/www/cofrac2/app/Services/Crm/DossierAppointmentRescheduler.php(119): Illuminate\Database\Connection->insert()
#1 /var/www/cofrac2/app/Http/Controllers/Crm/DossierAppointmentRescheduleController.php(47): App\Services\Crm\DossierAppointmentRescheduler->reschedule()
"}
[2026-11-18 08:18:00] production.ERROR: unrelated PRIVATE_EMAIL@example.test {"exception":"[object] (RuntimeException(code: 0): unrelated)
#0 /var/www/cofrac2/app/Services/OtherService.php(1)
"}
[2026-11-18 08:19:00] production.INFO: DossierAppointmentRescheduler INFO_SECRET
LOG;
    $facts = QuickRescheduleLogDiagnostic::inspect($log);
    expect($facts)->toHaveCount(1)->and($facts[0])->toBe([
        'logged_at' => '2026-11-18 08:17:00', 'sqlstate' => '22001',
        'value_too_long_column' => 'address', 'exception_class' => 'Illuminate\Database\QueryException',
        'application_frames' => ['app/Services/Crm/DossierAppointmentRescheduler.php:119',
            'app/Http/Controllers/Crm/DossierAppointmentRescheduleController.php:47'],
    ]);
    expect(json_encode($facts))->not->toContain('PRIVATE', 'userId', 'insert into', 'SQL:', 'password');
});

it('ignores partial entries and bounds the number of diagnostics', function () {
    $log = 'PRIVATE_TRUNCATED DossierAppointmentRescheduler';
    foreach (range(10, 29) as $second) {
        $log .= "\n[2026-11-18 08:17:$second] production.ERROR: Unknown column 'global_plus_review'\n"
            .'#0 /var/www/cofrac2/app/Services/Crm/DossierAppointmentRescheduler.php(119): update()';
    }
    $facts = QuickRescheduleLogDiagnostic::inspect($log);
    expect($facts)->toHaveCount(8)->and($facts[0]['logged_at'])->toBe('2026-11-18 08:17:22')
        ->and($facts[7]['unknown_column'])->toBe('global_plus_review');
});
