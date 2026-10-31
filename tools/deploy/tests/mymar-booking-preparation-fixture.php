<?php

declare(strict_types=1);

require '/workspace/tools/deploy/mymar/MymarReadOnlyAudit.php';
require '/workspace/tools/deploy/mymar/workflow/MymarWorkflowAudit.php';
require '/workspace/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php';

use Cofrac2\Deploy\Mymar\MymarApprovedTechnicianMap;
use Cofrac2\Deploy\MymarWorkflowAudit;

umask(0077);
$database = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$state = '/var/lib/cofrac2-production';
$write = static fn ($path, $data) => file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

if ($argv[1] === 'setup') {
    $database->exec("DELETE FROM MYMAR.users WHERE id=99;
        ALTER TABLE cofrac2production.users ADD first_name varchar(255),ADD last_name varchar(255),ADD role int,ADD admin tinyint NOT NULL DEFAULT 0,ADD deleted_at timestamp NULL,ADD external_client_id bigint NULL;
        UPDATE cofrac2production.users SET first_name='Terrain',last_name='A',role=2 WHERE id=101;
        INSERT INTO cofrac2production.users (id,password,first_name,last_name,role) VALUES (102,'COFRAC_PASSWORD_CANARY','Terrain','B',2);
        CREATE USER 'debian-sys-maint'@'localhost' IDENTIFIED BY 'fixture-maintenance-only';
        GRANT ALL ON *.* TO 'debian-sys-maint'@'localhost' WITH GRANT OPTION;");
    foreach (['/home/ubuntu/www/MYMAR' => 'MYMAR', '/var/www/cofrac2' => 'cofrac2production'] as $root => $schema) {
        @mkdir($root.'/bootstrap/cache', 0700, true);
        file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=$schema\nDB_PASSWORD=ENV_SECRET_CANARY\n");
        $config = ['database' => ['default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => $schema, 'password' => 'CONFIG_SECRET_CANARY']]], 'app' => ['timezone' => 'Europe/Paris']];
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return '.var_export($config, true).';');
    }
    foreach (['routes/api.php', 'routes/web.php', 'app/Http/Kernel.php', 'app/Http/Controllers/Controller.php',
        'app/Http/Controllers/ApiController.php', 'app/Http/Controllers/DossierController.php', 'app/Models/Dossier.php',
        'app/Models/DossierRDV.php', 'app/Models/DossierHistorique.php', 'app/Models/Client.php', 'app/Models/Status.php',
        'app/Providers/AppServiceProvider.php', 'app/Providers/EventServiceProvider.php', 'app/Providers/AuthServiceProvider.php', 'app/Providers/RouteServiceProvider.php'] as $file) {
        @mkdir(dirname('/home/ubuntu/www/MYMAR/'.$file), 0700, true);
        file_put_contents('/home/ubuntu/www/MYMAR/'.$file, '<?php file_put_contents("/fixtures/APPLICATION_EXECUTED", "forbidden");');
    }
    @mkdir($state, 0700, true);
    $revision = str_repeat('a', 40);
    $write($state.'/RUNTIME-PREPARED.json', ['revision' => $revision]);
    $write($state.'/INTERNAL-SITE-OPEN.json', []);
    $approval = ['format' => 'cofrac.mymar-approved-identities', 'version' => 1, 'bindings' => [
        ['source_technician_id' => 5, 'source_name' => 'Terrain A', 'cofrac_user_id' => 101, 'cofrac_name' => 'Terrain A'],
        ['source_technician_id' => 6, 'source_name' => 'Terrain B', 'cofrac_user_id' => 102, 'cofrac_name' => 'Terrain B'],
    ]];
    $records = [];
    foreach (explode(";\n", MymarApprovedTechnicianMap::sql($approval)) as $sql) {
        if (trim($sql) === '') {
            continue;
        }
        $statement = $database->query($sql);
        if ($statement->columnCount() > 0) {
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $row) {
                $records[] = json_decode($row, true, 32, JSON_THROW_ON_ERROR);
            }
        }
    }
    $map = MymarApprovedTechnicianMap::validate($approval, $records, new DateTimeImmutable);
    $mapDirectory = $state.'/mymar-mapping-abcdef01';
    mkdir($mapDirectory.'/bundle', 0700, true);
    $write($mapDirectory.'/bundle/approved-identities.json', $approval);
    $map['approval_sha256'] = hash_file('sha256', $mapDirectory.'/bundle/approved-identities.json');
    $write($mapDirectory.'/approved-map.json', $map);
    $proof = array_map(fn ($file) => $file['sha256'], MymarWorkflowAudit::sources('/home/ubuntu/www/MYMAR')['files']);
    $write('/backend-package/REQUEST.json', ['format' => 'cofrac.mymar-booking-preparation-request', 'version' => 1,
        'runtime_revision' => $revision, 'technical_author_approved' => true, 'mapping_path' => $mapDirectory.'/approved-map.json',
        'mapping_sha256' => hash_file('sha256', $mapDirectory.'/approved-map.json'), 'workflow_files' => $proof]);
    file_put_contents('/etc/mysql/debian.cnf', "[client]\nuser=debian-sys-maint\npassword=fixture-maintenance-only\n");
    chmod('/etc/mysql/debian.cnf', 0600);
    exit;
}
$marker = json_decode(file_get_contents($state.'/MYMAR-BOOKING-PREPARED.json'), true, 16, JSON_THROW_ON_ERROR);
assert($marker['stage'] === 'prepared_disabled' && $marker['read_enabled'] === false && $marker['writes_enabled'] === false);
$run = $marker['run_directory'];
$summary = json_decode(file_get_contents($run.'/summary.json'), true, 16, JSON_THROW_ON_ERROR);
$config = json_decode(file_get_contents($run.'/config.private.json'), true, 16, JSON_THROW_ON_ERROR);
assert($summary['complete'] === true && $summary['journal_rows'] === 0 && $summary['pending_items_probed'] === 1);
assert($summary['technical_author_disabled'] === true && $summary['technical_author_has_roles'] === false);
foreach (['existing_accounts_modified', 'appointments_modified', 'dossiers_modified', 'histories_modified', 'read_enabled', 'writes_enabled', 'http_listener_started', 'services_changed', 'messages_sent'] as $key) {
    assert($summary[$key] === false);
}
$locked = $database->query("SELECT account_locked FROM mysql.user WHERE User='".$config['database_user']."'")->fetchColumn();
assert($locked === 'Y');
try {
    new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', $config['database_user'], $config['database_password']);
    throw new LogicException('Locked SQL account accepted a new login.');
} catch (PDOException) {
}
assert($config['read_enabled'] === false && $config['writes_enabled'] === false);
assert((fileperms($run.'/config.private.json') & 0777) === 0600 && fileowner($run.'/config.private.json') === 0);
assert((int) $database->query('SELECT enabled FROM MYMAR.users WHERE id='.(int) $config['technical_author_id'])->fetchColumn() === 0);
assert((int) $database->query('SELECT COUNT(*) FROM MYMAR.model_has_roles WHERE model_id='.(int) $config['technical_author_id'])->fetchColumn() === 0);
$reports = glob('/home/ubuntu/cofrac2-mymar-booking-preparation-report-*');
assert(count($reports) === 1);
foreach (glob($reports[0].'/*') as $file) {
    $data = file_get_contents($file);
    assert(! str_contains($data, $config['token']) && ! str_contains($data, $config['database_password'])
        && ! preg_match('/CANARY|BENEFICIARY|@example|"password"|"email"/', $data));
}
assert(! file_exists('/fixtures/APPLICATION_EXECUTED'));
echo "Production preparation fixture: dedicated disabled author, restricted locked SQL, empty journal, no personal data exported.\n";
