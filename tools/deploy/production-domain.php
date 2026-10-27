<?php

declare(strict_types=1);

use Cofrac2\Deploy\ProductionDomains;

umask(0077);
ini_set('display_errors', '0');
try {
    $app = '/var/www/cofrac2';
    $state = '/var/lib/cofrac2-production';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || realpath(__FILE__) !== $app.'/tools/deploy/production-domain.php') {
        throw new RuntimeException('Operation root reservee au serveur de production V2.');
    }
    require $app.'/vendor/autoload.php';
    require __DIR__.'/lib/ProductionDomains.php';
    if ($argc === 2 && $argv[1] === '--runtime-info') {
        echo ProductionDomains::bundle($state, __DIR__.'/apache-production-app.conf.example') ?? '';
        exit(0);
    }
    if ($argc !== 4 || ! in_array($argv[1], ['--stage', '--apply'], true)
        || ! preg_match('#^/var/lib/cofrac2-production/domains-[A-Za-z0-9_-]+$#D', $argv[2])
        || ! is_dir($argv[2]) || is_link($argv[2]) || fileowner($argv[2]) !== 0 || (fileperms($argv[2]) & 0777) !== 0700
        || ! preg_match('/^[a-f0-9]{40}$/D', $argv[3])) {
        throw new RuntimeException('Action, diagnostic prive ou revision invalide.');
    }
    $work = $argv[2];
    $contents = file_get_contents($app.'/.env');
    $prepared = json_decode(file_get_contents($state.'/RUNTIME-PREPARED.json'), true, 64, JSON_THROW_ON_ERROR);
    if (($prepared['revision'] ?? '') !== $argv[3] || ! hash_equals($prepared['env_sha256'] ?? '', hash('sha256', $contents))) {
        throw new RuntimeException('Revision ou configuration preparee differente.');
    }
    $next = ProductionDomains::environment($contents);
    if ($argv[1] === '--stage') {
        foreach (['open', 'maintenance'] as $mode) {
            if (file_exists($work.'/'.$mode) || ! mkdir($work.'/'.$mode, 0700)) {
                throw new RuntimeException('Preparation des vhosts deja presente.');
            }
        }
        $data = ['format' => 'cofrac2.production-domains', 'primary' => ProductionDomains::PRIMARY,
            'temporary' => ProductionDomains::TEMPORARY, 'directory' => $work, 'revision' => $argv[3],
            'template_sha256' => hash_file('sha256', __DIR__.'/apache-production-app.conf.example'), 'files' => [],
            'enabled_at_utc' => gmdate(DATE_ATOM)];
        foreach (ProductionDomains::SITES as $index => $name) {
            $source = file_get_contents('/etc/apache2/sites-available/'.$name);
            $site = ProductionDomains::applicationSite($source, file_get_contents(__DIR__.'/apache-production-app.conf.example'), $index === 0 ? 80 : 443);
            foreach (['open' => $site, 'maintenance' => $source] as $mode => $value) {
                file_put_contents($work.'/'.$mode.'/'.$name, $value);
                $data['files'][$mode.'/'.$name] = hash('sha256', $value);
            }
        }
        file_put_contents($work.'/domains.next.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($work.'/env.next', $next);
        echo "Configurations des deux domaines preparees ; aucun service ou .env modifie.\n";
        exit(0);
    }
    if (file_exists($state.'/PRODUCTION-DOMAINS.json') || is_link($state.'/PRODUCTION-DOMAINS.json')
        || ! hash_equals(hash_file('sha256', $work.'/env.next'), hash('sha256', $next))) {
        throw new RuntimeException('Configuration de domaine deja appliquee ou preparation differente.');
    }
    $prepared['domain_changes'][] = ['at_utc' => gmdate(DATE_ATOM), 'revision' => $argv[3], 'work_directory' => $work,
        'previous_env_sha256' => $prepared['env_sha256'], 'primary' => ProductionDomains::PRIMARY];
    $prepared['env_sha256'] = hash('sha256', $next);
    $write = static function (string $path, string $value, int $mode, int $group): void {
        $temporary = tempnam(dirname($path), '.cofrac-domain-');
        if ($temporary === false || file_put_contents($temporary, $value) !== strlen($value)
            || ! chgrp($temporary, $group) || ! chmod($temporary, $mode) || ! rename($temporary, $path)) {
            throw new RuntimeException('Ecriture atomique de configuration echouee.');
        }
    };
    $write($app.'/.env', $next, 0640, posix_getpwnam('cofrac2-app')['gid']);
    $write($state.'/RUNTIME-PREPARED.json', json_encode($prepared, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n", 0600, 0);
    $write($state.'/PRODUCTION-DOMAINS.json', file_get_contents($work.'/domains.next.json'), 0600, 0);
    ProductionDomains::bundle($state, __DIR__.'/apache-production-app.conf.example');
    echo "Adresse principale et origines WebSocket mises a jour. Base, documents, mots de passe et modes d envoi inchanges.\n";
} catch (Throwable $exception) {
    $detail = $exception::class === RuntimeException::class ? ' '.$exception->getMessage() : '';
    fwrite(STDERR, 'STOP : configuration de domaine refusee.'.$detail." Aucun secret affiche.\n");
    exit(1);
}
