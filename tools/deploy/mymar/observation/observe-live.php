<?php

declare(strict_types=1);

use App\Services\Mymar\MymarReadWindow;
use Cofrac2\Deploy\Mymar\MymarLiveObservation;

umask(0077);
ini_set('display_errors', '0');

function liveReadJson(string $path, bool $gzip = false): array
{
    if (realpath($path) !== $path || ! is_file($path) || filesize($path) > 2097152) {
        throw new RuntimeException('mymar.invalid_private_input');
    }
    $content = file_get_contents($path);
    if ($gzip) {
        $content = gzdecode($content, 4194304);
    }
    if (! is_string($content) || strlen($content) > 4194304) {
        throw new RuntimeException('mymar.invalid_private_input');
    }
    $value = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('mymar.invalid_private_input');
    }

    return $value;
}

function liveWrite(string $path, array|string $content): void
{
    if (is_array($content)) {
        $content = json_encode($content, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    }
    if (str_ends_with($path, '.gz')) {
        $content = gzencode($content, 6);
    }
    $handle = fopen($path, 'x');
    if ($handle === false || fwrite($handle, $content) !== strlen($content) || ! fclose($handle) || ! chmod($path, 0600)) {
        throw new RuntimeException('mymar.private_output_failed');
    }
}

try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 3 || ! in_array($argv[1], ['preflight', 'prepare', 'finish'], true)) {
        throw new RuntimeException('mymar.invalid_invocation');
    }
    $run = $argv[2];
    if (realpath($run) !== $run || ! is_dir($run) || (fileperms($run) & 0777) !== 0700 || fileowner($run) !== 0
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-readonly-[A-Za-z0-9]+$~D', $run)) {
        throw new RuntimeException('mymar.invalid_private_directory');
    }
    $revision = trim(file_get_contents(__DIR__.'/SOURCE_REVISION'));
    $state = '/var/lib/cofrac2-production';
    $prepared = liveReadJson($state.'/RUNTIME-PREPARED.json');
    if (! preg_match('/^[a-f0-9]{40}$/D', $revision) || ($prepared['revision'] ?? null) !== $revision
        || ! is_file($state.'/INTERNAL-SITE-OPEN.json')) {
        throw new RuntimeException('mymar.unexpected_deployed_release');
    }
    $disabled = liveReadJson($state.'/mymar-gateway-prepared/'.$revision.'/DISABLED.json');
    if (($disabled['revision'] ?? null) !== $revision || ($disabled['enabled'] ?? null) !== false
        || ($disabled['configured'] ?? null) !== false || ! is_array($disabled['files'] ?? null)) {
        throw new RuntimeException('mymar.disabled_preparation_required');
    }
    foreach (['MymarObservation', 'MymarObservationPolicy', 'MymarReadWindow', 'MymarReadOnlySource'] as $name) {
        $relative = str_starts_with($name, 'MymarReadOnly') ? 'tools/deploy/mymar/gateway/'.$name.'.php' : 'app/Services/Mymar/'.$name.'.php';
        if (! isset($disabled['files'][$relative]) || ! hash_equals($disabled['files'][$relative], hash_file('sha256', __DIR__.'/'.$name.'.php'))) {
            throw new RuntimeException('mymar.prepared_contract_changed');
        }
        require __DIR__.'/'.$name.'.php';
    }
    require __DIR__.'/MymarLiveObservation.php';
    if ($argv[1] === 'preflight') {
        exit(0);
    }
    $proof = liveReadJson($run.'/source-state.private.json');
    foreach ($proof as $path => $hash) {
        if (! is_string($path) || ! preg_match('~^/(?:var/www/cofrac2|home/ubuntu/www/MYMAR)/~', $path)
            || ($hash === null ? file_exists($path) || is_link($path)
                : realpath($path) !== $path || ! is_file($path) || ! hash_equals($hash, hash_file('sha256', $path)))) {
            throw new RuntimeException('mymar.application_source_changed');
        }
    }
    if ($argv[1] === 'prepare') {
        $audit = liveReadJson($run.'/summary.json');
        $references = liveReadJson($run.'/database-audit.json.gz', true);
        if (($audit['sql']['complete'] ?? null) !== true || ($audit['source_unchanged_during_collection'] ?? null) !== true
            || ($references['complete'] ?? null) !== true || ($references['format'] ?? null) !== 'cofrac.mymar-reference-audit'
            || ! is_array($references['records'] ?? null)) {
            throw new RuntimeException('mymar.complete_reference_audit_required');
        }
        $review = MymarLiveObservation::scope($references['records']);
        $start = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->setTime(0, 0);
        $window = new MymarReadWindow($start->format(DATE_ATOM), $start->modify('+7 days')->format(DATE_ATOM), array_column($review, 'source_technician_id'));
        liveWrite($run.'/live-request.private.json', ['revision' => $revision, 'window' => $window->query(), 'review' => $review]);
        foreach (MymarLiveObservation::sql($window) as $mode => $sql) {
            liveWrite($run.'/live-'.$mode.'.sql', $sql);
        }
    } else {
        $request = liveReadJson($run.'/live-request.private.json');
        if (($request['revision'] ?? null) !== $revision || ! is_array($request['review'] ?? null)) {
            throw new RuntimeException('mymar.invalid_observation_request');
        }
        $window = new MymarReadWindow($request['window']['window_start'], $request['window']['window_end'], array_column($request['review'], 'source_technician_id'));
        $file = $run.'/live-observation.jsonl';
        if (realpath($file) !== $file || ! is_file($file) || filesize($file) > 2097152) {
            throw new RuntimeException('mymar.invalid_sql_result');
        }
        $records = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($row) || count($records) >= 2004) {
                throw new RuntimeException('mymar.excessive_sql_result');
            }
            $records[] = $row;
        }
        $observation = MymarLiveObservation::report($records, $window, new DateTimeImmutable('now'));
        $planFile = $run.'/live-explain.private.tsv';
        if (realpath($planFile) !== $planFile || ! is_file($planFile) || filesize($planFile) > 16384) {
            throw new RuntimeException('mymar.invalid_query_plan');
        }
        $plan = MymarLiveObservation::queryPlan(file_get_contents($planFile));
        $proposals = count(array_filter($request['review'], static fn ($row) => $row['suggested_cofrac_user_id'] !== null));
        $summary = ['format' => 'cofrac.mymar-live-observation', 'version' => 1, 'collection_complete' => true,
            'revision' => $revision, 'window_start' => $observation['window_start'], 'window_end' => $observation['window_end'],
            'active_source_technicians' => count($request['review']), 'proposed_links_not_approved' => $proposals,
            'technicians_requiring_review' => count($request['review']) - $proposals, 'counts' => $observation['counts'],
            'issue_counts' => array_count_values(array_column($observation['issues'], 'code')),
            'unassigned_source_rows_in_envelope' => $observation['unassigned_source_rows_in_envelope'],
            'source_unchanged' => true, 'sql_budget_ms' => 1500, 'source_database_read' => true,
            'database_writes' => false, 'accounts_linked' => false, 'http_requests' => false,
            'services_changed' => false, 'connector_enabled' => false, 'can_affect_planning' => false];
        liveWrite($run.'/technicians-review.json.gz', ['mapping_approved' => false, 'technicians' => $request['review']]);
        liveWrite($run.'/occupations-observation.json.gz', $observation);
        liveWrite($run.'/query-plan.json', ['explain_analyze' => false, 'plan' => $plan]);
        liveWrite($run.'/observation-summary.json', $summary);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable) {
    fwrite(STDERR, "STOP : observation MYMAR non validee ; aucun planning, compte ou service modifie. Les diagnostics restent prives.\n");
    exit(1);
}
