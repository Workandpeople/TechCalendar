<?php

use Cofrac2\Deploy\MymarReadOnlyAudit;

require_once __DIR__.'/../../tools/deploy/mymar/MymarReadOnlyAudit.php';

it('decodes literal Laravel caches without evaluating application code', function () {
    $data = ['app' => ['timezone' => 'Europe/Paris'], 'database' => ['password' => 'PRIVATE_CANARY'], 'flags' => [null, true, false, -3, 1.5]];
    expect(MymarReadOnlyAudit::literalArray('<?php return '.var_export($data, true).';'))->toBe($data);
    $routes = ['attributes' => ['route' => ['uri' => 'api/json/planning', 'action' => ['middleware' => ['api']]]]];
    expect(MymarReadOnlyAudit::literalArray("<?php app('router')->setCompiledRoutes(".var_export($routes, true).');'))->toBe($routes);
});

it('rejects executable cache contents instead of evaluating them', function (string $source) {
    expect(fn () => MymarReadOnlyAudit::literalArray($source))->toThrow(RuntimeException::class);
})->with([
    '<?php return ["key" => file_put_contents("/tmp/NEVER_EXECUTE", "bad")];',
    '<?php return ["key" => env("DB_PASSWORD")];',
    '<?php return []; echo "not allowed";',
    '<?php app("anything")->setCompiledRoutes([]);',
    '<?php include "/tmp/NEVER_INCLUDE"; return [];',
    '<?php return new stdClass;',
]);

it('reports only syntax kinds when a cache is refused', function () {
    $source = <<<'PHP'
<?php
return [
    'password' => 'PRIVATE_PASSWORD_123',
    'unrelated' => \Private\SecretClass::__set_state(['key' => 'PRIVATE_TOKEN_456']),
];
PHP;
    $diagnostic = null;
    try {
        MymarReadOnlyAudit::literalArray($source, $diagnostic);
        $this->fail('Non-literal contents must remain refused.');
    } catch (RuntimeException) {
        expect($diagnostic['reason'])->toBe('expression_not_literal')
            ->and($diagnostic['line'])->toBe(4)
            ->and($diagnostic['token_type'])->toBe('T_NAME_FULLY_QUALIFIED')
            ->and($diagnostic['next_token_types'])->toContain('T_DOUBLE_COLON', 'T_STRING')
            ->and(json_encode($diagnostic))->not->toContain('PRIVATE', 'password', 'SecretClass', '__set_state', 'unrelated', 'key');
    }
    MymarReadOnlyAudit::literalArray('<?php return [];', $diagnostic);
    expect($diagnostic)->toBeNull();
});

it('diagnoses a cache without executing it or reading environment values', function () {
    $root = sys_get_temp_dir().'/mymar-cache-diagnostic-'.bin2hex(random_bytes(8));
    mkdir($root.'/bootstrap/cache', 0700, true);
    try {
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return ["key" => file_put_contents("'.$root.'/NEVER_EXECUTED", "SECRET_VALUE")];');
        $report = MymarReadOnlyAudit::cacheDiagnostic($root);
        expect($report['readable_within_limits'])->toBeTrue()
            ->and($report['literal_decode'])->toBeFalse()
            ->and($report['syntax']['token_type'])->toBe('T_STRING')
            ->and(file_exists($root.'/NEVER_EXECUTED'))->toBeFalse()
            ->and(json_encode($report))->not->toContain('file_put_contents', 'SECRET_VALUE', 'NEVER_EXECUTED');
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return ["key" => "SECRET_VALUE"];');
        $report = MymarReadOnlyAudit::cacheDiagnostic($root);
        expect($report['literal_decode'])->toBeTrue()->and($report)->not->toHaveKey('syntax')
            ->and(json_encode($report))->not->toContain('SECRET_VALUE');
        unlink($root.'/bootstrap/cache/config.php');
        expect(MymarReadOnlyAudit::cacheDiagnostic($root))->toBe(['readable_within_limits' => false, 'literal_decode' => false]);
    } finally {
        if (is_file($root.'/bootstrap/cache/config.php')) {
            unlink($root.'/bootstrap/cache/config.php');
        }
        rmdir($root.'/bootstrap/cache');
        rmdir($root.'/bootstrap');
        rmdir($root);
    }
});

it('treats exported objects as opaque syntax without autoloading or invoking a class', function () {
    $source = <<<'PHP'
<?php return [
    'app' => ['timezone' => 'UTC'],
    'unrelated' => \MustNotAutoload\CachedConfig::__set_state(array(
        'secret' => 'NEVER_EXPORT_VALUE',
        'nested' => \MustNotAutoload\Nested::__set_state(['key' => 'NEVER_EXPORT_NESTED']),
    )),
];
PHP;
    $loaded = [];
    $autoload = function (string $class) use (&$loaded) {
        $loaded[] = $class;
    };
    spl_autoload_register($autoload);
    try {
        $diagnostic = null;
        $cache = MymarReadOnlyAudit::literalArray($source, $diagnostic, true);
        expect($cache['app']['timezone'])->toBe('UTC')
            ->and($cache['unrelated'])->toBeInstanceOf(stdClass::class)
            ->and(get_object_vars($cache['unrelated']))->toBe([])
            ->and($diagnostic)->toBe(['opaque_exported_objects' => 2])
            ->and($loaded)->toBe([])
            ->and(json_encode($cache))->not->toContain('NEVER_EXPORT', 'MustNotAutoload')
            ->and(fn () => MymarReadOnlyAudit::literalArray($source))->toThrow(RuntimeException::class);
    } finally {
        spl_autoload_unregister($autoload);
    }
});

it('still refuses every executable expression in opaque object mode', function (string $source) {
    $diagnostic = null;
    expect(fn () => MymarReadOnlyAudit::literalArray($source, $diagnostic, true))->toThrow(RuntimeException::class);
})->with([
    '<?php return ["x" => \Unknown\Factory::make(array())];',
    '<?php return ["x" => \Unknown\Factory::__set_state(env("SECRET"))];',
    '<?php return ["x" => \Unknown\Factory::__set_state(["key" => file_put_contents("/tmp/NEVER_EXECUTE", "bad")])];',
    '<?php return ["x" => \Unknown\Factory::__set_state([], system("whoami"))];',
    '<?php return ["x" => \Unknown\Factory::__set_state([])->run()];',
    '<?php return ["x" => \Unknown\Factory::__set_state([])]; include "/tmp/NEVER_INCLUDE";',
    '<?php return ["x" => new \Unknown\Factory()];',
    '<?php return ["x" => \Unknown\Factory::__set_state(["key" => $GLOBALS["SECRET"]])];',
    '<?php return ["x" => \Unknown\Factory::__set_state(["key" => fn () => true])];',
    '<?php app("router")->setCompiledRoutes(["database" => []]);',
]);

it('allows opaque values only outside the database configuration and selected timezone', function () {
    $root = sys_get_temp_dir().'/mymar-opaque-config-'.bin2hex(random_bytes(8));
    mkdir($root.'/bootstrap/cache', 0700, true);
    try {
        file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_HOST=localhost\nDB_DATABASE=MYMAR\nDB_PASSWORD=PRIVATE_ENV\n");
        $cache = ['app' => ['timezone' => 'UTC'], 'database' => ['default' => 'mysql', 'connections' => ['mysql' => [
            'driver' => 'mysql', 'host' => 'localhost', 'database' => 'MYMAR', 'password' => 'PRIVATE_PASSWORD',
        ]]], 'logging' => []];
        $object = '\\MustNotAutoload\\Config::__set_state(array("key" => "PRIVATE_TOKEN"))';
        $write = function (array $config) use ($root, $object): void {
            file_put_contents($root.'/bootstrap/cache/config.php', '<?php return '.str_replace("'OPAQUE_MARKER'", $object, var_export($config, true)).';');
        };
        $cache['logging']['unrelated'] = 'OPAQUE_MARKER';
        $cache['app']['aliases'] = 'OPAQUE_MARKER';
        $write($cache);
        $identity = MymarReadOnlyAudit::identity($root, 'MYMAR');
        expect($identity['sql_eligible'])->toBeTrue()
            ->and($identity['identity_basis'])->toBe('env_and_literal_database_cache_agree')
            ->and($identity['opaque_objects_outside_database_and_timezone_ignored'])->toBe(2)
            ->and($identity['cached_timezone'])->toBe('UTC')
            ->and($identity['runtime_configuration_confirmed'])->toBeFalse()
            ->and(json_encode($identity))->not->toContain('PRIVATE', 'MustNotAutoload');
        foreach (['password', 'host', 'options'] as $field) {
            $invalid = $cache;
            $invalid['database']['connections']['mysql'][$field] = 'OPAQUE_MARKER';
            $write($invalid);
            expect(MymarReadOnlyAudit::identity($root, 'MYMAR'))->toBe(['sql_eligible' => false, 'reason' => 'non_literal_database_or_timezone_configuration']);
        }
        $invalid = $cache;
        $invalid['app']['timezone'] = 'OPAQUE_MARKER';
        $write($invalid);
        expect(MymarReadOnlyAudit::identity($root, 'MYMAR')['sql_eligible'])->toBeFalse();
        $cache['database']['connections']['mysql']['host'] = 'remote.invalid';
        $write($cache);
        expect(MymarReadOnlyAudit::identity($root, 'MYMAR')['reason'])->toBe('cached_database_identity_disagrees');
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return ["x" => \\Unknown\\Factory::make([])];');
        $identity = MymarReadOnlyAudit::identity($root, 'MYMAR');
        expect($identity['sql_eligible'])->toBeFalse()
            ->and($identity['cache_syntax']['reason'])->toBe('unsupported_static_expression')
            ->and(json_encode($identity))->not->toContain('Unknown', 'Factory', 'make');
    } finally {
        unlink($root.'/bootstrap/cache/config.php');
        unlink($root.'/.env');
        rmdir($root.'/bootstrap/cache');
        rmdir($root.'/bootstrap');
        rmdir($root);
    }
});

it('extracts status flags real role IDs date formats and duration calls without leaking literals', function () {
    $source = <<<'PHP'
<?php
function jsonPlanning() {
    $key = 'NEVER_EXPORT_TOKEN';
    User::role(4)->where('enabled', 1);
    $query->whereIn('dossiers.status_id', [6, 12])->where('is_old', 0)->unique('dossier_id');
    $date = Carbon::parse('PRIVATE_DATE')->addMinutes(45)->format('Y-m-d H:i:s');
    $rdv->is_old = 1;
    $data = ['mar_type' => 2, 'password' => 'NEVER_EXPORT_PASSWORD'];
    User::hasRole('Technicien');
    User::role('private@example.test');
    User::role(fn () => secret('SMALL_SECRET_LITERAL', 1234));
    $query->where('enabled', function () { $password = 'DO_NOT_EXPORT_CLOSURE'; });
    $date->format('PRIVATE_OTHER_STRING');
}
PHP;
    $facts = MymarReadOnlyAudit::facts($source);
    $json = json_encode($facts);
    expect($json)->toContain('dossiers.status_id', '[6, 12]', 'addMinutes', '45', 'Y-m-d H:i:s', 'Technicien')
        ->not->toContain('NEVER_EXPORT_TOKEN', 'NEVER_EXPORT_PASSWORD', 'PRIVATE_DATE', 'private@example.test', 'PRIVATE_OTHER_STRING', 'SMALL_SECRET_LITERAL', '1234', 'DO_NOT_EXPORT_CLOSURE');
    expect(collect($facts)->firstWhere('field', 'is_old')['literal'])->toBe(1)
        ->and(collect($facts)->firstWhere('field', 'mar_type')['literal'])->toBe(2);
});

it('confirms database declarations and literal cache identity without publishing credentials', function () {
    $root = sys_get_temp_dir().'/mymar-identity-'.bin2hex(random_bytes(8));
    mkdir($root.'/bootstrap/cache', 0700, true);
    try {
        file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=MYMAR\nDB_PASSWORD=DO_NOT_EXPORT\nAPP_TIMEZONE=Europe/Paris\n");
        $cache = ['app' => ['timezone' => 'UTC'], 'database' => ['default' => 'mysql', 'connections' => ['mysql' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'MYMAR', 'password' => 'SECRET_CACHE_VALUE',
        ]]]];
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return '.var_export($cache, true).';');
        $identity = MymarReadOnlyAudit::identity($root, 'MYMAR');
        expect($identity['sql_eligible'])->toBeTrue()->and($identity['cached_timezone'])->toBe('UTC')
            ->and(json_encode($identity))->not->toContain('DO_NOT_EXPORT', 'SECRET_CACHE_VALUE', 'password');
        $cache['database']['connections']['mysql']['database'] = 'different_db';
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return '.var_export($cache, true).';');
        expect(MymarReadOnlyAudit::identity($root, 'MYMAR')['sql_eligible'])->toBeFalse();
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return file_put_contents("'.$root.'/NEVER_EXECUTED", "bad");');
        expect(MymarReadOnlyAudit::identity($root, 'MYMAR')['sql_eligible'])->toBeFalse()->and(file_exists($root.'/NEVER_EXECUTED'))->toBeFalse();
    } finally {
        unlink($root.'/bootstrap/cache/config.php');
        unlink($root.'/.env');
        rmdir($root.'/bootstrap/cache');
        rmdir($root.'/bootstrap');
        rmdir($root);
    }
});

it('refuses remote priority URLs duplicate declarations and unsafe source links', function () {
    $root = sys_get_temp_dir().'/mymar-declaration-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        foreach (['DB_HOST=remote.invalid', "DB_HOST=localhost\nDB_URL=sqlite:///other", "DB_HOST=localhost\nDB_DATABASE=MYMAR\nDB_DATABASE=OTHER", "DB_HOST=localhost\nDB_PORT=3307"] as $values) {
            file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_DATABASE=MYMAR\n".$values."\n");
            expect(MymarReadOnlyAudit::identity($root, 'MYMAR')['sql_eligible'])->toBeFalse();
        }
        symlink($root.'/.env', $root.'/linked.php');
        expect(MymarReadOnlyAudit::read($root, 'linked.php'))->toBeNull()
            ->and(MymarReadOnlyAudit::read($root, '../outside.php'))->toBeNull();
    } finally {
        unlink($root.'/linked.php');
        unlink($root.'/.env');
        rmdir($root);
    }
});

it('generates only fixed read-only transactions and a plain execution plan', function () {
    foreach ([MymarReadOnlyAudit::metadataSql(), MymarReadOnlyAudit::dataSql(), MymarReadOnlyAudit::explainSql()] as $sql) {
        expect($sql)->toContain('START TRANSACTION READ ONLY;', 'SET SESSION TRANSACTION READ ONLY;', 'ROLLBACK;', 'max_execution_time=1500')
            ->not->toMatch('/\b(?:INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|GRANT|REVOKE|CALL|INTO\s+OUTFILE|ANALYZE|FOR\s+UPDATE|SET\s+GLOBAL)\b/i');
        foreach (array_filter(array_map(trim(...), explode(';', $sql))) as $statement) {
            expect($statement)->toMatch('/^(?:SET SESSION|START TRANSACTION READ ONLY|SELECT|WITH candidate_ids AS|EXPLAIN SELECT|ROLLBACK)\b/');
        }
    }
    expect(MymarReadOnlyAudit::dataSql())->not->toContain('password', 'remember_token', 'clients.', 'dossier_documents', 'dossier_comments');
});

it('materializes technician candidates and duplicate counts without dependent user lookups', function () {
    $sql = MymarReadOnlyAudit::technicianSql();
    expect($sql)->toStartWith('WITH candidate_ids AS (')
        ->toContain('historical_counts AS (', 'email_candidates AS (', 'FROM candidate_ids c JOIN MYMAR.users u ON u.id=c.mymar_id')
        ->not->toContain('WHERE u.id IN (', '(SELECT COUNT(*) FROM MYMAR.users other')
        ->and(MymarReadOnlyAudit::dataSql())->toContain('max_execution_time=1500', 'START TRANSACTION READ ONLY;');
});

it('refuses an unexpected MySQL instance engine volume or missing column', function (string $failure) {
    $fields = ['MYMAR.status' => ['id', 'name', 'deleted_at'], 'MYMAR.roles' => ['id', 'name'],
        'MYMAR.model_has_roles' => ['role_id', 'model_id', 'model_type'], 'MYMAR.users' => ['id', 'name', 'email', 'enabled', 'cofrac_id'],
        'MYMAR.dossiers' => ['id', 'status_id', 'technicien_id', 'auditeur_id', 'mar_type'],
        'MYMAR.dossier_rdv' => ['id', 'dossier_id', 'date_rdv', 'duree', 'is_old', 'updated_at'],
        'cofrac2production.users' => ['id', 'email', 'first_name', 'last_name', 'role', 'deleted_at', 'external_client_id'],
        'cofrac2production.coffrac_user_links' => ['source_user_id', 'user_id']];
    $rows = [['section' => 'server', 'port' => 3306, 'socket' => '/var/run/mysqld/mysqld.sock', 'version' => '8.0.46']];
    foreach ($fields as $key => $columns) {
        [$schema, $table] = explode('.', $key);
        $rows[] = ['section' => 'table', 'schema' => $schema, 'table' => $table, 'engine' => 'InnoDB', 'estimated_rows' => 100];
        foreach ($columns as $column) {
            $rows[] = ['section' => 'column', 'schema' => $schema, 'table' => $table, 'column' => $column];
        }
    }
    MymarReadOnlyAudit::validateMetadata($rows);
    match ($failure) {
        'port' => $rows[0]['port'] = 3307,
        'version' => $rows[0]['version'] = '8.4.0',
        'engine' => $rows[1]['engine'] = 'MyISAM',
        'volume' => $rows[1]['estimated_rows'] = 100001,
        'column' => array_pop($rows),
    };
    expect(fn () => MymarReadOnlyAudit::validateMetadata($rows))->toThrow(RuntimeException::class);
})->with(['port', 'version', 'engine', 'volume', 'column']);
