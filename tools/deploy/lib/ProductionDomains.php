<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use Dotenv\Dotenv;
use RuntimeException;

final class ProductionDomains
{
    public const PRIMARY = 'cofrac.geniuscontrole.com';

    public const TEMPORARY = 'cofrac2.geniuscontrole.com';

    public const SITES = ['cofrac.geniuscontrole.com.conf', 'cofrac.geniuscontrole.com-le-ssl.conf'];

    public static function environment(string $contents): string
    {
        $before = Dotenv::parse($contents);
        foreach (['APP_ENV' => 'production', 'APP_URL' => 'https://'.self::TEMPORARY,
            'DB_DATABASE' => 'cofrac2production', 'CRM_V2_ENABLED' => 'true', 'SIMULATION_MODE' => 'false',
            'VITE_REVERB_HOST' => self::TEMPORARY, 'REVERB_ALLOWED_ORIGINS' => self::TEMPORARY,
            'SESSION_COOKIE' => 'cofrac2_session', 'SESSION_SECURE_COOKIE' => 'true',
            'SMSFACTOR_ENABLED' => 'false', 'SMSFACTOR_MODE' => 'simulate', 'CAPCEE_ENABLED' => 'false'] as $key => $expected) {
            if (($before[$key] ?? null) !== $expected) {
                throw new RuntimeException('Configuration avant ouverture du domaine inattendue : '.$key);
            }
        }
        if (! in_array($before['SESSION_DOMAIN'] ?? null, ['', 'null'], true)) {
            throw new RuntimeException('Cookies isoles par domaine requis ; aucun partage avec les autres sous-domaines.');
        }
        $changes = ['APP_URL' => 'https://'.self::PRIMARY, 'VITE_REVERB_HOST' => self::PRIMARY,
            'REVERB_ALLOWED_ORIGINS' => self::TEMPORARY.','.self::PRIMARY];
        $next = $contents;
        foreach ($changes as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match_all($pattern, $contents) !== 1) {
                throw new RuntimeException('Cle de domaine absente ou dupliquee.');
            }
            $next = preg_replace_callback($pattern, fn () => $key.'='.$value, $next);
        }
        $after = Dotenv::parse($next);
        foreach (array_keys($changes) as $key) {
            unset($before[$key], $after[$key]);
        }
        if ($before !== $after) {
            throw new RuntimeException('Modification hors des trois cles de domaine autorisees.');
        }

        return $next;
    }

    public static function applicationSite(string $maintenance, string $template, int $port): string
    {
        if (! in_array($port, [80, 443], true)
            || ! str_contains($maintenance, '# Cofrac legacy-domain maintenance.')
            || preg_match_all('~<VirtualHost\s+\*:(80|443)>~', $maintenance, $hosts) !== 1
            || (int) $hosts[1][0] !== $port
            || preg_match_all('/^\s*ServerName\s+(\S+)\s*$/m', $maintenance, $names) !== 1
            || $names[1][0] !== self::PRIMARY
            || preg_match_all('/^\s*DocumentRoot\s+(\S+)\s*$/m', $maintenance, $roots) !== 1
            || $roots[1][0] !== '/var/www/cofrac-legacy-maintenance'
            || preg_match_all('~<VirtualHost \*:'.preg_quote((string) $port, '~').'>.*?</VirtualHost>~s', $template, $blocks) !== 1) {
            throw new RuntimeException('Maintenance historique ou modele V2 inattendu.');
        }
        preg_match_all('/^\s*ServerAlias\s+([^\r\n]+)/m', $maintenance, $aliases);
        foreach ($aliases[1] as $line) {
            foreach (preg_split('/\s+/', trim($line)) as $alias) {
                if (! in_array($alias, [self::PRIMARY, 'www.'.self::PRIMARY], true)) {
                    throw new RuntimeException('Alias historique inattendu.');
                }
            }
        }
        $site = str_replace(self::TEMPORARY, self::PRIMARY, $blocks[0][0]);
        if (str_contains($maintenance, 'www.'.self::PRIMARY)) {
            $site = str_replace('ServerName '.self::PRIMARY, 'ServerName '.self::PRIMARY."\n    ServerAlias www.".self::PRIMARY, $site);
        }
        // Reuse the existing legacy certificate; never copy the original PHP handlers.
        if ($port === 443) {
            foreach (['SSLCertificateFile', 'SSLCertificateKeyFile'] as $directive) {
                if (preg_match_all('/^\s*'.$directive.'\s+(\/[^\s"\r\n]+)\s*$/m', $maintenance, $values) !== 1
                    || ! preg_match('~^/[A-Za-z0-9_./-]+\.pem$~D', $values[1][0]) || str_contains($values[1][0], '..')) {
                    throw new RuntimeException('Certificat HTTPS historique non identifie.');
                }
                $site = preg_replace_callback('/^\s*'.$directive.'\s+\S+\s*$/m', fn () => '    '.$directive.' '.$values[1][0], $site);
            }
        }
        // Keep the original certbot webroot challenge route, not the original application.
        $challenge = '/home/ubuntu/www/coffrac_preprod/public/.well-known/acme-challenge';
        if (! str_contains($maintenance, $challenge)) {
            throw new RuntimeException('Racine de renouvellement du certificat historique absente.');
        }
        if ($port === 80) {
            $site = str_replace('</VirtualHost>', <<<APACHE
    Alias "/.well-known/acme-challenge/" "$challenge/"
    <Directory "$challenge">
        AllowOverride None
        Options None
        SetHandler none
        ForceType text/plain
        Require all granted
        <FilesMatch "^(?![A-Za-z0-9_-]+$)">
            Require all denied
        </FilesMatch>
    </Directory>
</VirtualHost>
APACHE, $site);
        }

        return "# Historical domain serves the existing V2 runtime; no separate application.\n".$site."\n";
    }

    public static function bundle(string $state, string $template): ?string
    {
        $marker = $state.'/PRODUCTION-DOMAINS.json';
        if (! file_exists($marker) && ! is_link($marker)) {
            return null;
        }
        self::privateFile($marker);
        $data = json_decode(file_get_contents($marker), true, 32, JSON_THROW_ON_ERROR);
        $directory = $data['directory'] ?? '';
        if (($data['format'] ?? null) !== 'cofrac2.production-domains' || ($data['primary'] ?? null) !== self::PRIMARY
            || ! preg_match('#^'.preg_quote($state, '#').'/domains-[A-Za-z0-9_-]+$#D', $directory)
            || is_link($directory) || realpath($directory) !== $directory || fileowner($directory) !== 0
            || (fileperms($directory) & 0777) !== 0700
            || ($data['template_sha256'] ?? '') !== hash_file('sha256', $template)) {
            throw new RuntimeException('Preparation des domaines ou modele Apache different.');
        }
        foreach (['open', 'maintenance'] as $mode) {
            foreach (self::SITES as $name) {
                $path = $directory.'/'.$mode.'/'.$name;
                self::privateFile($path);
                if (! hash_equals($data['files'][$mode.'/'.$name] ?? '', hash_file('sha256', $path))) {
                    throw new RuntimeException('Configuration de domaine modifiee depuis son approbation.');
                }
            }
        }

        return $directory;
    }

    private static function privateFile(string $path): void
    {
        if (! is_file($path) || is_link($path) || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Fichier de domaine prive et protege par root requis.');
        }
    }
}
