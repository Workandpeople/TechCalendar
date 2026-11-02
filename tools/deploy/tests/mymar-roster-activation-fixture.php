<?php

// Disposable Linux/MySQL fixture only. No production host or credentials.
declare(strict_types=1);

require '/workspace/vendor/autoload.php';
require '/workspace/tools/deploy/mymar/roster/MymarRosterActivation.php';
require '/workspace/tools/deploy/mymar/booking/MymarBookingSqlAccess.php';

use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess;
use Cofrac2\Deploy\Mymar\MymarRosterActivation as Activation;

function rosterFixtureWrite(string $path, string $body, int $mode = 0600, string $owner = 'root', ?string $group = null): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $body);
    chown($path, $owner);
    chgrp($path, $group ?? $owner);
    chmod($path, $mode);
}

function rosterFixtureJson(string $path, array $data, int $mode = 0600, string $owner = 'root', ?string $group = null): void
{
    rosterFixtureWrite($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n", $mode, $owner, $group);
}

if (($argv[1] ?? '') === 'setup') {
    $pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("ALTER TABLE cofrac2production.users ADD first_name varchar(100), ADD last_name varchar(200), ADD email varchar(200), ADD role int DEFAULT 2, ADD admin int DEFAULT 0, ADD deleted_at datetime NULL, ADD external_client_id bigint NULL, ADD latitude double DEFAULT 48.8, ADD longitude double DEFAULT 2.3;
        UPDATE cofrac2production.users SET first_name='Terrain',last_name='A',email='terrain-a@example.test' WHERE id=101;
        INSERT INTO cofrac2production.users (id,first_name,last_name,email) VALUES (102,'Bertrand','Ballekens','terrain-b@example.test'),(103,'Celine','Sauvetre','outside@example.test'),(104,'Orphan','Tech','orphan@example.test');
        UPDATE MYMAR.users SET name='Bertrand Ballekens' WHERE id=6;
        UPDATE MYMAR.users SET name='Celine' WHERE id=8;
        CREATE TABLE cofrac2production.services (id bigint PRIMARY KEY,type varchar(30),name varchar(200),retired_at datetime NULL,average_duration_minutes int) ENGINE=InnoDB;
        CREATE TABLE cofrac2production.service_user (user_id bigint,service_id bigint) ENGINE=InnoDB;
        CREATE TABLE cofrac2production.department_user (user_id bigint,department_code varchar(3)) ENGINE=InnoDB;
        INSERT INTO cofrac2production.services VALUES (10,'MAR','VISITE APRES TRAVAUX',NULL,60);
        INSERT INTO cofrac2production.service_user VALUES (101,10),(102,10),(103,10);
        INSERT INTO cofrac2production.department_user VALUES (101,'59'),(102,'59'),(103,'59');
        CREATE USER 'cofrac_app_fixture'@'localhost'; GRANT SELECT ON cofrac2production.* TO 'cofrac_app_fixture'@'localhost';");
    $token = str_repeat('0123456789abcdef', 4);
    $password = 'Mb3!'.str_repeat('a', 64);
    foreach ([true, false] as $readOnly) {
        $user = 'cofrac_mymar_'.($readOnly ? 'br' : 'rw').'_12345678';
        $pdo->exec(MymarBookingSqlAccess::createSql($user, $password, $readOnly));
        $pdo->exec("ALTER USER '$user'@'localhost' ACCOUNT UNLOCK");
    }
    $pdo->exec("CREATE USER 'cofrac_mymar_ro_test'@'localhost' IDENTIFIED BY '$password';
        GRANT SELECT(id,status_id,technicien_id) ON MYMAR.dossiers TO 'cofrac_mymar_ro_test'@'localhost';
        GRANT SELECT(id,dossier_id,date_rdv,duree,is_old) ON MYMAR.dossier_rdv TO 'cofrac_mymar_ro_test'@'localhost';");
    $bindings = [['source_technician_id' => 5, 'cofrac_user_id' => 101]];
    $gateway = ['version' => 1, 'read_enabled' => true, 'writes_enabled' => false, 'technical_author_id' => 99,
        'technician_ids' => [5], 'database_user' => 'cofrac_mymar_br_12345678', 'database_password' => $password, 'token' => $token];
    $configs = ['occupations' => ['technician_ids' => [5]],
        'reader' => ['format' => 'cofrac.mymar-booking-reader', ...$gateway],
        'writer' => ['format' => 'cofrac.mymar-actions-gateway', ...$gateway, 'writes_enabled' => true, 'database_user' => 'cofrac_mymar_rw_12345678'],
        'pilot' => ['format' => 'cofrac.mymar-observation-pilot', 'version' => 1, 'sync_enabled' => true, 'display_enabled' => true, 'blocking_enabled' => false,
            'generation' => str_repeat('a', 32), 'bindings' => $bindings],
        'client' => ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => true,
            'port' => 19848, 'generation' => '12345678-1234-4234-8234-123456789abc', 'bindings' => $bindings]];
    foreach (Activation::PATHS as $name => $path) {
        $group = match ($name) {
            'occupations' => 'cofrac-mymar', 'reader' => 'cofrac-mymar-booking', 'writer' => 'cofrac-mymar-actions', default => 'cofrac2-app'
        };
        $owner = $name === 'occupations' ? $group : 'root';
        rosterFixtureJson($path, $configs[$name], $name === 'occupations' ? 0600 : 0640, $owner, $group);
        chown(dirname($path), $owner);
        chgrp(dirname($path), $group);
        chmod(dirname($path), $name === 'occupations' ? 0700 : 0750);
    }
    rosterFixtureJson('/fixtures/original-configs.json', Activation::readFiles());
    rosterFixtureJson('/var/lib/cofrac-mymar-gateway/private/config.json', ['enabled' => true, 'token' => $token,
        'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours', 'database_socket' => '/var/run/mysqld/mysqld.sock',
        'database_user' => 'cofrac_mymar_ro_test', 'database_password' => $password], 0600, 'cofrac-mymar');
    foreach (['/etc/cofrac2-mymar/api-token', '/etc/cofrac2-mymar-actions/api-token', '/etc/cofrac2-mymar-booking/reader-token'] as $path) {
        rosterFixtureWrite($path, $token."\n", 0640, 'root', 'cofrac2-app');
        chgrp(dirname($path), 'cofrac2-app');
        chmod(dirname($path), 0750);
    }
    rosterFixtureJson('/etc/cofrac2-mymar-booking/reader.json', ['format' => 'cofrac.mymar-booking-reader-client', 'version' => 1,
        'read_enabled' => true, 'writes_enabled' => false, 'port' => 19847, 'generation' => '12345678-1234-4234-8234-123456789abc'], 0640, 'root', 'cofrac2-app');
    rosterFixtureJson('/package/confirmed-identities.json', ['bindings' => [['source_technician_id' => 6, 'source_name' => 'Bertrand Ballekens',
        'cofrac_user_id' => 102, 'cofrac_name' => 'Bertrand Ballekens']]]);
    $contract = [];
    foreach (['MymarActionSettings', 'MymarPilotConfiguration', 'MymarPilotSettings', 'MymarObservationSync', 'MymarCachedObservation',
        'MymarPendingBookings', 'MymarBookingReaderSettings', 'MymarSlotGuard', 'MymarBookingPlanning'] as $class) {
        $path = 'app/Services/Mymar/'.$class.'.php';
        $contract[$path] = hash_file('sha256', '/var/www/cofrac2/'.$path);
    }
    rosterFixtureJson('/package/REQUEST.json', ['format' => 'cofrac.mymar-roster-activation', 'version' => 1, 'runtime_contract' => $contract]);
    $env = "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nCRM_V2_ENABLED=true\nCACHE_STORE=file\nMAIL_MAILER=array\nQUEUE_CONNECTION=sync\n"
        ."DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=cofrac2production\nDB_SOCKET=/var/run/mysqld/mysqld.sock\nDB_USERNAME=cofrac_app_fixture\nDB_PASSWORD=\n";
    rosterFixtureWrite('/var/www/cofrac2/.env', $env, 0640, 'root', 'cofrac2-app');
    rosterFixtureWrite('/home/ubuntu/www/MYMAR/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=MYMAR\nDB_PASSWORD=CANARY\n");
    rosterFixtureWrite('/home/ubuntu/www/MYMAR/artisan', '<?php file_put_contents("/fixtures/FORBIDDEN", "MYMAR bootstrapped");');
    rosterFixtureWrite('/var/www/cofrac2/bootstrap/providers.php', '<?php return [];', 0644);
    rosterFixtureWrite('/var/www/cofrac2/bootstrap/app.php', '<?php return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))->withExceptions()->create();', 0644);
    exit;
}

$original = json_decode(file_get_contents('/fixtures/original-configs.json'), true, 32, JSON_THROW_ON_ERROR);
if (($argv[1] ?? '') === 'reset') {
    foreach (Activation::PATHS as $name => $path) {
        $current = Activation::readFiles();
        Activation::replace($name, $current[$name]['sha256'], $original[$name]['content'], $original[$name]);
    }
} elseif (($argv[1] ?? '') === 'verify-original') {
    assert(Activation::readFiles() === $original);
} elseif (($argv[1] ?? '') === 'verify-expanded') {
    $current = Activation::readFiles();
    $expected = Activation::prepare(array_map(fn ($f) => json_decode($f['content'], true, 16, JSON_THROW_ON_ERROR), $original),
        [['source_technician_id' => 5, 'cofrac_user_id' => 101], ['source_technician_id' => 6, 'cofrac_user_id' => 102], ['source_technician_id' => 8, 'cofrac_user_id' => 103]]);
    foreach (Activation::PATHS as $name => $path) {
        assert(json_decode($current[$name]['content'], true, 16, JSON_THROW_ON_ERROR) === $expected[$name]);
        foreach (['uid', 'gid', 'mode'] as $field) {
            assert($current[$name][$field] === $original[$name][$field]);
        }
    }
} else {
    throw new RuntimeException('fixture.unknown_phase');
}
