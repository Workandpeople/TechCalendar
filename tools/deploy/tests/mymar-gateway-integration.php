<?php

declare(strict_types=1);

use App\Services\Mymar\MymarGatewayClient;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarReadWindow;
use Cofrac2\Deploy\Mymar\MymarGatewayConfig;
use Cofrac2\Deploy\Mymar\MymarReadOnlySource;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

if (PHP_SAPI !== 'cli' || __DIR__ !== '/workspace/tools/deploy/tests' || ! is_file('/.dockerenv')) {
    throw new RuntimeException('Disposable local Docker fixture only.');
}
require '/workspace/vendor/autoload.php';
require '/workspace/tools/deploy/mymar/gateway/MymarReadOnlySource.php';
require '/workspace/tools/deploy/mymar/gateway/MymarGatewayConfig.php';

function checkMymarGateway(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
checkMymarGateway($root->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='MYMAR'")->fetchColumn() === 0, 'Fixture database already exists.');
$root->exec('CREATE DATABASE MYMAR');
$root->exec('CREATE TABLE MYMAR.dossiers (id BIGINT PRIMARY KEY,status_id BIGINT,technicien_id BIGINT,private_name VARCHAR(100)) ENGINE=InnoDB');
$root->exec('CREATE TABLE MYMAR.dossier_rdv (id BIGINT PRIMARY KEY,dossier_id BIGINT,date_rdv DATETIME NULL,duree INT NULL,is_old INT,INDEX(dossier_id)) ENGINE=InnoDB');
$root->exec('CREATE TABLE MYMAR.users (id BIGINT PRIMARY KEY,password VARCHAR(100)) ENGINE=InnoDB');
$root->exec("INSERT INTO MYMAR.users VALUES (1,'SECRET_CANARY')");
$root->exec("INSERT INTO MYMAR.dossiers VALUES (91,16,26,'PRIVATE_BENEFICIARY'),(92,3,32,'PRIVATE_ADDRESS'),(93,20,26,'PRIVATE_EMAIL'),(94,16,99,'PRIVATE_OTHER_SCOPE')");
$root->exec("INSERT INTO MYMAR.dossier_rdv VALUES
    (1,91,'2026-11-02 09:30:00',1,0), (2,91,'2026-11-02 13:30:00',2,0),
    (3,91,'2026-11-02 15:30:00',1,1), (4,92,'2026-11-01 23:30:00',2,0),
    (5,93,'2026-11-02 10:00:00',1,0), (6,94,'2026-11-02 10:00:00',1,0),
    (7,92,NULL,1,0), (8,91,'2026-11-03 00:00:00',1,0), (9,91,'2026-11-01 00:00:00',1,0),
    (10,91,'2020-01-01 10:00:00',99,0), (11,92,'2026-10-25 02:30:00',1,0)");
$password = bin2hex(random_bytes(24));
$root->exec("CREATE USER 'cofrac_mymar_ro'@'localhost' IDENTIFIED BY '$password'");
$root->exec('GRANT SELECT (id,status_id,technicien_id) ON MYMAR.dossiers TO `cofrac_mymar_ro`@`localhost`');
$root->exec('GRANT SELECT (id,dossier_id,date_rdv,duree,is_old) ON MYMAR.dossier_rdv TO `cofrac_mymar_ro`@`localhost`');
$directory = '/tmp/mymar-gateway-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$path = $directory.'/config.json';
$config = ['enabled' => true, 'token' => bin2hex(random_bytes(32)), 'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours',
    'database_socket' => '/var/run/mysqld/mysqld.sock', 'database_user' => 'cofrac_mymar_ro', 'database_password' => $password];
$writeConfig = static function (array $configuration) use ($path): void {
    file_put_contents($path, json_encode($configuration, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
};
$writeConfig($config);
$reader = MymarGatewayConfig::database(MymarGatewayConfig::load($path));
$source = new MymarReadOnlySource($reader);
$window = new MymarReadWindow('2026-11-02T00:00:00+01:00', '2026-11-03T00:00:00+01:00', [26, 32]);
$policy = new MymarObservationPolicy;
$checksum = static fn (): array => $root->query('CHECKSUM TABLE MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.users')->fetchAll(PDO::FETCH_ASSOC);
$before = $checksum();
$rows = $source->fetch($window, $policy);
checkMymarGateway(array_column($rows, 'rdv_id') === [1, 2, 4, 7, 9, 10], 'Wrong MySQL appointment scope or boundary handling.');
checkMymarGateway(is_int($rows[0]['raw_duration']) && ! $reader->inTransaction(), 'Unexpected PDO typing or dangling transaction.');
foreach (['SELECT * FROM MYMAR.users', 'SELECT private_name FROM MYMAR.dossiers', 'UPDATE MYMAR.dossiers SET status_id=20 WHERE id=91'] as $denied) {
    try {
        $reader->query($denied);
        throw new RuntimeException('Column-only SQL account escaped its scope.');
    } catch (PDOException $exception) {
        checkMymarGateway(in_array($exception->errorInfo[1], [1142, 1143], true), 'Wrong SQL denial.');
    }
}
[$sql, $parameters] = MymarReadOnlySource::query($window, $policy);
$explain = $reader->prepare('EXPLAIN '.$sql);
$explain->execute($parameters);
checkMymarGateway(count($explain->fetchAll()) === 2, 'Unexpected query plan.');
$server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:19846', '/workspace/tools/deploy/mymar/gateway/serve.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/server.log', 'a'], 2 => ['file', $directory.'/server.log', 'a']],
    $pipes, '/tmp', ['MYMAR_GATEWAY_CONFIG' => $path, 'PATH' => '/usr/local/bin:/usr/bin:/bin']);
checkMymarGateway(is_resource($server), 'Fixture HTTP server did not start.');
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', 19846, $errno, $error, 0.05);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    checkMymarGateway($ready, 'Fixture HTTP server timed out.');
    $client = new MymarGatewayClient(new Factory, true, token: $config['token']);
    $report = $client->observe($window, $policy, new DateTimeImmutable('now'), [26 => 151, 32 => 152]);
    checkMymarGateway(array_column($report['candidates'], 'source_rdv_id') === [4, 1, 2], 'HTTP contract lost an overlapping or same-dossier appointment.');
    checkMymarGateway($report['candidates'][1]['starts_at'] === '2026-11-02T09:30:00+01:00'
        && $report['candidates'][2]['duration_minutes'] === 120 && $report['can_affect_planning'] === false, 'Unsafe time conversion or activation.');
    checkMymarGateway(in_array('invalid_wall_time', array_column($report['issues'], 'code'), true)
        && in_array('invalid_duration', array_column($report['issues'], 'code'), true), 'Invalid source values were hidden.');
    $request = static fn (): PendingRequest => (new Factory)->withToken($config['token'])->timeout(5)->withoutRedirecting()->withOptions(['proxy' => '']);
    foreach ([['POST', '/v1/occupations', [], 405], ['GET', '/.env', [], 404], ['GET', '/v1/occupations', ['Authorization' => 'Bearer WRONG'], 403],
        ['GET', '/v1/occupations', ['X-Forwarded-For' => '127.0.0.1'], 403], ['GET', '/v1/occupations', ['Host' => 'example.test'], 403]] as [$method, $route, $headers, $status]) {
        $response = $request()->withHeaders($headers)->send($method, 'http://127.0.0.1:19846'.$route);
        checkMymarGateway($response->status() === $status && $response->json('complete') === false, 'Unsafe HTTP endpoint accepted.');
        checkMymarGateway(! preg_match('/SECRET_CANARY|PRIVATE_|'.$password.'/', $response->body()), 'Private data leaked.');
    }
    checkMymarGateway($before === $checksum(), 'Source changed during observation.');

    // Comparable source volume, without a new date index. All reads retain the 1500 ms MySQL budget.
    $insert = $root->prepare('INSERT INTO MYMAR.dossier_rdv VALUES (?,?,?,1,0)');
    $root->beginTransaction();
    for ($i = 100; $i < 12100; $i++) {
        $insert->execute([$i, 91, '2020-01-01 10:00:00']);
    }
    $root->commit();
    $before = $checksum();
    $start = hrtime(true);
    for ($i = 0; $i < 10; $i++) {
        checkMymarGateway(count($source->fetch($window, $policy)) === 6, 'Load test changed source scope.');
    }
    $meanMs = round((hrtime(true) - $start) / 1_000_000 / 10, 2);
    checkMymarGateway($before === $checksum(), 'Load test changed source.');
    $root->exec("UPDATE MYMAR.dossier_rdv SET date_rdv='2026-11-02 10:00:00' WHERE id BETWEEN 100 AND 2100");
    try {
        $client->observe($window, $policy, new DateTimeImmutable('now'));
        throw new LogicException('Truncated result accepted.');
    } catch (RuntimeException $exception) {
        checkMymarGateway($exception->getMessage() === 'mymar.gateway_observation_failed', 'Unsafe oversized result.');
    }
    $writeConfig(array_replace($config, ['enabled' => false]));
    $response = $request()->get('http://127.0.0.1:19846/v1/occupations', $window->query());
    checkMymarGateway($response->status() === 503 && $response->json('error') === 'gateway_disabled', 'Gateway kill switch failed.');
    $writeConfig($config);
    $root->exec('GRANT SELECT ON MYMAR.users TO `cofrac_mymar_ro`@`localhost`');
    $response = $request()->get('http://127.0.0.1:19846/v1/occupations', $window->query());
    checkMymarGateway($response->status() === 503 && $response->json('complete') === false, 'Privilege drift was not rejected.');
    echo "MySQL 8.0.46 + HTTP local : contrat reel valide, SELECT par colonnes, source inchangee, erreurs/troncature/droits excessifs refuses.\n";
    echo "Charge synthetique : 12 011 RDV, moyenne de 10 lectures = $meanMs ms ; aucun acces au serveur reel.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
}
