<?php

declare(strict_types=1);

use Cofrac2\Deploy\MymarReadOnlyAudit as Audit;
use Cofrac2\Deploy\MymarTimeCalibration as Calibration;

require __DIR__.'/MymarReadOnlyAudit.php';
require __DIR__.'/MymarTimeCalibration.php';

umask(0077);

function calibrationWrite(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
    if (str_ends_with($path, '.gz')) {
        $json = gzencode($json, 6);
    }
    $stream = fopen($path, 'xb');
    if ($stream === false || fwrite($stream, $json) !== strlen($json) || ! fclose($stream)) {
        throw new RuntimeException('Incomplete private output.');
    }
    chmod($path, 0600);
}

function calibrationRows(string $file): array
{
    if (! is_file($file) || is_link($file) || filesize($file) > 262144) {
        throw new RuntimeException('Bounded SQL report missing.');
    }
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($row) || ! is_string($row['section'] ?? null) || count($rows) >= 400) {
            throw new RuntimeException('SQL report invalid.');
        }
        $rows[] = $row;
    }

    return $rows;
}

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! in_array($argv[1], ['prepare', 'metadata', 'finish'], true)) {
        throw new RuntimeException('Invalid invocation.');
    }
    $run = realpath($argv[2]);
    $root = '/home/ubuntu/www/MYMAR';
    if ($run === false || ! is_dir($run) || is_link($argv[2]) || (fileperms($run) & 0077) !== 0
        || ! str_starts_with($run, '/var/lib/cofrac2-production/mymar-calibration-')
        || realpath($root) !== $root || is_link($root)) {
        throw new RuntimeException('Unexpected private directories.');
    }

    if ($argv[1] === 'prepare') {
        $source = Calibration::sources($root);
        $proof = $source['proof'];
        unset($source['proof']);
        foreach (['.env', 'bootstrap/cache/config.php'] as $path) {
            $contents = Audit::read($root, $path);
            $proof[$path] = $contents === null ? null : hash('sha256', $contents);
        }
        $identity = Audit::identity($root, 'MYMAR');
        calibrationWrite($run.'/source-proof.private.json', $proof);
        calibrationWrite($run.'/source-facts.json.gz', ['format' => 'cofrac.mymar-time-source', 'version' => 1,
            'collected_at_utc' => gmdate(DATE_ATOM), 'identity' => $identity, ...$source]);
        $eligible = ($identity['sql_eligible'] ?? false) && Calibration::unchanged($root, $proof);
        if ($eligible) {
            file_put_contents($run.'/metadata.sql', Calibration::metadataSql());
        }
        echo json_encode(['source_files' => count($source['files']), 'calendar_source_present' => $source['calendar_source_present'],
            'sql_eligible' => $eligible, 'application_executed' => false, 'http_requests' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($argv[1] === 'metadata') {
        Calibration::validateMetadata(calibrationRows($run.'/metadata.jsonl'));
        file_put_contents($run.'/reference.sql', Calibration::dataSql());
        echo "Lecture ciblee indexee du seul dossier 91 autorisee.\n";
    } else {
        $proof = json_decode(file_get_contents($run.'/source-proof.private.json'), true, 32, JSON_THROW_ON_ERROR);
        $unchanged = Calibration::unchanged($root, $proof);
        $source = json_decode(gzdecode(file_get_contents($run.'/source-facts.json.gz')), true, 64, JSON_THROW_ON_ERROR);
        $steps = [];
        if (is_file($run.'/steps.tsv') && ! is_link($run.'/steps.tsv') && filesize($run.'/steps.tsv') <= 4096) {
            foreach (file($run.'/steps.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^(metadata_sql|metadata_validation|reference_sql)\t([0-9]{1,3})$/D', $line, $match)) {
                    $steps[$match[1]] = (int) $match[2];
                }
            }
        }
        $sqlComplete = false;
        if ($unchanged && $steps === ['metadata_sql' => 0, 'metadata_validation' => 0, 'reference_sql' => 0]
            && is_file($run.'/reference.jsonl')) {
            $reference = Calibration::validateData(calibrationRows($run.'/reference.jsonl'));
            calibrationWrite($run.'/reference.json', ['format' => 'cofrac.mymar-time-reference', 'version' => 1,
                'read_only' => true, 'collected_at_utc' => gmdate(DATE_ATOM), ...$reference]);
            $sqlComplete = true;
        }
        $errors = [];
        foreach (['metadata', 'reference'] as $stage) {
            $file = $run.'/'.$stage.'.stderr.private';
            if (! is_file($file) || is_link($file) || filesize($file) > 65536) {
                continue;
            }
            preg_match_all('/^ERROR ([0-9]{3,5}) \(([A-Z0-9]{5})\)(?: at line ([0-9]+))?/m', file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach (array_slice($matches, 0, 5) as $match) {
                $errors[] = ['stage' => $stage, 'mysql_code' => (int) $match[1], 'sqlstate' => $match[2]];
            }
        }
        $summary = ['format' => 'cofrac.mymar-time-calibration', 'version' => 1, 'complete' => $unchanged && $sqlComplete,
            'selected_source_unchanged' => $unchanged, 'calendar_source_present' => $source['calendar_source_present'],
            'sql_complete' => $sqlComplete, 'sql_stages' => $steps, 'sql_errors' => $errors,
            'application_executed' => false, 'http_requests' => false, 'database_writes' => false, 'services_changed' => false,
            'connector_enabled' => false, 'integration_ready' => false];
        calibrationWrite($run.'/summary.json', $summary);
        echo json_encode(['complete' => $summary['complete'], 'sql_complete' => $sqlComplete,
            'selected_source_unchanged' => $unchanged, 'integration_ready' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable) {
    fwrite(STDERR, "STOP : calibration incomplete ou controle refuse. Aucun detail personnel ou secret affiche.\n");
    exit(1);
}
