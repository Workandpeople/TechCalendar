<?php

declare(strict_types=1);
use App\Models\User;
use App\Services\Mymar\MymarPilotSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

final class MymarTargetDiagnostic
{
    public static function inspect(array $map, array $users): array
    {
        $invalid = [];
        foreach ($map as $source => $target) {
            $user = $users[$target] ?? null;
            $reason = match (true) {
                $user === null => 'missing_account',
                ($user['deleted_at'] ?? null) !== null => 'deleted_account',
                ($user['external_client_id'] ?? null) !== null => 'external_account',
                (int) $user['role'] !== 2 => 'role_changed',
                (bool) $user['admin'] => 'administrator_account',
                default => null,
            };
            if ($reason !== null) {
                $invalid[] = ['source_technician_id' => $source, 'cofrac_user_id' => $target,
                    'name' => $user ? trim($user['first_name'].' '.$user['last_name']) : null,
                    'reason' => $reason, 'role' => $user ? (int) $user['role'] : null,
                    'admin' => $user ? (bool) $user['admin'] : null,
                    'deleted' => $user ? $user['deleted_at'] !== null : null,
                    'external_assignment' => $user ? $user['external_client_id'] !== null : null];
            }
        }

        return ['format' => 'cofrac.mymar-target-diagnostic', 'version' => 1,
            'mapped_technicians' => count($map), 'eligible_technicians' => count($map) - count($invalid),
            'invalid_targets' => $invalid, 'read_only' => true, 'mymar_database_accessed' => false,
            'http_requests' => false, 'business_writes' => false, 'messages_sent' => false];
    }
}

// Also accepts stdin, so the runtime user needs no access to the operator's home directory.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || ($_SERVER['SCRIPT_FILENAME'] ?? '') === '') {
    ini_set('display_errors', '0');
    ini_set('zend.exception_ignore_args', '1');
    $database = null;
    try {
        if (PHP_SAPI !== 'cli' || gethostname() !== 'ns365601' || $argc !== 1
            || posix_geteuid() !== (posix_getpwnam('cofrac2-app')['uid'] ?? null)) {
            throw new RuntimeException('Invocation refused.');
        }
        chdir('/var/www/cofrac2');
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = DB::connection();
        if (! app()->environment('production') || config('app.debug') || ! config('crm.v2_enabled')
            || $database->getDriverName() !== 'mysql' || $database->getDatabaseName() !== 'cofrac2production') {
            throw new RuntimeException('Unexpected environment.');
        }
        $pilot = app(MymarPilotSettings::class)->load();
        $database->statement('SET SESSION max_execution_time=1500');
        $database->statement('SET SESSION TRANSACTION READ ONLY');
        $database->beginTransaction();
        try {
            $users = User::withTrashed()->whereIn('id', array_values($pilot->technicianMap))
                ->get(['id', 'first_name', 'last_name', 'role', 'admin', 'external_client_id', 'deleted_at'])
                ->keyBy('id')->map(fn ($user) => $user->getAttributes())->all();
            $report = MymarTargetDiagnostic::inspect($pilot->technicianMap, $users);
        } finally {
            $database->rollBack();
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        echo json_encode(['read_only' => true, 'diagnostic_failed' => true, 'error_class' => $error::class,
            'business_writes' => false, 'http_requests' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
}
