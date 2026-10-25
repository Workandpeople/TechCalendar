<?php

require_once __DIR__.'/../../tools/deploy/legacy-domain-maintenance.php';

function legacyMaintenanceFixture(int $port = 443): string
{
    $ssl = $port === 443 ? <<<'SSL'
    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCertificateFile /etc/letsencrypt/live/cofrac.geniuscontrole.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/cofrac.geniuscontrole.com/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
SSL : '';

    return <<<APACHE
<VirtualHost *:$port>
    ServerName cofrac.geniuscontrole.com
    ServerAlias www.cofrac.geniuscontrole.com
    DocumentRoot /home/ubuntu/www/coffrac_preprod/public
    <Directory /home/ubuntu/www/coffrac_preprod/public>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch \\.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
    RewriteEngine on
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
$ssl
</VirtualHost>
APACHE;
}

it('preserves the existing TLS policy without PHP handlers or old application routing', function () {
    $config = legacyMaintenanceSite(legacyMaintenanceFixture(), 443);

    expect($config)->toContain('SSLCertificateFile /etc/letsencrypt/live/cofrac.geniuscontrole.com/fullchain.pem')
        ->toContain('SSLCertificateKeyFile /etc/letsencrypt/live/cofrac.geniuscontrole.com/privkey.pem')
        ->toContain('Include /etc/letsencrypt/options-ssl-apache.conf')
        ->toContain('SSLProtocol -all +TLSv1.2 +TLSv1.3')
        ->toContain('DocumentRoot /var/www/cofrac-legacy-maintenance')
        ->toContain('ErrorDocument 503 /index.html')
        ->toContain('RewriteCond %{ENV:REDIRECT_STATUS} =503')
        ->toContain('AllowOverride None')
        ->toContain('Cache-Control "no-store, max-age=0"')
        ->not->toContain('php8.3-fpm.sock')
        ->not->toContain('AllowOverride All')
        ->not->toContain('R=permanent')
        ->not->toContain('DocumentRoot /home/ubuntu');
});

it('does not install or generate certificates for HTTP', function () {
    expect(legacyMaintenanceSite(legacyMaintenanceFixture(80), 80))
        ->toContain('<VirtualHost *:80>')->toContain('[R=503,END]')
        ->not->toContain('SSLCertificate');
});

it('refuses ambiguous vhosts aliases roots and unknown HTTPS includes', function (string $case) {
    $source = legacyMaintenanceFixture();
    $source = match ($case) {
        'other-domain' => str_replace('ServerName cofrac.', 'ServerName other.', $source),
        'other-alias' => str_replace('ServerAlias www.cofrac.geniuscontrole.com', 'ServerAlias another-site.invalid', $source),
        'other-root' => str_replace('DocumentRoot /home/ubuntu/www/coffrac_preprod/public', 'DocumentRoot /var/www/cofrac2/public', $source),
        'multiple-hosts' => $source.legacyMaintenanceFixture(80),
        'wrong-port' => str_replace('*:443', '*:8443', $source),
        'missing-cert' => preg_replace('/^\s*SSLCertificateFile[^\n]*\n/m', '', $source),
        'other-include' => str_replace('Include /etc/letsencrypt/options-ssl-apache.conf', 'Include /etc/apache2/another-site.conf', $source),
        'multiple-names' => str_replace('ServerAlias', "ServerName other.invalid\n    ServerAlias", $source),
    };

    expect(fn () => legacyMaintenanceSite($source, 443))->toThrow(RuntimeException::class);
})->with(['other-domain', 'other-alias', 'other-root', 'multiple-hosts', 'wrong-port', 'missing-cert', 'other-include', 'multiple-names']);

it('shows only the maintenance message without links login information or redirects', function () {
    $page = file_get_contents(__DIR__.'/../../tools/deploy/legacy-domain-maintenance.html');
    $dom = new DOMDocument;
    $dom->loadHTML($page, LIBXML_NOERROR | LIBXML_NOWARNING);

    expect(trim($dom->getElementsByTagName('body')->item(0)->textContent))->toBe('Maintenance en cours');
    expect($dom->getElementsByTagName('a')->length)->toBe(0);
    expect($page)->toContain('data-cofrac-maintenance="true"')
        ->not->toContain('cofrac2')->not->toContain('mot de passe')
        ->not->toContain('<script')->not->toContain('<form')->not->toContain('http-equiv="refresh"');
});
