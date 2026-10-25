<?php

declare(strict_types=1);

// Standalone: never bootstrap either Laravel application or read their secrets.
function legacyMaintenanceSite(string $source, int $port): string
{
    $domain = 'cofrac.geniuscontrole.com';
    $root = '/var/www/cofrac-legacy-maintenance';
    $challengeRoot = '/home/ubuntu/www/coffrac_preprod/public/.well-known/acme-challenge';

    if (! in_array($port, [80, 443], true)
        || preg_match_all('~<VirtualHost\s+([^>]+)>~i', $source, $hosts) !== 1
        || trim($hosts[1][0]) !== '*:'.$port
        || preg_match_all('~^\s*ServerName\s+(\S+)\s*$~mi', $source, $names) !== 1
        || strcasecmp($names[1][0], $domain) !== 0
        || preg_match_all('~^\s*DocumentRoot\s+([^\r\n]+)~mi', $source, $roots) !== 1
        || trim($roots[1][0], " \t\"'") !== '/home/ubuntu/www/coffrac_preprod/public') {
        throw new RuntimeException('Vhost original inattendu ; aucun remplacement autorise.');
    }

    preg_match_all('~^\s*ServerAlias\s+([^\r\n]+)~mi', $source, $aliases);
    foreach ($aliases[1] as $line) {
        foreach (preg_split('/\s+/', trim($line)) as $alias) {
            if ($alias !== 'www.'.$domain && $alias !== $domain) {
                throw new RuntimeException('Alias original inattendu ; ne pas modifier un autre domaine.');
            }
        }
    }

    $tls = [];
    if ($port === 443) {
        // Keep the existing certificate and TLS policy, not the old PHP/routing configuration.
        foreach (preg_split('/\r?\n/', $source) as $line) {
            if (preg_match('/^\s*SSL[A-Za-z]+\s+/', $line)) {
                $tls[] = trim($line);
            } elseif (preg_match('~^\s*Include\s+"?(/etc/letsencrypt/options-ssl-apache\.conf)"?\s*$~', $line, $include)) {
                $tls[] = 'Include '.$include[1];
            } elseif (preg_match('/^\s*Include(?:Optional)?\s+/i', $line)) {
                throw new RuntimeException('Include HTTPS non reconnu ; verifier manuellement sa politique TLS.');
            }
        }
        $policy = implode("\n", $tls);
        if (! preg_match('/^SSLCertificateFile\s+/mi', $policy)
            || ! preg_match('/^SSLCertificateKeyFile\s+/mi', $policy)) {
            throw new RuntimeException('Certificat HTTPS original non identifie ; aucun certificat remplace.');
        }
        if (! preg_match('/^SSLEngine\s+/mi', $policy)) {
            array_unshift($tls, 'SSLEngine on');
        }
    }
    $tlsConfig = $tls === [] ? '' : '    '.implode("\n    ", $tls)."\n";

    return <<<APACHE
# Cofrac legacy-domain maintenance. Original vhost is kept in the private rollback directory.
<VirtualHost *:$port>
    ServerName $domain
    ServerAlias www.$domain
    DocumentRoot $root
    ErrorLog \${APACHE_LOG_DIR}/cofrac-legacy-maintenance-error.log
    CustomLog \${APACHE_LOG_DIR}/cofrac-legacy-maintenance-access.log combined
    TraceEnable Off
$tlsConfig
    <Directory "$root">
        AllowOverride None
        Options None
        Require all granted
        DirectoryIndex disabled
        SetHandler none
        <Files "index.html">
            ForceType text/html
        </Files>
    </Directory>
    <Location "/">
        SetHandler none
    </Location>
    AddDefaultCharset UTF-8
    ErrorDocument 503 /index.html

    # Only the internal error document and ACME tokens bypass the 503. No old PHP is served.
    RewriteEngine On
    RewriteCond %{ENV:REDIRECT_STATUS} =503
    RewriteRule ^/index\\.html$ - [END]
    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/[A-Za-z0-9_-]+$
    RewriteRule ^ - [R=503,END]

    Alias "/.well-known/acme-challenge/" "$challengeRoot/"
    <Directory "$challengeRoot">
        AllowOverride None
        Options None
        SetHandler none
        ForceType text/plain
        Require all granted
        <FilesMatch "^(?![A-Za-z0-9_-]+$)">
            Require all denied
        </FilesMatch>
    </Directory>

    <IfModule mod_headers.c>
        Header always set Cache-Control "no-store, max-age=0"
        Header always set Retry-After "3600"
        Header always set X-Content-Type-Options "nosniff"
        Header always set Referrer-Policy "no-referrer"
        Header always set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'"
    </IfModule>
</VirtualHost>

APACHE;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if ($argc !== 3 || ! in_array($argv[2], ['80', '443'], true)) {
            throw new RuntimeException('Usage : php8.4 legacy-domain-maintenance.php SOURCE 80|443');
        }
        $source = file_get_contents($argv[1]);
        if ($source === false) {
            throw new RuntimeException('Vhost source illisible.');
        }
        echo legacyMaintenanceSite($source, (int) $argv[2]);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'STOP : '.$exception->getMessage().PHP_EOL);
        exit(1);
    }
}
