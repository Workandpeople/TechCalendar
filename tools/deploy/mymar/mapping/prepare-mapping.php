<?php

declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarApprovedTechnicianMap;
use Cofrac2\Deploy\MymarReadOnlyAudit;

umask(0077);
ini_set('display_errors', '0');

function mappingJson(string $path): array
{
    if (realpath($path) !== $path || ! is_file($path) || filesize($path) > 131072) {
        throw new RuntimeException('mymar.invalid_mapping_input');
    }
    $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('mymar.invalid_mapping_input');
    }

    return $value;
}

function mappingWrite(string $path, array|string $value): void
{
    $content = is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n" : $value;
    $handle = fopen($path, 'x');
    if ($handle === false || fwrite($handle, $content) !== strlen($content) || ! fclose($handle) || ! chmod($path, 0600)) {
        throw new RuntimeException('mymar.mapping_output_failed');
    }
}

try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 3 || ! in_array($argv[1], ['prepare', 'finish'], true)) {
        throw new RuntimeException('mymar.invalid_mapping_invocation');
    }
    $run = $argv[2];
    $state = '/var/lib/cofrac2-production';
    if (realpath($run) !== $run || ! is_dir($run) || (fileperms($run) & 0777) !== 0700 || fileowner($run) !== 0
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+$~D', $run)
        || realpath($state) !== $state || (fileperms($state) & 0777) !== 0700 || fileowner($state) !== 0) {
        throw new RuntimeException('mymar.invalid_mapping_directory');
    }
    require __DIR__.'/MymarReadOnlyAudit.php';
    require __DIR__.'/MymarApprovedTechnicianMap.php';
    $revision = trim(file_get_contents(__DIR__.'/SOURCE_REVISION'));
    $prepared = mappingJson($state.'/RUNTIME-PREPARED.json');
    if (! preg_match('/^[a-f0-9]{40}$/D', $revision) || ($prepared['revision'] ?? null) !== $revision
        || ! is_file($state.'/INTERNAL-SITE-OPEN.json') || is_link($state.'/INTERNAL-SITE-OPEN.json')) {
        throw new RuntimeException('mymar.unexpected_open_release');
    }
    $disabledPath = $state.'/mymar-gateway-prepared/'.$revision.'/DISABLED.json';
    $disabled = mappingJson($disabledPath);
    if (($disabled['format'] ?? null) !== 'cofrac.mymar-disabled-preparation' || ($disabled['version'] ?? null) !== 1
        || ($disabled['revision'] ?? null) !== $revision || ($disabled['enabled'] ?? null) !== false || ($disabled['configured'] ?? null) !== false) {
        throw new RuntimeException('mymar.disabled_gateway_required');
    }
    $approvalPath = __DIR__.'/approved-identities.json';
    $approval = mappingJson($approvalPath);
    MymarApprovedTechnicianMap::bindings($approval);
    if ($argv[1] === 'prepare') {
        $proof = [];
        foreach (['/home/ubuntu/www/MYMAR' => 'MYMAR', '/var/www/cofrac2' => 'cofrac2production'] as $root => $schema) {
            if (realpath($root) !== $root || ! (MymarReadOnlyAudit::identity($root, $schema)['sql_eligible'] ?? false)) {
                throw new RuntimeException('mymar.source_configuration_not_confirmed');
            }
            foreach (['.env', 'bootstrap/cache/config.php'] as $relative) {
                $path = $root.'/'.$relative;
                if (is_link($path) || (file_exists($path) && (realpath($path) !== $path || ! is_file($path)))) {
                    throw new RuntimeException('mymar.indirect_mapping_configuration');
                }
                $proof[$path] = is_file($path) ? hash_file('sha256', $path) : null;
            }
        }
        foreach ([$state.'/RUNTIME-PREPARED.json', $state.'/INTERNAL-SITE-OPEN.json', $disabledPath, $approvalPath] as $path) {
            $proof[$path] = hash_file('sha256', $path);
        }
        mappingWrite($run.'/configuration-proof.private.json', $proof);
        mappingWrite($run.'/validation.sql', MymarApprovedTechnicianMap::sql($approval));
        echo "Verification des identifiants approuves ; SQL borne en lecture seule.\n";
    } else {
        $proof = mappingJson($run.'/configuration-proof.private.json');
        foreach ($proof as $path => $sha) {
            if (! is_string($path) || ($sha === null ? file_exists($path) || is_link($path)
                : realpath($path) !== $path || ! is_file($path) || ! hash_equals($sha, hash_file('sha256', $path)))) {
                throw new RuntimeException('mymar.mapping_configuration_changed');
            }
        }
        $path = $run.'/validation.jsonl';
        if (realpath($path) !== $path || ! is_file($path) || filesize($path) > 1048576) {
            throw new RuntimeException('mymar.invalid_mapping_sql_result');
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (count($rows) >= 810) {
                throw new RuntimeException('mymar.excessive_mapping_sql_result');
            }
            $rows[] = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        }
        $map = MymarApprovedTechnicianMap::validate($approval, $rows, new DateTimeImmutable('now'));
        $map['approval_sha256'] = hash_file('sha256', $approvalPath);
        $map['source_revision'] = $revision;
        mappingWrite($run.'/approved-map.json', $map);
        $summary = ['format' => 'cofrac.mymar-mapping-preparation', 'version' => 1, 'validated_links' => count($map['bindings']),
            'bindings' => array_map(static fn ($binding) => array_intersect_key($binding, ['source_technician_id' => true, 'cofrac_user_id' => true]), $map['bindings']),
            'validated_at_utc' => $map['validated_at_utc'], 'source_revision' => $revision,
            'private_mapping_path' => $run.'/approved-map.json', 'mapping_sha256' => hash_file('sha256', $run.'/approved-map.json'),
            'configuration_unchanged' => true, 'requires_runtime_revalidation' => true, 'database_writes' => false,
            'accounts_created_or_modified' => false, 'services_changed' => false, 'connector_enabled' => false, 'can_affect_planning' => false];
        mappingWrite($run.'/mapping-summary.json', $summary);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $exception) {
    // Only fixed diagnostic codes escape; never a raw SQL message, configuration value or personal label.
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $exception->getMessage()) ? $exception->getMessage() : 'mymar.mapping_validation_failed';
    fwrite(STDERR, 'STOP : '.$reason.". Aucun compte ou planning modifie.\n");
    exit(1);
}
