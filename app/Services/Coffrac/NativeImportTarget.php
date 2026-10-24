<?php

namespace App\Services\Coffrac;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Production scope exists only inside the dedicated, root-prepared CLI staging operation. */
final class NativeImportTarget
{
    private static ?string $productionDirectory = null;

    public static function assertAllowed(): void
    {
        if (self::$productionDirectory === null) {
            CoffracNativeImporter::assertRehearsalTarget();

            return;
        }
        self::assertProductionConfiguration();
    }

    public static function assertProductionScope(): void
    {
        if (self::$productionDirectory === null) {
            throw new RuntimeException('Import de preparation hors de son contexte CLI protege.');
        }
        self::assertProductionConfiguration();
    }

    public static function production(StagingImportPackage $package, callable $callback): mixed
    {
        self::assertProductionConfiguration();
        if (self::$productionDirectory !== null || ! preg_match('#^/srv/cofrac2-staging-imports/[A-Za-z0-9_-]+/inputs$#D', $package->directory)
            || fileowner($package->directory) !== 0 || is_writable($package->directory)
            || is_writable($package->directory.'/manifest.json')) {
            throw new RuntimeException('Entrees de preparation non protegees par root.');
        }
        $grants = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW GRANTS FOR CURRENT_USER()'));
        if (count($grants) !== 2
            || count(array_filter($grants, fn ($grant) => str_starts_with($grant, 'GRANT ALL PRIVILEGES ON `cofrac2production`.* TO '))) !== 1
            || count(array_filter($grants, fn ($grant) => str_starts_with($grant, 'GRANT USAGE ON *.* TO '))) !== 1
            || str_contains(implode("\n", $grants), 'WITH GRANT OPTION')) {
            throw new RuntimeException('Compte SQL non limite a la seule preparation V2.');
        }
        self::$productionDirectory = $package->directory;
        try {
            return $callback();
        } finally {
            self::$productionDirectory = null;
        }
    }

    public static function canReadPlanningGroup(string $directory, string $file): bool
    {
        return self::$productionDirectory !== null && $directory === self::$productionDirectory.'/planning'
            && fileowner($directory) === 0 && fileowner($file) === 0
            && filegroup($directory) === posix_getegid() && filegroup($file) === posix_getegid()
            && (fileperms($directory) & 0777) === 0750 && (fileperms($file) & 0777) === 0440;
    }

    private static function assertProductionConfiguration(): void
    {
        $connection = config('database.connections.'.config('database.default'), []);
        $runtime = function_exists('posix_getpwnam') ? posix_getpwnam('cofrac2-app') : false;
        if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! $runtime || posix_geteuid() !== $runtime['uid']
            || explode('.', gethostname())[0] !== 'ns365601' || realpath(base_path()) !== '/var/www/cofrac2'
            || ! app()->environment('production') || config('app.debug') || config('app.url') !== 'https://cofrac2.geniuscontrole.com'
            || config('app.timezone') !== 'Europe/Paris'
            || ($connection['driver'] ?? null) !== 'mysql' || ($connection['host'] ?? null) !== '127.0.0.1'
            || (string) ($connection['port'] ?? '') !== '3306' || ($connection['database'] ?? null) !== 'cofrac2production'
            || ($connection['username'] ?? null) !== 'cofrac2_app' || ! empty($connection['url']) || ! empty($connection['unix_socket'])
            || ! config('crm.v2_enabled') || config('simulation.enabled') || config('coffrac.offline_rehearsal')
            || config('mail.default') !== 'log' || config('services.smsfactor.enabled')
            || config('services.smsfactor.mode') !== 'simulate' || config('capcee.enabled')) {
            throw new RuntimeException('Cible refusee : preparation CLI V2 limitee, envois bloques et runtime dedie obligatoires.');
        }
    }
}
