<?php

declare(strict_types=1);

require __DIR__.'/MymarReadOnlyAudit.php';

use Cofrac2\Deploy\MymarReadOnlyAudit as Audit;

umask(0077);

function writeJson(string $path, mixed $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
    if (str_ends_with($path, '.gz')) {
        $json = gzencode($json, 6);
    }
    if (file_exists($path) || is_link($path) || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Private output refused.');
    }
    chmod($path, 0600);
}

function readLines(string $path): array
{
    if (! is_file($path) || is_link($path) || filesize($path) > 8388608) {
        throw new RuntimeException('Invalid SQL report.');
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($row) || ! isset($row['section']) || count($rows) >= 12000) {
            throw new RuntimeException('Invalid SQL report.');
        }
        $rows[] = $row;
    }

    return $rows;
}

function safeApache(): array
{
    $result = [];
    foreach (array_slice(glob('/etc/apache2/sites-enabled/*') ?: [], 0, 150) as $link) {
        $path = realpath($link);
        if ($path === false || ! str_starts_with($path, '/etc/apache2/sites-available/') || ! is_file($path) || filesize($path) > 131072) {
            continue;
        }
        $source = file_get_contents($path);
        if (! str_contains($source, '/home/ubuntu/www/MYMAR') && ! str_contains($source, 'mymar.geniuscontrole.com')) {
            continue;
        }
        $directives = [];
        foreach (explode("\n", $source) as $index => $line) {
            $line = trim($line);
            if (preg_match('/^(ServerName|ServerAlias|DocumentRoot)\s+([A-Za-z0-9_\/.*:-]+)$/D', $line, $m)
                || preg_match('/^Require\s+(all\s+(?:granted|denied)|local|ip\s+[0-9a-fA-F.:\/ ]+)$/D', $line, $m)) {
                $directives[] = ['line' => $index + 1, 'directive' => $line];
            } elseif (preg_match('/^(Auth\w+|Require|RewriteRule|ProxyPass|SetHandler)\b/i', $line, $m)) {
                $directives[] = ['line' => $index + 1, 'directive' => $m[1], 'arguments' => '[not exported]'];
            }
        }
        $result[] = ['file' => basename($path), 'sha256' => hash('sha256', $source), 'selected_directives_only' => $directives];
    }

    return ['files' => $result, 'http_probed' => false, 'effective_access_not_confirmed' => true,
        'note' => 'Directives locales partielles ; pas de preuve du confinement ni lecture des secrets Apache.'];
}

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! in_array($argv[1], ['prepare', 'metadata', 'finish'], true)) {
        throw new RuntimeException('Invalid invocation.');
    }
    $run = realpath($argv[2]);
    if ($run === false || ! is_dir($run) || is_link($argv[2]) || ! str_starts_with($run, '/var/lib/cofrac2-production/mymar-readonly-')
        || (fileperms($run) & 0077) !== 0) {
        throw new RuntimeException('Private report directory required.');
    }

    $roots = ['mymar' => '/home/ubuntu/www/MYMAR', 'cofrac' => '/var/www/cofrac2'];
    foreach ($roots as $root) {
        if (realpath($root) !== $root || is_link($root)) {
            throw new RuntimeException('Unexpected application directory.');
        }
    }

    if ($argv[1] === 'prepare') {
        $proof = [];
        $files = [];
        $bytes = 0;
        $paths = ['routes/api.php', 'app/Http/Kernel.php', 'app/Providers/RouteServiceProvider.php', 'app/Http/Controllers/ApiController.php',
            'app/Http/Controllers/DossierController.php', 'app/Models/Dossier.php', 'app/Models/DossierRDV.php', 'app/Models/Technicien.php',
            'app/Models/User.php', 'app/Console/Commands/RdvCron.php', 'config/app.php'];
        foreach ($paths as $path) {
            $source = Audit::read($roots['mymar'], $path);
            if ($source === null || ($bytes += strlen($source)) > 8388608) {
                throw new RuntimeException('Bounded source read incomplete.');
            }
            $proof[$roots['mymar'].'/'.$path] = hash('sha256', $source);
            $files[$path] = ['sha256' => hash('sha256', $source), 'facts' => Audit::facts($source)];
        }
        foreach ($roots as $root) {
            foreach (['.env', 'bootstrap/cache/config.php'] as $path) {
                $source = Audit::read($root, $path);
                $proof[$root.'/'.$path] = $source === null ? null : hash('sha256', $source);
            }
        }
        $identities = ['mymar' => Audit::identity($roots['mymar'], 'MYMAR'), 'cofrac' => Audit::identity($roots['cofrac'], 'cofrac2production')];
        $routeCache = ['present' => false, 'read_without_execution' => true];
        foreach (['bootstrap/cache/routes-v7.php', 'bootstrap/cache/routes.php'] as $path) {
            $source = Audit::read($roots['mymar'], $path);
            $proof[$roots['mymar'].'/'.$path] = $source === null ? null : hash('sha256', $source);
            if ($source === null) {
                continue;
            }
            $routeCache['present'] = true;
            try {
                $cache = Audit::literalArray($source);
                $routeCache['api_routes'] = [];
                foreach ($cache['attributes'] ?? [] as $route) {
                    $uri = $route['uri'] ?? null;
                    if (! is_string($uri) || ! preg_match('~^api/[a-zA-Z0-9_{}./-]{1,140}$~D', $uri) || preg_match('/[a-zA-Z0-9_-]{32,}/', $uri)) {
                        continue;
                    }
                    $middleware = array_map(static fn ($m) => in_array($m, ['auth:sanctum', 'auth:api', 'auth:web'], true) ? $m : explode(':', (string) $m)[0], (array) ($route['action']['middleware'] ?? []));
                    $middleware = array_values(array_filter($middleware, static fn ($m) => preg_match('~^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.:-]{0,120}$~D', $m)));
                    $routeCache['api_routes'][] = ['uri' => $uri, 'methods' => array_values(array_intersect((array) ($route['methods'] ?? []), ['GET', 'POST', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'DELETE'])), 'middleware_names' => $middleware];
                    if (count($routeCache['api_routes']) > 200) {
                        throw new RuntimeException('Too many cached routes.');
                    }
                }
                $routeCache['decoded'] = true;
                unset($cache);
            } catch (Throwable) {
                $routeCache = ['present' => true, 'decoded' => false, 'read_without_execution' => true];
            }
            break;
        }
        writeJson($run.'/source-state.private.json', $proof);
        writeJson($run.'/source-facts.json.gz', ['format' => 'cofrac.mymar-integration-facts', 'version' => 2, 'collected_at_utc' => gmdate(DATE_ATOM),
            'application_executed' => false, 'http_requests' => false, 'identities' => $identities, 'source_facts' => $files,
            'route_cache' => $routeCache, 'apache_static' => safeApache(), 'php_cli_timezone' => date_default_timezone_get(),
            'limitations' => ['Source facts are not executable code or a full security audit.', 'No default visit duration or blocking status is assumed.',
                'Dates and is_old/mar_type semantics still require application/business confirmation.', 'No frontend templates or business appointment rows are copied.']]);
        $stable = true;
        foreach ($proof as $path => $hash) {
            if ($hash === null) {
                if (file_exists($path) || is_link($path)) {
                    $stable = false;
                }

                continue;
            }
            if (! is_file($path) || is_link($path) || ! hash_equals($hash, hash_file('sha256', $path))) {
                $stable = false;
            }
        }
        $eligible = $stable && $identities['mymar']['sql_eligible'] && $identities['cofrac']['sql_eligible'];
        if ($eligible) {
            file_put_contents($run.'/metadata.sql', Audit::metadataSql());
        }
        echo json_encode(['source_files' => count($files), 'sql_eligible' => $eligible, 'application_executed' => false, 'http_requests' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($argv[1] === 'metadata') {
        Audit::validateMetadata(readLines($run.'/metadata.jsonl'));
        file_put_contents($run.'/data.sql', Audit::dataSql());
        file_put_contents($run.'/explain.sql', Audit::explainSql());
        echo "Schema InnoDB et volumes estimes compatibles avec une collecte bornee.\n";
    } else {
        $unchanged = true;
        $proof = json_decode(file_get_contents($run.'/source-state.private.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($proof as $path => $hash) {
            $root = str_starts_with($path, $roots['mymar'].'/') ? $roots['mymar'] : $roots['cofrac'];
            $source = Audit::read($root, substr($path, strlen($root) + 1));
            if ($hash === null ? $source !== null || is_link($path) : $source === null || ! hash_equals($hash, hash('sha256', $source))) {
                $unchanged = false;
            }
        }
        $sql = ['complete' => false, 'reason' => 'sql_not_collected_or_refused'];
        if (is_file($run.'/database.jsonl') && $unchanged) {
            $rows = readLines($run.'/database.jsonl');
            $bySection = [];
            foreach ($rows as $row) {
                $bySection[$row['section']][] = $row;
            }
            $counts = $bySection['counts'][0] ?? [];
            $complete = ($bySection['complete'][0]['read_only_collection'] ?? false)
                && (int) ($bySection['clock'][0]['transaction_read_only'] ?? 0) === 1
                && ($counts['statuses'] ?? PHP_INT_MAX) <= 200 && ($counts['roles'] ?? PHP_INT_MAX) <= 100
                && ($counts['role_bindings'] ?? PHP_INT_MAX) <= 2500 && ($counts['source_users'] ?? PHP_INT_MAX) <= 2000
                && ($counts['target_users'] ?? PHP_INT_MAX) <= 5000 && ($counts['rdvs'] ?? PHP_INT_MAX) <= 100000
                && count($bySection['status'] ?? []) === (int) ($counts['statuses'] ?? -1)
                && count($bySection['role'] ?? []) === (int) ($counts['roles'] ?? -1)
                && count($bySection['role_binding'] ?? []) === (int) ($counts['role_bindings'] ?? -1)
                && array_sum(array_column($bySection['rdv_group'] ?? [], 'rdvs')) === (int) ($counts['rdvs'] ?? -1);
            if ($complete) {
                foreach ($rows as &$row) {
                    foreach ($row as $key => &$value) {
                        if (is_string($value) && str_contains($key, 'name') && (str_contains($value, '@') || strlen($value) > 200)) {
                            $value = '[label masked]';
                        }
                    }
                    unset($value);
                }
                unset($row);
                writeJson($run.'/database-audit.json.gz', ['format' => 'cofrac.mymar-reference-audit', 'version' => 1, 'complete' => true,
                    'read_only' => true, 'raw_emails_exported' => false, 'passwords_read' => false, 'appointments_individually_exported' => false,
                    'mapping_is_provisional' => true, 'records' => $rows]);
                $sql = ['complete' => true, 'reference_statuses' => $counts['statuses'], 'reference_roles' => $counts['roles'],
                    'rdvs_aggregated' => $counts['rdvs'], 'technicians_to_review' => count($bySection['technician'] ?? [])];
            } else {
                $sql['reason'] = 'partial_or_excessive_result_not_published';
            }
        }
        $sql['stages'] = [];
        if (is_file($run.'/sql-stages.tsv') && ! is_link($run.'/sql-stages.tsv') && filesize($run.'/sql-stages.tsv') <= 8192) {
            foreach (file($run.'/sql-stages.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^(metadata_sql|metadata_validation|data_sql|explain_sql)\t([0-9]{1,3})$/D', $line, $match) && (int) $match[2] <= 255) {
                    $sql['stages'][$match[1]] = (int) $match[2];
                }
            }
        }
        $sql['errors'] = [];
        foreach (['metadata', 'database', 'explain'] as $stage) {
            $file = $run.'/'.$stage.'-private.stderr';
            if (! is_file($file) || is_link($file) || filesize($file) > 65536) {
                continue;
            }
            preg_match_all('/^ERROR ([0-9]{3,5}) \(([A-Z0-9]{5})\)(?: at line ([0-9]+))?/m', file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach (array_slice($matches, 0, 10) as $match) {
                $sql['errors'][] = ['stage' => $stage, 'mysql_code' => (int) $match[1], 'sqlstate' => $match[2],
                    'statement_line' => isset($match[3]) ? (int) $match[3] : null];
            }
        }
        writeJson($run.'/summary.json', ['format' => 'cofrac.mymar-readonly-preparation', 'version' => 1, 'source_unchanged_during_collection' => $unchanged,
            'sql' => $sql, 'application_executed' => false, 'services_changed' => false, 'http_requests' => false, 'connector_enabled' => false,
            'source_or_target_database_writes' => false, 'integration_ready' => false]);
        echo json_encode(['source_unchanged' => $unchanged, 'sql_complete' => $sql['complete'], 'sql_errors' => $sql['errors'], 'integration_ready' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable) {
    fwrite(STDERR, "STOP : collecte statique ou resultat non conforme. Aucun detail de configuration affiche.\n");
    exit(1);
}
