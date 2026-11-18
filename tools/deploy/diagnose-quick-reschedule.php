<?php

declare(strict_types=1);

// Standalone: no application bootstrap, database connection, network request or file write.
final class QuickRescheduleLogDiagnostic
{
    public static function inspect(string $contents): array
    {
        $results = [];
        foreach (preg_split('/(?=^\[\d{4}-\d{2}-\d{2} )/m', $contents) ?: [] as $entry) {
            if (! preg_match('/^\[([0-9 :+-]{19,32})\] [a-z]+\.(?:ERROR|CRITICAL|ALERT|EMERGENCY):/', $entry, $timestamp)
                || ! preg_match('/DossierAppointmentReschedul(?:er|eController)/', $entry)) {
                continue;
            }
            $facts = ['logged_at' => $timestamp[1]];
            if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $entry, $state)) {
                $facts['sqlstate'] = $state[1];
            }
            foreach ([
                'value_too_long_column' => "/Data too long for column ['`]([A-Za-z_][A-Za-z0-9_.]*)['`]/",
                'unknown_column' => "/Unknown column ['`]([A-Za-z_][A-Za-z0-9_.]*)['`]/",
                'required_column' => "/Column ['`]([A-Za-z_][A-Za-z0-9_.]*)['`] cannot be null/",
                'missing_table' => "/Table ['`]([A-Za-z_][A-Za-z0-9_.]*)['`] doesn't exist/",
                'undefined_method' => '/Call to undefined method ([A-Za-z_\\\\][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*)\(/',
                'exception_class' => '/\[object\] \(([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\(code:/',
            ] as $key => $pattern) {
                if (preg_match($pattern, $entry, $match)) {
                    $facts[$key] = str_replace('\\\\', '\\', $match[1]);
                }
            }
            preg_match_all('~\b(app/[A-Za-z0-9_/.-]+\.php)(?:\(([0-9]+)\)|:([0-9]+))~', $entry, $frames, PREG_SET_ORDER);
            $facts['application_frames'] = array_slice(array_values(array_unique(array_map(
                static fn (array $frame): string => $frame[1].':'.($frame[2] ?: $frame[3]), $frames
            ))), 0, 8);
            $results[] = $facts;
        }

        return array_slice($results, -8);
    }

    public static function collect(): array
    {
        $files = glob('/var/www/cofrac2/storage/logs/laravel*.log') ?: [];
        $files = array_filter($files, static fn (string $path): bool => ! is_link($path) && is_file($path) && is_readable($path));
        usort($files, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
        $results = [];
        $read = 0;
        foreach (array_slice($files, 0, 3) as $path) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            $size = fstat($handle)['size'];
            $offset = max(0, $size - 4 * 1024 * 1024);
            fseek($handle, $offset);
            $contents = fread($handle, 4 * 1024 * 1024);
            fclose($handle);
            if ($contents !== false) {
                $results = [...$results, ...self::inspect($contents)];
                $read++;
            }
        }
        usort($results, static fn (array $left, array $right): int => strcmp($left['logged_at'], $right['logged_at']));

        return ['format' => 'cofrac.quick-reschedule-log-diagnostic', 'version' => 1,
            'read_only' => true, 'application_bootstrapped' => false, 'database_accessed' => false,
            'http_requests' => false, 'messages_sent' => false, 'raw_messages_included' => false,
            'log_files_read' => $read, 'scan_limit_bytes_per_file' => 4 * 1024 * 1024,
            'errors' => array_slice($results, -8)];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli' || $argc !== 1) {
        fwrite(STDERR, "Usage: php8.4 diagnose-quick-reschedule.php (lecture seule).\n");
        exit(1);
    }
    echo json_encode(QuickRescheduleLogDiagnostic::collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}
