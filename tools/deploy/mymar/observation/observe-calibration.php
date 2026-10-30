<?php

declare(strict_types=1);

use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;

// Offline only: autoload and Laravel bootstrap are deliberately not required.
$project = dirname(__DIR__, 4);
require_once $project.'/app/Services/Mymar/MymarObservationPolicy.php';
require_once $project.'/app/Services/Mymar/MymarObservation.php';

umask(0077);

function calibrationFile(string $directory, string $name, int $limit): string
{
    $path = $directory.'/'.$name;
    if (! is_file($path) || is_link($path) || ! is_readable($path) || filesize($path) > $limit) {
        throw new RuntimeException('Invalid bounded calibration file.');
    }
    $value = file_get_contents($path);
    if (! is_string($value) || strlen($value) > $limit) {
        throw new RuntimeException('Incomplete calibration file.');
    }

    return $value;
}

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
        throw new RuntimeException('Usage: php observe-calibration.php CALIBRATION_DIR NEW_OUTPUT_JSON');
    }
    $directory = realpath($argv[1]);
    $output = $argv[2];
    $parent = realpath(dirname($output));
    if ($directory === false || ! is_dir($directory) || is_link($argv[1])
        || $parent === false || ! is_dir($parent) || is_link(dirname($output)) || (fileperms($parent) & 0077) !== 0
        || str_starts_with($parent.'/', $project.'/') || str_starts_with($parent.'/', '/var/www/')
        || str_starts_with($parent.'/', '/home/ubuntu/www/') || ! str_ends_with($output, '.json')
        || file_exists($output) || is_link($output)) {
        throw new RuntimeException('Use private real directories and a new output file.');
    }
    $files = [];
    foreach (preg_split('/\r?\n/', trim(calibrationFile($directory, 'SHA256SUMS', 4096))) as $line) {
        if (! preg_match('/^([a-f0-9]{64}) [ *](source-facts\.json\.gz|summary\.json|reference\.json)$/D', $line, $match)
            || isset($files[$match[2]])) {
            throw new RuntimeException('Unexpected calibration manifest.');
        }
        $file = calibrationFile($directory, $match[2], 1048576);
        if (! hash_equals($match[1], hash('sha256', $file))) {
            throw new RuntimeException('Calibration checksum mismatch.');
        }
        $files[$match[2]] = $file;
    }
    if (count($files) !== 3) {
        throw new RuntimeException('Incomplete calibration manifest.');
    }
    $summary = json_decode($files['summary.json'], true, 32, JSON_THROW_ON_ERROR);
    $reference = json_decode($files['reference.json'], true, 32, JSON_THROW_ON_ERROR);
    $source = json_decode(gzdecode($files['source-facts.json.gz'], 1048576), true, 64, JSON_THROW_ON_ERROR);
    if (($summary['format'] ?? null) !== 'cofrac.mymar-time-calibration' || ($summary['version'] ?? null) !== 1
        || ($summary['complete'] ?? null) !== true || ($summary['sql_complete'] ?? null) !== true
        || ($summary['selected_source_unchanged'] ?? null) !== true || ($summary['database_writes'] ?? null) !== false
        || ($source['format'] ?? null) !== 'cofrac.mymar-time-source' || ($source['version'] ?? null) !== 1
        || ($source['identity']['database'] ?? null) !== 'MYMAR' || ($source['identity']['sql_eligible'] ?? null) !== true
        || ($reference['format'] ?? null) !== 'cofrac.mymar-time-reference' || ($reference['version'] ?? null) !== 1
        || ($reference['read_only'] ?? null) !== true || ! is_array($reference['references'] ?? null)
        || ! array_is_list($reference['references']) || count($reference['references']) < 1 || count($reference['references']) > 10) {
        throw new RuntimeException('Calibration not eligible for offline observation.');
    }
    $rows = [];
    foreach ($reference['references'] as $row) {
        if (! is_array($row) || ($row['section'] ?? null) !== 'reference' || ($row['dossier_id'] ?? null) !== 91
            || ($row['is_old'] ?? null) !== 0 || ! is_int($row['technician_id'] ?? null)) {
            throw new RuntimeException('Unexpected calibration reference.');
        }
        $rows[] = array_intersect_key($row, array_flip(['rdv_id', 'dossier_id', 'technician_id', 'status_id', 'is_old', 'stored_start', 'raw_duration']));
    }
    $policy = new MymarObservationPolicy;
    $window = mymarReferenceWindow($rows);
    $from = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $window, new DateTimeZone($policy->sourceTimezone));
    if ($from === false || $from->format('Y-m-d H:i:s') !== $window) {
        throw new RuntimeException('Invalid reference observation window.');
    }
    $snapshot = [
        'format' => MymarObservation::FORMAT, 'version' => 1, 'generated_at' => $reference['collected_at_utc'] ?? null,
        'source_timezone' => $policy->sourceTimezone, 'duration_unit' => $policy->durationUnit,
        // A single calibration is never evidence that all appointments in this window were collected.
        'complete' => false, 'window_start' => $from->format(DATE_ATOM), 'window_end' => $from->modify('+7 days')->format(DATE_ATOM),
        'technician_ids' => array_values(array_unique(array_column($rows, 'technician_id'))), 'status_ids' => $policy->statusIds, 'appointments' => $rows,
    ];
    $report = (new MymarObservation)->inspect($snapshot, $policy, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $report['evidence'] = ['kind' => 'single_dossier_calibration_not_full_window', 'reference_sha256' => hash('sha256', $files['reference.json'])];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    $stream = fopen($output, 'xb');
    if ($stream === false || fwrite($stream, $json) !== strlen($json) || ! fclose($stream)) {
        throw new RuntimeException('Incomplete private output.');
    }
    chmod($output, 0600);
    echo json_encode(['mode' => $report['mode'], 'counts' => $report['counts'],
        'connector_enabled' => false, 'planning_ready' => false, 'database_writes' => false, 'http_requests' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "STOP : observation locale refusee ou incomplete. Aucun acces SQL/HTTP ni changement applicatif.\n");
    exit(1);
}

function mymarReferenceWindow(array $rows): string
{
    $date = min(array_column($rows, 'stored_start'));
    if (! is_string($date) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} /', $date)) {
        throw new RuntimeException('Invalid reference date.');
    }

    return substr($date, 0, 10).' 00:00:00';
}
