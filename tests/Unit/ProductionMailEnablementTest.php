<?php

use Cofrac2\Deploy\ProductionMailEnablement;
use Dotenv\Dotenv;

require_once __DIR__.'/../../tools/deploy/lib/ProductionMailEnablement.php';

function mailActivationEnvironment(): string
{
    return "# Fixture only\nAPP_ENV=production\nAPP_URL=https://cofrac2.geniuscontrole.com\nDB_DATABASE=cofrac2production\n"
        ."CRM_V2_ENABLED=true\nSIMULATION_MODE=false\nMAIL_MAILER=log\nMAIL_DELIVERY_ENABLED=false\n"
        ."SMSFACTOR_ENABLED=false\nSMSFACTOR_MODE=simulate\nCAPCEE_ENABLED=false\n"
        ."DB_PASSWORD=fictional-private-value\nMAIL_PASSWORD=fictional-smtp-value\n";
}

it('activates only the delivery gate and SMTP fallback while preserving every secret and SMS gate', function () {
    $source = mailActivationEnvironment();
    $next = ProductionMailEnablement::enabledEnvironment($source);
    $expected = str_replace(['MAIL_MAILER=log', 'MAIL_DELIVERY_ENABLED=false'], ['MAIL_MAILER=smtp', 'MAIL_DELIVERY_ENABLED=true'], $source);
    expect($next)->toBe($expected)
        ->and(Dotenv::parse($next)['SMSFACTOR_ENABLED'])->toBe('false')
        ->and(Dotenv::parse($next)['SMSFACTOR_MODE'])->toBe('simulate');
});

it('refuses unsafe or already changed production inputs without revealing their values', function (string $key, string $value) {
    $source = mailActivationEnvironment();
    $source = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $source);
    expect(fn () => ProductionMailEnablement::enabledEnvironment($source))->toThrow(RuntimeException::class);
})->with([
    ['APP_ENV', 'demo'], ['APP_URL', 'https://other.example.invalid'], ['DB_DATABASE', 'fiche_crm'],
    ['CRM_V2_ENABLED', 'false'], ['SIMULATION_MODE', 'true'], ['MAIL_MAILER', 'smtp'],
    ['MAIL_DELIVERY_ENABLED', 'true'], ['SMSFACTOR_ENABLED', 'true'], ['SMSFACTOR_MODE', 'send'], ['CAPCEE_ENABLED', 'true'],
]);

it('requires unambiguous explicit mail keys', function (string $case) {
    $source = mailActivationEnvironment();
    $source = match ($case) {
        'missing' => str_replace("MAIL_DELIVERY_ENABLED=false\n", '', $source),
        'duplicate' => $source."MAIL_DELIVERY_ENABLED=false\n",
        'duplicate-mailer' => $source."MAIL_MAILER=log\n",
        'invalid' => str_replace('MAIL_DELIVERY_ENABLED=false', 'MAIL_DELIVERY_ENABLED=not-a-boolean', $source),
    };
    expect(fn () => ProductionMailEnablement::enabledEnvironment($source))->toThrow(RuntimeException::class);
})->with(['missing', 'duplicate', 'duplicate-mailer', 'invalid']);
