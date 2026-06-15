<?php

namespace App\Services;

use App\Models\SystemErrorEvent;
use App\Models\SystemHealthSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthMonitor
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function collectChecks(): array
    {
        return [
            $this->measure('database', 'Base de données', fn (): array => $this->checkDatabase()),
            $this->measure('storage', 'Storage writable', fn (): array => $this->checkStorage()),
            $this->measure('disk', 'Espace disque', fn (): array => $this->checkDisk()),
            $this->measure('logs', 'Erreurs applicatives', fn (): array => $this->checkLogs()),
            $this->measure('failed_jobs', 'Jobs échoués', fn (): array => $this->checkFailedJobs()),
            $this->measure('queue_backlog', 'Backlog queue', fn (): array => $this->checkQueueBacklog()),
            $this->measure('runtime_config', 'Configuration runtime', fn (): array => $this->checkRuntimeConfig()),
        ];
    }

    public function run(): SystemHealthSnapshot
    {
        $checks = collect($this->collectChecks());
        $failedCount = $checks->where('status', 'fail')->count();
        $warningCount = $checks->where('status', 'warn')->count();
        $score = max(0, 100 - ($failedCount * 30) - ($warningCount * 10));
        $overallStatus = $failedCount > 0 ? 'fail' : ($warningCount > 0 ? 'warn' : 'ok');

        $snapshot = SystemHealthSnapshot::query()->create([
            'overall_status' => $overallStatus,
            'score' => $score,
            'summary' => [
                'checks' => $checks->count(),
                'failed' => $failedCount,
                'warnings' => $warningCount,
            ],
            'checked_at' => now(),
        ]);

        $snapshot->checks()->createMany($checks->map(fn (array $check): array => [
            'name' => $check['name'],
            'label' => $check['label'],
            'status' => $check['status'],
            'value' => $check['value'] ?? null,
            'message' => $check['message'] ?? null,
            'meta' => $check['meta'] ?? null,
            'duration_ms' => $check['duration_ms'],
            'checked_at' => $snapshot->checked_at,
        ])->all());

        return $snapshot->load('checks');
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(string $name, string $label, callable $callback): array
    {
        $startedAt = microtime(true);

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $result = [
                'status' => 'fail',
                'value' => 'Erreur',
                'message' => $exception->getMessage(),
                'meta' => ['exception' => $exception::class],
            ];
        }

        return [
            'name' => $name,
            'label' => $label,
            'status' => $result['status'],
            'value' => $result['value'] ?? null,
            'message' => $result['message'] ?? null,
            'meta' => $result['meta'] ?? null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        DB::select('select 1');

        return [
            'status' => 'ok',
            'value' => config('database.default'),
            'message' => 'Connexion active.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStorage(): array
    {
        $paths = [
            storage_path('logs'),
            storage_path('framework'),
            storage_path('app'),
        ];
        $blocked = collect($paths)->reject(fn (string $path): bool => is_dir($path) && is_writable($path))->values();

        return [
            'status' => $blocked->isEmpty() ? 'ok' : 'fail',
            'value' => $blocked->isEmpty() ? 'OK' : $blocked->count().' chemin(s)',
            'message' => $blocked->isEmpty()
                ? 'Les répertoires critiques sont accessibles en écriture.'
                : 'Certains répertoires storage ne sont pas writable.',
            'meta' => ['blocked_paths' => $blocked->all()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDisk(): array
    {
        $total = disk_total_space(base_path()) ?: 0;
        $free = disk_free_space(base_path()) ?: 0;
        $freePercent = $total > 0 ? round(($free / $total) * 100, 1) : 0;
        $status = $freePercent < 5 ? 'fail' : ($freePercent < 15 ? 'warn' : 'ok');

        return [
            'status' => $status,
            'value' => $freePercent.'%',
            'message' => 'Espace disque libre.',
            'meta' => [
                'free_bytes' => $free,
                'total_bytes' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkLogs(): array
    {
        $events = $this->recentLaravelLogEvents();
        $errors = $events->where('severity', 'ERROR');
        $warnings = $events->whereIn('severity', ['WARNING', 'WARN']);

        $events->each(fn (array $event): mixed => $this->recordErrorEvent($event));

        return [
            'status' => $errors->isNotEmpty() ? 'fail' : ($warnings->isNotEmpty() ? 'warn' : 'ok'),
            'value' => $errors->count().' erreur(s)',
            'message' => $events->isEmpty()
                ? 'Aucune erreur récente dans laravel.log.'
                : $events->count().' événement(s) détecté(s) dans les logs récents.',
            'meta' => [
                'errors' => $errors->count(),
                'warnings' => $warnings->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['status' => 'warn', 'value' => 'N/A', 'message' => 'Table failed_jobs absente.'];
        }

        $count = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->count();

        return [
            'status' => $count > 0 ? 'fail' : 'ok',
            'value' => (string) $count,
            'message' => $count > 0 ? 'Des jobs ont échoué sur les dernières 24h.' : 'Aucun job échoué récent.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkQueueBacklog(): array
    {
        if (! Schema::hasTable('jobs')) {
            return ['status' => 'warn', 'value' => 'N/A', 'message' => 'Table jobs absente.'];
        }

        $count = DB::table('jobs')->count();

        return [
            'status' => $count > 100 ? 'fail' : ($count > 25 ? 'warn' : 'ok'),
            'value' => (string) $count,
            'message' => 'Jobs en attente.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRuntimeConfig(): array
    {
        $isRiskyDebug = app()->environment('production') && (bool) config('app.debug');

        return [
            'status' => $isRiskyDebug ? 'fail' : 'ok',
            'value' => app()->environment(),
            'message' => $isRiskyDebug ? 'APP_DEBUG est actif en production.' : 'Configuration runtime coherente.',
            'meta' => [
                'debug' => (bool) config('app.debug'),
                'queue' => config('queue.default'),
                'cache' => config('cache.default'),
            ],
        ];
    }

    private function recentLaravelLogEvents()
    {
        $logPath = (string) config('health.log_path', storage_path('logs/laravel.log'));

        if (! is_file($logPath)) {
            return collect();
        }

        $lines = array_slice(file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -1500);

        return collect($lines)
            ->map(fn (string $line): ?array => $this->parseLogLine($line))
            ->filter()
            ->filter(fn (array $event): bool => ! $event['occurred_at'] || $event['occurred_at']->greaterThanOrEqualTo(now()->subDay()))
            ->values();
    }

    private function parseLogLine(string $line): ?array
    {
        if (! preg_match('/^\[(?<date>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+\w+\.(?<severity>[A-Z]+):\s+(?<message>.*)$/', $line, $matches)) {
            return null;
        }

        if (! in_array($matches['severity'], ['ERROR', 'WARNING', 'WARN', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
            return null;
        }

        return [
            'source' => 'laravel.log',
            'severity' => $matches['severity'],
            'message' => mb_substr($matches['message'], 0, 1000),
            'occurred_at' => Carbon::parse($matches['date']),
        ];
    }

    private function recordErrorEvent(array $event): void
    {
        $fingerprint = hash('sha256', $event['source'].'|'.$event['severity'].'|'.mb_substr($event['message'], 0, 240));

        $existing = SystemErrorEvent::query()->where('fingerprint', $fingerprint)->first();

        if ($existing) {
            if ($existing->last_seen_at && $event['occurred_at'] && $existing->last_seen_at->greaterThanOrEqualTo($event['occurred_at'])) {
                return;
            }

            $existing->update([
                'last_seen_at' => $event['occurred_at'],
                'occurred_at' => $event['occurred_at'],
                'occurrences' => $existing->occurrences + 1,
            ]);

            return;
        }

        SystemErrorEvent::query()->create([
            'source' => $event['source'],
            'severity' => $event['severity'],
            'fingerprint' => $fingerprint,
            'message' => $event['message'],
            'context' => null,
            'occurred_at' => $event['occurred_at'],
            'first_seen_at' => $event['occurred_at'],
            'last_seen_at' => $event['occurred_at'],
            'occurrences' => 1,
        ]);
    }
}
