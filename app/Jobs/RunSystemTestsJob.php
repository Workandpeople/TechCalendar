<?php

namespace App\Jobs;

use App\Models\SystemTestRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RunSystemTestsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(private readonly int $runId)
    {
    }

    public function handle(): void
    {
        $run = SystemTestRun::query()->findOrFail($this->runId);
        $runtimeDirectory = storage_path('framework/testing/system-runs/'.$run->id);
        $command = $this->commandForSuite((string) $run->suite, $runtimeDirectory);

        $run->update([
            'status' => SystemTestRun::STATUS_RUNNING,
            'command' => $command,
            'started_at' => now(),
            'finished_at' => null,
            'exit_code' => null,
            'output' => null,
            'error_message' => null,
        ]);

        try {
            $this->prepareRuntime($runtimeDirectory);

            $process = new Process($command, base_path(), $this->processEnvironment($runtimeDirectory), null, $this->timeout);
            $process->run();

            $output = trim($process->getOutput().PHP_EOL.$process->getErrorOutput());

            $run->update([
                'status' => $process->isSuccessful() ? SystemTestRun::STATUS_PASSED : SystemTestRun::STATUS_FAILED,
                'exit_code' => $process->getExitCode(),
                'output' => $this->truncateOutput($output),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => SystemTestRun::STATUS_ERROR,
                'error_message' => $exception->getMessage(),
                'output' => $this->truncateOutput($exception->getTraceAsString()),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function commandForSuite(string $suite, string $runtimeDirectory): array
    {
        $command = [
            PHP_BINARY,
            base_path('vendor/bin/pest'),
            '--compact',
            '--colors=never',
            '--configuration=phpunit.xml',
            '--do-not-cache-result',
            '--cache-directory='.$runtimeDirectory.'/phpunit-cache',
        ];

        if ($suite === SystemTestRun::SUITE_UNIT) {
            $command[] = '--testsuite=Unit';
        }

        if ($suite === SystemTestRun::SUITE_FEATURE) {
            $command[] = '--testsuite=Feature';
        }

        return $command;
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(string $runtimeDirectory): array
    {
        $bootstrapCacheDirectory = $runtimeDirectory.'/bootstrap-cache';

        return [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $bootstrapCacheDirectory.'/config.php',
            'APP_EVENTS_CACHE' => $bootstrapCacheDirectory.'/events.php',
            'APP_PACKAGES_CACHE' => $bootstrapCacheDirectory.'/packages.php',
            'APP_ROUTES_CACHE' => $bootstrapCacheDirectory.'/routes.php',
            'APP_SERVICES_CACHE' => $bootstrapCacheDirectory.'/services.php',
            'BCRYPT_ROUNDS' => '4',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'TMP' => $runtimeDirectory.'/tmp',
            'TEMP' => $runtimeDirectory.'/tmp',
            'TMPDIR' => $runtimeDirectory.'/tmp',
            'XDG_CACHE_HOME' => $runtimeDirectory.'/cache',
        ];
    }

    private function prepareRuntime(string $runtimeDirectory): void
    {
        $this->ensureRequiredPhpExtensions();

        foreach ([
            $runtimeDirectory,
            $runtimeDirectory.'/bootstrap-cache',
            $runtimeDirectory.'/cache',
            $runtimeDirectory.'/phpunit-cache',
            $runtimeDirectory.'/tmp',
        ] as $directory) {
            $this->ensureWritableDirectory($directory);
        }

        foreach ($this->requiredWritablePestDirectories() as $directory) {
            $this->ensureWritableDirectory($directory, true);
        }
    }

    /**
     * Pest mutate instantiates this cache even when mutation testing is not used.
     *
     * @return array<int, string>
     */
    private function requiredWritablePestDirectories(): array
    {
        return [
            base_path('vendor/pestphp/pest/.temp'),
            base_path('vendor/pestphp/pest-plugin-mutate/.temp'),
            base_path('vendor/pestphp/pest-plugin-mutate/.temp/pest-mutate-cache'),
        ];
    }

    private function ensureRequiredPhpExtensions(): void
    {
        $missingExtensions = array_values(array_filter(
            $this->requiredPhpExtensions(),
            fn (string $extension): bool => ! extension_loaded($extension)
        ));

        if ($missingExtensions === []) {
            return;
        }

        throw new RuntimeException(
            'Le runner de tests nécessite les extensions PHP suivantes: '
            .implode(', ', $missingExtensions)
            .'. En production Debian/Ubuntu avec PHP 8.3: sudo apt install php8.3-sqlite3 && sudo systemctl restart php8.3-fpm && php artisan queue:restart'
        );
    }

    /**
     * @return array<int, string>
     */
    private function requiredPhpExtensions(): array
    {
        return [
            'pdo_sqlite',
        ];
    }

    private function ensureWritableDirectory(string $directory, bool $isVendorCache = false): void
    {
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        clearstatcache(true, $directory);

        if (is_dir($directory) && is_writable($directory)) {
            return;
        }

        if ($isVendorCache) {
            throw new RuntimeException(
                "Le runner de tests doit pouvoir écrire dans {$directory}. "
                .'Pest utilise encore certains caches sous vendor. En production, crée ce dossier et donne-le au user du worker queue/PHP-FPM, par exemple: '
                .'sudo mkdir -p /var/www/TechCalendar/vendor/pestphp/pest/.temp /var/www/TechCalendar/vendor/pestphp/pest-plugin-mutate/.temp/pest-mutate-cache && '
                .'sudo chown -R www-data:www-data /var/www/TechCalendar/vendor/pestphp/pest/.temp /var/www/TechCalendar/vendor/pestphp/pest-plugin-mutate/.temp'
            );
        }

        throw new RuntimeException("Le runner de tests doit pouvoir écrire dans {$directory}.");
    }

    private function truncateOutput(string $output): string
    {
        $limit = 120_000;

        if (strlen($output) <= $limit) {
            return $output;
        }

        return substr($output, -$limit);
    }
}
