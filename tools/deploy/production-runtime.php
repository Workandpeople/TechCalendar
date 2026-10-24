<?php

declare(strict_types=1);

use App\Jobs\ProductionRuntimeProbe;
use Cofrac2\Deploy\CoffracDocumentArchive;
use Cofrac2\Deploy\ProductionRuntimeBundle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

umask(0077);
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $root = '/var/www/cofrac2';
    $state = '/var/lib/cofrac2-production';
    $archive = '/srv/cofrac2-document-archive';
    $runtime = '/srv/cofrac2-runtime';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || explode('.', gethostname())[0] !== 'ns365601'
        || realpath(__FILE__) !== $root.'/tools/deploy/production-runtime.php' || ! function_exists('posix_geteuid')) {
        throw new RuntimeException('Preparation runtime reservee au serveur V2.');
    }
    require $root.'/vendor/autoload.php';
    require __DIR__.'/lib/ProductionRuntimeBundle.php';
    $action = $argv[1] ?? '';
    $env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://cofrac2.geniuscontrole.com', 'APP_TIMEZONE' => 'Europe/Paris',
        'CRM_V2_ENABLED' => 'true', 'SIMULATION_MODE' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306', 'DB_DATABASE' => 'cofrac2production', 'DB_USERNAME' => 'cofrac2_app', 'DB_URL' => '', 'DB_SOCKET' => '',
        'MAIL_MAILER' => 'log', 'SMSFACTOR_ENABLED' => 'false', 'SMSFACTOR_MODE' => 'simulate', 'CAPCEE_ENABLED' => 'false',
        'QUEUE_CONNECTION' => 'database', 'SESSION_SECURE_COOKIE' => 'true', 'SESSION_COOKIE' => 'cofrac2_session', 'REVERB_SERVER_HOST' => '127.0.0.1',
        'REVERB_SERVER_PORT' => '8091', 'REVERB_ALLOWED_ORIGINS' => 'cofrac2.geniuscontrole.com'] as $key => $expected) {
        if (($env[$key] ?? null) !== $expected) {
            throw new RuntimeException('Configuration runtime refusee : '.$key);
        }
    }
    if ($action === '--prepare') {
        if (posix_geteuid() !== 0 || $argc !== 5 || ! preg_match('#^/var/lib/cofrac2-production/runtime-[A-Za-z0-9_-]+/bundle$#D', $argv[2])) {
            throw new RuntimeException('Preparation root avec copie privee du paquet requise.');
        }
        $bundle = new ProductionRuntimeBundle($argv[2], $argv[3]);
        if (file_exists($runtime) || is_link($runtime) || file_exists($root.'/public/build') || is_link($root.'/public/build')
            || file_exists($root.'/public/hot') || is_link($root.'/public/hot')) {
            throw new RuntimeException('Runtime ou build deja present, ou serveur Vite local inattendu.');
        }
        $import = json_decode(file_get_contents($state.'/STAGING-IMPORTED.json'), true, 64, JSON_THROW_ON_ERROR);
        if (! preg_match('#^/var/lib/cofrac2-production/native-import-[A-Za-z0-9_-]+/report\.json$#D', $import['report'] ?? '')) {
            throw new RuntimeException('Rapport d import attendu.');
        }
        $report = json_decode(file_get_contents($import['report']), true, 512, JSON_THROW_ON_ERROR);
        if (($import['package_sha256'] ?? null) !== $bundle->manifest['import_package_sha256']
            || ($import['package_sha256'] ?? null) !== ($report['staging_import']['package_sha256'] ?? null)
            || ($report['staging_import']['purpose'] ?? null) !== 'isolated_production_preparation'
            || ($report['document_verification']['native_database_links_match_plan'] ?? null) !== true) {
            throw new RuntimeException('Import et rapprochement documentaire non valides.');
        }
        require __DIR__.'/lib/CoffracDocumentArchive.php';
        $proof = CoffracDocumentArchive::verifiedIndex($bundle->manifest['documents']['inventory'], $archive, $bundle->manifest['documents']['verification']);
        if (($report['document_verification']['plan_sha256'] ?? '') !== $bundle->manifest['documents']['plan_sha256']
            || $proof['summary']['inventory_sha256'] !== $bundle->manifest['documents']['inventory_sha256']
            || $proof['verification_sha256'] !== $bundle->manifest['documents']['verification_sha256']) {
            throw new RuntimeException('Preuves documentaires differentes de l import approuve.');
        }
        $account = posix_getpwnam('cofrac2-app');
        $group = $account['gid'];
        $protect = static function (string $path, int $mode) use ($group): void {
            if (is_link($path) || fileowner($path) !== 0 || ! chgrp($path, $group) || ! chmod($path, $mode)) {
                throw new RuntimeException('Copie ou chemin runtime non protege.');
            }
        };
        mkdir($runtime, 0700);
        $protect($runtime, 0750);
        $manifest = '';
        $readable = 0;
        $parents = [];
        // Grant access to the independent copy only, never the original or quarantined files.
        foreach ($proof['files'] as $relative => $metadata) {
            if (! $metadata['servable']) {
                continue;
            }
            $path = $archive.'/files/'.$relative;
            $parent = dirname($path);
            while ($parent !== '/srv') {
                if (! isset($parents[$parent])) {
                    $protect($parent, $parent === $archive || $parent === $archive.'/files' || $parent === $archive.'/files/public' ? 0710 : 0750);
                    $parents[$parent] = true;
                }
                $parent = dirname($parent);
            }
            $protect($path, 0640);
            $manifest .= substr($relative, strlen('public/documents/'))."\0";
            $readable++;
        }
        if ($readable !== $proof['summary']['servable_documents'] || $readable === 0) {
            throw new RuntimeException('Selection documentaire accessible incomplete.');
        }
        file_put_contents($runtime.'/documents.list0', $manifest);
        $protect($runtime.'/documents.list0', 0440);
        mkdir($root.'/public/build', 0755);
        mkdir($root.'/public/build/assets', 0755);
        foreach ($bundle->manifest['files'] as $name => $metadata) {
            if (str_starts_with($name, 'build/')) {
                copy($bundle->directory.'/'.$name, $root.'/public/'.$name);
                chmod($root.'/public/'.$name, 0644);
            }
        }
        // The deployment account owns assets; the PHP runtime must not be able to replace code.
        $deployer = posix_getpwnam('cofrac2-deploy');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/build', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            chown($entry->getPathname(), $deployer['uid']);
            chgrp($entry->getPathname(), $group);
            chmod($entry->getPathname(), $entry->isDir() ? 0755 : 0644);
        }
        chown($root.'/public/build', $deployer['uid']);
        chgrp($root.'/public/build', $group);
        chmod($root.'/public/build', 0755);
        $changes = [...$bundle->apis, 'MAIL_DELIVERY_ENABLED' => 'false', 'CRM_COLLECTED_DOCUMENTS_ROOT' => $archive.'/files/public/documents',
            'CRM_COLLECTED_DOCUMENTS_MANIFEST' => $runtime.'/documents.list0'];
        $contents = file_get_contents($root.'/.env');
        foreach ($changes as $key => $value) {
            // These API credentials must be literal, not dotenv interpolation expressions.
            if (str_contains($value, '$')) {
                throw new RuntimeException('Interpolation interdite dans les reglages runtime.');
            }
            $line = $key.'='.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match_all($pattern, $contents) > 1) {
                throw new RuntimeException('Cle runtime dupliquee dans le .env V2.');
            }
            $contents = preg_match($pattern, $contents) ? preg_replace_callback($pattern, fn () => $line, $contents) : rtrim($contents)."\n".$line."\n";
        }
        $new = tempnam($root, '.cofrac2-env-');
        file_put_contents($new, $contents);
        $protect($new, 0640);
        rename($new, $root.'/.env');
        $vite = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $result = ['revision' => $bundle->manifest['revision'], 'bundle_sha256' => $argv[3], 'document_files' => $readable,
            'document_manifest_sha256' => hash_file('sha256', $runtime.'/documents.list0'), 'env_sha256' => hash_file('sha256', $root.'/.env'),
            'asset_probe' => '/build/'.$vite['resources/css/app.css']['file'], 'prepared_at_utc' => gmdate(DATE_ATOM),
            'external_portals_enabled' => false, 'outbound_delivery_enabled' => false, 'final_cutover_ready' => false];
        if ($argv[4] !== dirname($bundle->directory).'/prepared.json' || file_exists($argv[4])) {
            throw new RuntimeException('Destination du rapport runtime inattendue.');
        }
        file_put_contents($argv[4], json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        echo json_encode(['document_files' => $readable, 'assets' => 'compiled', 'apis' => 'configured_without_network_test', 'services_started' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    if (in_array($action, ['--websocket', '--websocket-direct'], true)) {
        if (posix_geteuid() !== 0 || $argc !== 2) {
            throw new RuntimeException('Controle WebSocket root uniquement.');
        }
        $key = base64_encode(random_bytes(16));
        $domain = 'cofrac2.geniuscontrole.com';
        $context = stream_context_create(['ssl' => ['peer_name' => $domain, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $socket = stream_socket_client($action === '--websocket' ? 'tls://127.0.0.1:443' : 'tcp://127.0.0.1:8091', $errno, $error, 8, STREAM_CLIENT_CONNECT, $context);
        stream_set_timeout($socket, 8);
        $path = '/app/'.rawurlencode($env['REVERB_APP_KEY']).'?protocol=7&client=cofrac2-check&version=1.0';
        fwrite($socket, "GET $path HTTP/1.1\r\nHost: $domain\r\nOrigin: https://$domain\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: $key\r\n\r\n");
        $headers = '';
        while (($line = fgets($socket, 4096)) !== false && strlen($headers) < 16384) {
            $headers .= $line;
            if ($line === "\r\n") {
                break;
            }
        }
        fclose($socket);
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (! preg_match('#^HTTP/1\.[01] 101 #', $headers) || ! preg_match('/^Sec-WebSocket-Accept:\s*'.preg_quote($accept, '/').'\s*$/mi', $headers)) {
            throw new RuntimeException('Handshake WebSocket refuse.');
        }
        echo "WebSocket : OK (101).\n";
        exit(0);
    }

    $account = posix_getpwnam('cofrac2-app');
    if (! $account || posix_geteuid() !== $account['uid'] || $argc !== 2 || ! in_array($action, ['--check', '--queue-probe'], true)) {
        throw new RuntimeException('Controle applicatif reserve au runtime dedie.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Http::preventStrayRequests();
    if (! app()->environment('production') || DB::connection()->getDatabaseName() !== 'cofrac2production'
        || config('app.debug') || config('simulation.enabled') || ! config('crm.v2_enabled') || config('mail.default') !== 'log' || config('mail.delivery_enabled')
        || config('services.smsfactor.enabled') || config('services.smsfactor.mode') !== 'simulate' || config('capcee.enabled')
        || config('services.global_plus.api_url') !== 'https://cee-api.test.globalplus.fr'
        || config('queue.connections.database.retry_after') <= 1800 || config('reverb.client.host') !== 'cofrac2.geniuscontrole.com') {
        throw new RuntimeException('Configuration effective differente de la recette interne approuvee.');
    }
    if ($action === '--queue-probe') {
        $nonce = bin2hex(random_bytes(16));
        ProductionRuntimeProbe::dispatch($nonce)->onConnection('database')->onQueue('default');
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if (Cache::get('cofrac2.runtime-probe.'.$nonce) === 'processed') {
                Cache::forget('cofrac2.runtime-probe.'.$nonce);
                echo "Worker : aller-retour verifie, sans appel externe.\n";
                exit(0);
            }
            sleep(1);
        }
        throw new RuntimeException('Sonde du worker non traitee sous 30 secondes. Aucun job rejoue automatiquement.');
    }
    foreach (['users', 'dossiers', 'dossier_operations', 'appointments', 'coffrac_user_links'] as $table) {
        if (! DB::table($table)->exists()) {
            throw new RuntimeException('Donnees natives absentes : '.$table);
        }
    }
    if (DB::table('users')->where('admin', true)->count() !== 1 || DB::table('users')->where('portal_enabled', true)->exists()
        || DB::table('mail_templates')->where('is_active', true)->exists() || DB::table('capcee_deliveries')->exists() || DB::table('dossier_sms')->exists()) {
        throw new RuntimeException('Administrateurs, portails ou envois non conformes a la recette interne.');
    }
    foreach ([$root, $root.'/.env', $root.'/artisan', $root.'/public/build/manifest.json'] as $path) {
        if (! is_readable($path) || is_writable($path)) {
            throw new RuntimeException('Code ou configuration non protege.');
        }
    }
    foreach ([$root.'/storage/logs', $root.'/storage/framework/sessions', $root.'/bootstrap/cache'] as $directory) {
        if (! is_writable($directory)) {
            throw new RuntimeException('Repertoire runtime non inscriptible.');
        }
    }
    foreach (['services.mapbox.token', 'services.openai.api_key', 'services.global_plus.api_key'] as $key) {
        if (! is_string(config($key)) || trim(config($key)) === '') {
            throw new RuntimeException('Cle API manquante : '.$key);
        }
    }
    $rootDocuments = config('crm.collected_documents_root');
    if ($rootDocuments !== $archive.'/files/public/documents' || config('crm.collected_documents_manifest') !== $runtime.'/documents.list0'
        || ! is_readable($runtime.'/documents.list0') || is_writable($runtime.'/documents.list0')) {
        throw new RuntimeException('Selection documentaire privee non configuree.');
    }
    $manifest = file_get_contents($runtime.'/documents.list0');
    if (! str_ends_with($manifest, "\0")) {
        throw new RuntimeException('Manifeste documentaire incomplet.');
    }
    $count = 0;
    foreach (explode("\0", rtrim($manifest, "\0")) as $relative) {
        $path = $rootDocuments.'/'.$relative;
        if (is_link($path) || ! is_readable($path) || is_writable($path) || filesize($path) === 0) {
            throw new RuntimeException('Document de la selection illisible ou modifiable.');
        }
        $count++;
    }
    echo json_encode(['database' => 'cofrac2production', 'document_files_readable' => $count,
        'single_super_admin' => true, 'code_read_only' => true, 'outbound_delivery_enabled' => false,
        'external_portals_enabled' => false, 'apis' => 'configured_without_network_test', 'final_cutover_ready' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    $detail = get_class($exception) === RuntimeException::class ? ' '.$exception->getMessage() : '';
    fwrite(STDERR, 'STOP : runtime V2 ('.get_class($exception).', '.basename($exception->getFile()).':'.$exception->getLine().').'.$detail." Aucun secret affiche.\n");
    exit(1);
}
