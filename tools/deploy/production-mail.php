<?php

declare(strict_types=1);

use Cofrac2\Deploy\ProductionMailEnablement;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

umask(0077);
ini_set('display_errors', '0');

try {
    $root = '/var/www/cofrac2';
    $state = '/var/lib/cofrac2-production';
    $action = $argv[1] ?? '';
    $readOnlyStdin = __FILE__ === 'Standard input code' && $argc === 2
        && in_array($action, ['--inspect-disabled', '--inspect-enabled'], true);
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || explode('.', gethostname())[0] !== 'ns365601'
        || (! $readOnlyStdin && realpath(__FILE__) !== $root.'/tools/deploy/production-mail.php') || ! function_exists('posix_geteuid')) {
        throw new RuntimeException('Activation reservee au serveur V2.');
    }
    require $root.'/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    if ($action === '--enable') {
        if (posix_geteuid() !== 0 || $argc !== 4 || ! preg_match('#^/var/lib/cofrac2-production/mail-enablement-[A-Za-z0-9_-]+$#D', $argv[2])
            || ! is_dir($argv[2]) || is_link($argv[2]) || fileowner($argv[2]) !== 0 || (fileperms($argv[2]) & 0077) !== 0
            || ! preg_match('/^[a-f0-9]{40}$/D', $argv[3])) {
            throw new RuntimeException('Activation root avec diagnostic prive et revision exacte requise.');
        }
        require __DIR__.'/lib/ProductionMailEnablement.php';
        $prepared = json_decode(file_get_contents($state.'/RUNTIME-PREPARED.json'), true, 64, JSON_THROW_ON_ERROR);
        $contents = file_get_contents($root.'/.env');
        if ($prepared['revision'] !== $argv[3] || ! hash_equals($prepared['env_sha256'], hash('sha256', $contents))
            || is_link($root.'/.env') || fileowner($root.'/.env') !== 0 || (fileperms($root.'/.env') & 0777) !== 0640) {
            throw new RuntimeException('Configuration preparee differente ; activation annulee.');
        }
        $audit = json_decode(file_get_contents($argv[2].'/inspection.closed.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($audit['pending_jobs'] ?? null) !== 0 || ($audit['mail_delivery_enabled'] ?? null) !== false
            || ($audit['smtp_configured'] ?? null) !== true) {
            throw new RuntimeException('Inspection de la file et du transport non validee.');
        }
        $next = ProductionMailEnablement::enabledEnvironment($contents);
        $prepared['mail_delivery_changes'][] = ['enabled' => true, 'approval' => 'explicit_root_cli', 'at_utc' => gmdate(DATE_ATOM),
            'revision' => $argv[3], 'work_directory' => $argv[2], 'pending_jobs_before' => 0,
            'failed_jobs_not_retried' => $audit['failed_jobs'], 'previous_env_sha256' => $prepared['env_sha256']];
        $prepared['env_sha256'] = hash('sha256', $next);
        $prepared['mail_delivery_enabled'] = true;
        $prepared['outbound_delivery_enabled'] = true;
        $account = posix_getpwnam('cofrac2-app');
        $write = static function (string $path, string $data, int $mode, int $group): void {
            $temporary = tempnam(dirname($path), '.cofrac2-mail-');
            if ($temporary === false || file_put_contents($temporary, $data) !== strlen($data)
                || ! chgrp($temporary, $group) || ! chmod($temporary, $mode) || ! rename($temporary, $path)) {
                throw new RuntimeException('Ecriture atomique de la configuration refusee.');
            }
        };
        $write($root.'/.env', $next, 0640, $account['gid']);
        $write($state.'/RUNTIME-PREPARED.json', json_encode($prepared, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n", 0600, 0);
        echo "Configuration mail activee et empreinte de preparation mise a jour. Aucun envoi.\n";
        exit(0);
    }
    $account = posix_getpwnam('cofrac2-app');
    if (! $account || posix_geteuid() !== $account['uid'] || $argc !== 2
        || ! in_array($action, ['--inspect-disabled', '--inspect-enabled'], true)) {
        throw new RuntimeException('Inspection reservee au runtime dedie.');
    }
    $expected = $action === '--inspect-enabled';
    if (($env['MAIL_DELIVERY_ENABLED'] ?? null) !== ($expected ? 'true' : 'false')
        || ($env['MAIL_MAILER'] ?? null) !== ($expected ? 'smtp' : 'log')) {
        throw new RuntimeException('Mode mail declare inattendu.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Http::preventStrayRequests();
    Event::listen(MessageSending::class, static fn () => false);
    if (! app()->environment('production') || DB::connection()->getDatabaseName() !== 'cofrac2production'
        || config('app.debug') || config('simulation.enabled') || ! config('crm.v2_enabled')
        || config('mail.delivery_enabled') !== $expected || config('mail.default') !== 'smtp'
        || config('services.smsfactor.enabled') || config('services.smsfactor.mode') !== 'simulate' || config('capcee.enabled')) {
        throw new RuntimeException('Configuration effective non conforme ; aucun envoi ni modification.');
    }
    $smtp = config('mail.mailers.smtp');
    if (! is_array($smtp) || ($smtp['transport'] ?? null) !== 'smtp'
        || blank($smtp['host'] ?? null) || ! in_array((int) ($smtp['port'] ?? 0), [465, 587], true)
        || blank($smtp['username'] ?? null) || blank($smtp['password'] ?? null)
        || ! filter_var(config('mail.contact_address'), FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Configuration SMTP incomplete ; aucun identifiant affiche.');
    }
    $pending = DB::table('jobs')->count();
    if ($pending !== 0) {
        throw new RuntimeException('Des jobs sont encore en attente ou reserves. Attendre leur fin, sans purger ni rejouer la file.');
    }
    echo json_encode(['database' => 'cofrac2production', 'mail_delivery_enabled' => $expected, 'smtp_configured' => true,
        'pending_jobs' => $pending, 'failed_jobs' => DB::table('failed_jobs')->count(),
        'sms_delivery_enabled' => false, 'sms_mode' => 'simulate', 'network_test' => false, 'messages_sent' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    $detail = $exception::class === RuntimeException::class ? ' '.$exception->getMessage() : '';
    file_put_contents('php://stderr', 'STOP : activation mail refusee.'.$detail." Aucun secret affiche.\n");
    exit(1);
}
