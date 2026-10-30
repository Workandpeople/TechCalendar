<?php

use Cofrac2\Deploy\Mymar\MymarSqlGatewayPreparation;

require_once __DIR__.'/../../tools/deploy/mymar/sql-gateway/MymarSqlGatewayPreparation.php';

it('generates independent credentials for a new reserved SQL account', function () {
    $first = MymarSqlGatewayPreparation::credentials();
    $second = MymarSqlGatewayPreparation::credentials();
    expect($first['database_user'])->toMatch('/^cofrac_mymar_ro_[a-f0-9]{8}$/D')
        ->and($first['database_password'])->toMatch('/^Mm2![a-f0-9]{64}$/D')
        ->and($first['token'])->toMatch('/^[a-f0-9]{64}$/D');
    foreach ($first as $key => $value) {
        expect($value)->not->toBe($second[$key]);
    }
});

it('creates an initially locked account with only the two required column grants', function () {
    $credentials = MymarSqlGatewayPreparation::credentials();
    $sql = MymarSqlGatewayPreparation::createSql($credentials['database_user'], $credentials['database_password']);
    expect($sql)->toContain('ACCOUNT LOCK;', 'SELECT (id,status_id,technicien_id) ON MYMAR.dossiers',
        'SELECT (id,dossier_id,date_rdv,duree,is_old) ON MYMAR.dossier_rdv')
        ->not->toContain('GRANT OPTION', '*', 'UPDATE', 'INSERT', 'DELETE', 'FLUSH', 'ROLE');
    expect(substr_count($sql, 'GRANT '))->toBe(2);
});

it('refuses existing or injected account names before generating any SQL', function (string $username) {
    expect(fn () => MymarSqlGatewayPreparation::createSql($username, 'Mm2!'.str_repeat('a', 64)))->toThrow(RuntimeException::class);
    expect(fn () => MymarSqlGatewayPreparation::accountSql($username, 'lock'))->toThrow(RuntimeException::class);
})->with(['root', 'debian-sys-maint', 'cofrac2_app', 'cofrac_mymar_ro', 'cofrac_mymar_ro_12345678\';DROP USER root;--',
    'cofrac_mymar_ro_123456789', 'cofrac_mymar_ro_ABCDEFGH', 'cofrac_mymar_ro_1234567g']);

it('rejects passwords outside the generated safe alphabet and unsupported account operations', function () {
    expect(fn () => MymarSqlGatewayPreparation::createSql('cofrac_mymar_ro_12345678', "injected'password"))->toThrow(RuntimeException::class);
    expect(fn () => MymarSqlGatewayPreparation::accountSql('cofrac_mymar_ro_12345678', 'drop'))->toThrow(RuntimeException::class);
    expect(MymarSqlGatewayPreparation::accountSql('cofrac_mymar_ro_12345678', 'lock'))
        ->toBe("ALTER USER IF EXISTS 'cofrac_mymar_ro_12345678'@'localhost' ACCOUNT LOCK;");
});

it('refuses an enabled configuration or unapproved mapping before accessing SQL', function (array $config, array $map) {
    expect(fn () => MymarSqlGatewayPreparation::probe($config, $map, new DateTimeImmutable, new PDO('sqlite::memory:')))
        ->toThrow(RuntimeException::class, 'mymar.disabled_preparation_required');
})->with([[['enabled' => true], []], [['enabled' => false], []], [['enabled' => false], [
    'format' => 'cofrac.mymar-approved-technician-map', 'connector_enabled' => false, 'can_affect_planning' => true,
    'bindings' => [['source_technician_id' => 1, 'cofrac_user_id' => 101]],
]]]);
