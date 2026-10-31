<?php

require_once __DIR__.'/../../tools/deploy/mymar/runtime/MymarRuntimePlan.php';
require_once __DIR__.'/../../tools/deploy/mymar/booking-http/MymarBookingHttpPlan.php';
require_once __DIR__.'/../../tools/deploy/mymar/booking/MymarBookingSqlAccess.php';
require_once __DIR__.'/../../tools/deploy/mymar/booking/MymarBookingSource.php';

use Cofrac2\Deploy\Mymar\MymarBookingHttpPlan;
use Cofrac2\Deploy\Mymar\MymarBookingSource;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess;

it('uses independent services, user, paths and port with the existing hardened runtime policy', function () {
    $files = MymarBookingHttpPlan::files();
    expect(array_keys($files))->toBe(['apache.conf', 'php-fpm.conf', 'cofrac-mymar-booking-fpm.service', 'cofrac-mymar-booking-http.service']);
    $all = implode("\n", $files);
    expect($files['apache.conf'])->toContain('Listen 127.0.0.1:19847', '/code/entry.php', 'Require local')
        ->and($all)->not->toContain('19846', '/var/lib/cofrac-mymar-gateway', 'MYMAR_GATEWAY_SCOPE', '/etc/apache2')
        ->and($all)->toContain('PrivateTmp=true', 'ProtectSystem=strict', 'NoNewPrivileges=true', 'IPAddressDeny=any', 'pm.max_children = 2');
});

it('creates a fresh locked SELECT-only reader and refuses write grants or the writer identity', function () {
    $credentials = MymarBookingSqlAccess::credentials(readOnly: true);
    expect($credentials['database_user'])->toMatch('/^cofrac_mymar_br_[a-f0-9]{8}$/');
    $sql = MymarBookingSqlAccess::createSql($credentials['database_user'], $credentials['database_password'], readOnly: true);
    expect($sql)->toContain('ACCOUNT LOCK', 'GRANT SELECT')->not->toContain('GRANT INSERT', 'GRANT UPDATE', 'GRANT DELETE', 'GRANT OPTION', 'ACCOUNT UNLOCK');
    $grants = [];
    foreach (MymarBookingSqlAccess::privileges(readOnly: true) as $table => $operations) {
        expect(array_keys($operations))->toBe(['SELECT']);
        $grants[] = 'GRANT SELECT (`'.implode('`, `', $operations['SELECT']).'`) ON `'.str_replace('.', '`.`', $table).'` TO `'.$credentials['database_user'].'`@`localhost`';
    }
    MymarBookingSqlAccess::validateGrants($grants, readOnly: true);
    $grants[] = 'GRANT UPDATE (`status_id`) ON `MYMAR`.`dossiers` TO `'.$credentials['database_user'].'`@`localhost`';
    expect(fn () => MymarBookingSqlAccess::validateGrants($grants, readOnly: true))->toThrow(RuntimeException::class);
    expect(fn () => MymarBookingSqlAccess::createSql('cofrac_mymar_rw_abcdef01', $credentials['database_password'], readOnly: true))->toThrow(RuntimeException::class);
});

it('refuses to enable writes on a reader credential profile before any database operation', function () {
    $pdo = new class extends PDO
    {
        public function __construct() {}
    };
    expect(fn () => new MymarBookingSource($pdo, 99, [5], enabled: true, writesEnabled: true, readOnlyCredentials: true))
        ->toThrow(InvalidArgumentException::class);
});
