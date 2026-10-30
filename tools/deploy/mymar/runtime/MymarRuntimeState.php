<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use DateTimeImmutable;
use RuntimeException;

/** Root-only installation state; the long-running gateway never loads this maintenance adapter. */
final class MymarRuntimeState
{
    public const STATE = '/var/lib/cofrac2-production';

    public static function read(string $path, int $maximum = 131072): array
    {
        if (realpath($path) !== $path || ! is_file($path) || filesize($path) > $maximum) {
            throw new RuntimeException('mymar.invalid_runtime_input');
        }
        $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('mymar.invalid_runtime_input');
        }

        return $value;
    }

    public static function write(string $path, array $value, bool $replace = false): void
    {
        $target = $replace ? $path.'.new-'.bin2hex(random_bytes(6)) : $path;
        $handle = fopen($target, 'x');
        $content = json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        if ($handle === false || fwrite($handle, $content) !== strlen($content) || ! fclose($handle) || ! chmod($target, 0600)
            || ($replace && ! rename($target, $path))) {
            throw new RuntimeException('mymar.runtime_write_failed');
        }
    }

    public static function admin(#[\SensitiveParameter] string $sql, string $run): string
    {
        $process = proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=3', '20', '/usr/bin/mysql',
            '--defaults-file=/etc/mysql/debian.cnf', '--protocol=socket', '--socket=/var/run/mysqld/mysqld.sock',
            '--connect-timeout=3', '--skip-reconnect', '--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $run.'/mysql-private.stderr', 'a']], $pipes, null,
            ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root', 'MYSQL_HISTFILE' => '/dev/null', 'MYSQL_TEST_LOGIN_FILE' => '/dev/null']);
        if (! is_resource($process)) {
            throw new RuntimeException('mymar.maintenance_client_failed');
        }
        $written = 0;
        while ($written < strlen($sql)) {
            $bytes = @fwrite($pipes[0], substr($sql, $written));
            if ($bytes === false || $bytes === 0) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('mymar.maintenance_input_failed');
            }
            $written += $bytes;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1], 1048577);
        fclose($pipes[1]);
        $result = proc_close($process);
        if ($result !== 0 || ! is_string($output) || strlen($output) > 1048576) {
            throw new RuntimeException('mymar.maintenance_sql_failed');
        }

        return trim($output);
    }

    public static function freshMap(string $path, array $map, string $run): array
    {
        $approvalPath = dirname($path).'/bundle/approved-identities.json';
        $approval = self::read($approvalPath);
        if (! hash_equals($map['approval_sha256'] ?? '', hash_file('sha256', $approvalPath))) {
            throw new RuntimeException('mymar.identity_approval_changed');
        }
        $raw = self::admin(MymarApprovedTechnicianMap::sql($approval), $run);
        $rows = array_map(static fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), explode("\n", $raw));
        $fresh = MymarApprovedTechnicianMap::validate($approval, $rows, new DateTimeImmutable);
        if ($fresh['bindings'] !== ($map['bindings'] ?? null)) {
            throw new RuntimeException('mymar.approved_mapping_identity_drift');
        }

        return $fresh;
    }

    public static function assertProof(array $proof): void
    {
        foreach ($proof as $path => $sha) {
            if ($sha === null ? file_exists($path) || is_link($path) : realpath($path) !== $path || ! is_file($path)
                || ! hash_equals($sha, hash_file('sha256', $path))) {
                throw new RuntimeException('mymar.original_or_prepared_files_changed');
            }
        }
    }
}
