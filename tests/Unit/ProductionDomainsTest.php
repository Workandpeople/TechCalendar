<?php

use Cofrac2\Deploy\ProductionDomains;
use Dotenv\Dotenv;

require_once __DIR__.'/../../tools/deploy/lib/ProductionDomains.php';

function domainEnvironment(): string
{
    return "APP_ENV=production\nAPP_URL=https://cofrac2.geniuscontrole.com\nDB_DATABASE=cofrac2production\n"
        ."CRM_V2_ENABLED=true\nSIMULATION_MODE=false\nVITE_REVERB_HOST=cofrac2.geniuscontrole.com\n"
        ."REVERB_ALLOWED_ORIGINS=cofrac2.geniuscontrole.com\nSESSION_COOKIE=cofrac2_session\nSESSION_SECURE_COOKIE=true\nSESSION_DOMAIN=null\n"
        ."REVERB_HOST=127.0.0.1\nREVERB_PORT=8091\nMAIL_MAILER=smtp\nMAIL_DELIVERY_ENABLED=true\n"
        ."SMSFACTOR_ENABLED=false\nSMSFACTOR_MODE=simulate\nCAPCEE_ENABLED=false\nDB_PASSWORD='fake secret'\n";
}

function domainMaintenance(int $port): string
{
    $tls = $port === 443 ? "SSLEngine on\nSSLCertificateFile /etc/letsencrypt/live/cofrac.geniuscontrole.com/fullchain.pem\nSSLCertificateKeyFile /etc/letsencrypt/live/cofrac.geniuscontrole.com/privkey.pem\n" : '';

    return "# Cofrac legacy-domain maintenance.\n<VirtualHost *:$port>\nServerName cofrac.geniuscontrole.com\n"
        ."DocumentRoot /var/www/cofrac-legacy-maintenance\n$tls"
        ."Alias /.well-known/acme-challenge/ /home/ubuntu/www/coffrac_preprod/public/.well-known/acme-challenge/\n</VirtualHost>\n";
}

it('changes only the canonical URL browser websocket host and explicit origins', function () {
    $before = domainEnvironment();
    $after = ProductionDomains::environment($before);
    $expected = str_replace(['APP_URL=https://cofrac2.geniuscontrole.com', 'VITE_REVERB_HOST=cofrac2.geniuscontrole.com',
        'REVERB_ALLOWED_ORIGINS=cofrac2.geniuscontrole.com'], ['APP_URL=https://cofrac.geniuscontrole.com',
            'VITE_REVERB_HOST=cofrac.geniuscontrole.com', 'REVERB_ALLOWED_ORIGINS=cofrac2.geniuscontrole.com,cofrac.geniuscontrole.com'], $before);
    expect($after)->toBe($expected)
        ->and(Dotenv::parse($after)['REVERB_HOST'])->toBe('127.0.0.1')
        ->and(Dotenv::parse($after)['MAIL_DELIVERY_ENABLED'])->toBe('true')
        ->and(Dotenv::parse($after)['SMSFACTOR_MODE'])->toBe('simulate');
});

it('refuses wrong environments unsafe cookies or ambiguous domain keys', function (string $key, string $value) {
    $source = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, domainEnvironment());
    expect(fn () => ProductionDomains::environment($source))->toThrow(RuntimeException::class);
})->with([
    ['APP_ENV', 'demo'], ['APP_URL', 'https://other.example.test'], ['DB_DATABASE', 'fiche_crm'],
    ['CRM_V2_ENABLED', 'false'], ['SIMULATION_MODE', 'true'], ['SESSION_DOMAIN', '.geniuscontrole.com'],
    ['SESSION_COOKIE', 'laravel_session'], ['SESSION_SECURE_COOKIE', 'false'], ['SMSFACTOR_ENABLED', 'true'],
    ['SMSFACTOR_MODE', 'send'], ['CAPCEE_ENABLED', 'true'], ['REVERB_ALLOWED_ORIGINS', '*'], ['VITE_REVERB_HOST', 'localhost'],
]);

it('requires exactly one copy of each domain key', function (string $key) {
    expect(fn () => ProductionDomains::environment(domainEnvironment().$key."=unexpected\n"))->toThrow(RuntimeException::class);
    $source = preg_replace('/^'.preg_quote($key, '/').'=.*\n/m', '', domainEnvironment());
    expect(fn () => ProductionDomains::environment($source))->toThrow(RuntimeException::class);
})->with(['APP_URL', 'VITE_REVERB_HOST', 'REVERB_ALLOWED_ORIGINS']);

it('serves the exact V2 public directory and PHP socket with the existing domain certificate', function (int $port) {
    $template = file_get_contents(__DIR__.'/../../tools/deploy/apache-production-app.conf.example');
    $site = ProductionDomains::applicationSite(domainMaintenance($port), $template, $port);
    expect($site)->toContain('ServerName cofrac.geniuscontrole.com')->not->toContain('cofrac2.geniuscontrole.com');
    if ($port === 443) {
        expect($site)->toContain('DocumentRoot /var/www/cofrac2/public', '/run/cofrac2/php-fpm.sock', 'Require all denied',
            'SSLCertificateFile /etc/letsencrypt/live/cofrac.geniuscontrole.com/fullchain.pem',
            'SSLCertificateKeyFile /etc/letsencrypt/live/cofrac.geniuscontrole.com/privkey.pem')
            ->not->toContain('DocumentRoot /home/ubuntu/www/coffrac_preprod/public', 'AllowOverride All');
    } else {
        expect($site)->toContain('https://cofrac.geniuscontrole.com%{REQUEST_URI}',
            '/home/ubuntu/www/coffrac_preprod/public/.well-known/acme-challenge/', 'ForceType text/plain');
    }
})->with([80, 443]);

it('refuses arbitrary original vhosts aliases certificate paths or missing challenge paths', function (string $case) {
    $source = domainMaintenance(443);
    $source = match ($case) {
        'domain' => str_replace('ServerName cofrac.geniuscontrole.com', 'ServerName other.example.test', $source),
        'root' => str_replace('DocumentRoot /var/www/cofrac-legacy-maintenance', 'DocumentRoot /original', $source),
        'certificate' => str_replace('/etc/letsencrypt/live/cofrac.geniuscontrole.com/fullchain.pem', '/etc/../private.pem', $source),
        'extra-host' => $source."<VirtualHost *:443>\nServerName other.example.test\n</VirtualHost>\n",
        'missing-challenge' => str_replace('/home/ubuntu/www/coffrac_preprod/public/.well-known/acme-challenge/', '/unexpected/', $source),
    };
    expect(fn () => ProductionDomains::applicationSite($source,
        file_get_contents(__DIR__.'/../../tools/deploy/apache-production-app.conf.example'), 443))->toThrow(RuntimeException::class);
})->with(['domain', 'root', 'certificate', 'extra-host', 'missing-challenge']);
