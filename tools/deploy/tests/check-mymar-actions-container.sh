#!/usr/bin/env bash
# Runs after the read-only fixture checks, only in its disposable MySQL container.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ -d /reader-package ] && [ -f /fixtures/reader-business.after ] || exit 1
mkdir -m 0700 /actions-package
cp /workspace/tools/deploy/mymar/actions/*.php /actions-package/
cp /workspace/tools/deploy/mymar/runtime/{MymarRuntimeState,MymarRuntimePlan}.php /actions-package/
cp /workspace/tools/deploy/mymar/booking/MymarBooking*.php /actions-package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /workspace/tools/deploy/mymar/workflow/MymarWorkflowAudit.php /workspace/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php /actions-package/
cp /workspace/app/Services/Mymar/{MymarBookingCommand,MymarObservation,MymarObservationPolicy}.php /actions-package/
RUN=$(mktemp -d /var/lib/cofrac2-production/mymar-actions-XXXXXXXX)
php <<'PHP'
<?php
umask(0077);
$state='/var/lib/cofrac2-production';$ui=json_decode(file_get_contents($state.'/MYMAR-BOOKING-UI.json'),true,16,JSON_THROW_ON_ERROR);
$runtime=json_decode(file_get_contents($state.'/RUNTIME-PREPARED.json'),true,16,JSON_THROW_ON_ERROR);
file_put_contents('/actions-package/REQUEST.json',json_encode(['format'=>'cofrac.mymar-actions-request','version'=>1,'base_revision'=>$runtime['revision'],'revision'=>$runtime['revision'],
 'ui_summary_sha256'=>hash_file('sha256',$ui['run_directory'].'/summary.json')],JSON_THROW_ON_ERROR));
PHP
chmod 0600 /actions-package/*
php8.4 /actions-package/control.php --check "$RUN"
useradd --system --user-group -M --shell /usr/sbin/nologin cofrac-mymar-actions
php8.4 /actions-package/control.php --stage "$RUN"
mkdir -p /run/cofrac-mymar-actions-fpm /run/cofrac-mymar-actions-http
php-fpm8.4 --test --fpm-config /etc/cofrac-mymar-actions-gateway/php-fpm.conf > /fixtures/actions-fpm-test.log 2>&1
apache2 -t -f /etc/cofrac-mymar-actions-gateway/apache.conf > /fixtures/actions-apache-test.log 2>&1
systemd-analyze verify "$RUN/cofrac-mymar-actions-fpm.service" "$RUN/cofrac-mymar-actions-http.service" > /fixtures/actions-unit-test.log 2>&1
php8.4 /actions-package/control.php --app "$RUN"
# Exercise the installed client parser, not just a hand-written ready=true proof.
runuser -u cofrac2-app -- php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
require '/workspace/vendor/autoload.php';
$config=(new App\Services\Mymar\MymarActionSettings)->load();
assert(count($config['bindings'])===2 && $config['writes_enabled']===false && $config['guard_enabled']===true);
foreach($config['bindings']as$binding){assert(array_keys($binding)===['source_technician_id','cofrac_user_id']);}
assert(!str_contains(json_encode($config,JSON_THROW_ON_ERROR),'identity_sha256'));
PHP
bash /workspace/tools/deploy/tests/check-mymar-actions-recovery-container.sh "$RUN"
php8.4 /actions-package/control.php --unlock "$RUN"
mkdir -p /run/cofrac-mymar-actions-fpm /run/cofrac-mymar-actions-http
php-fpm8.4 --nodaemonize --fpm-config /etc/cofrac-mymar-actions-gateway/php-fpm.conf > /fixtures/actions-fpm.log 2>&1 &
FPM=$!
for _ in $(seq 1 30); do [ ! -S /run/cofrac-mymar-actions-fpm/php.sock ] || break; sleep 0.1; done
apache2 -f /etc/cofrac-mymar-actions-gateway/apache.conf -DFOREGROUND > /fixtures/actions-http.log 2>&1 &
HTTP=$!
trap 'kill -TERM "$HTTP" "$FPM" 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do [ -z "$(ss -H -ltn 'sport = :19848')" ] || break; sleep 0.1; done
php8.4 /actions-package/control.php --probe "$RUN"
php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$config=json_decode(file_get_contents('/var/lib/cofrac-mymar-actions-gateway/private/config.json'),true,16,JSON_THROW_ON_ERROR);
$curl=curl_init('http://127.0.0.1:19848/v1/booking-commands');
curl_setopt_array($curl,[CURLOPT_PROXY=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{}',CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['token'],'Content-Type: application/json']]);
curl_exec($curl);assert(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===422);
PHP
printf '{"ready":true,"mapped_technicians":2,"post_requests":false}\n' > "$RUN/application-probe.json"
chmod 0600 "$RUN/application-probe.json"
php8.4 /actions-package/control.php --enable "$RUN"
php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
require '/workspace/vendor/autoload.php';
require '/actions-package/MymarBookingSqlAccess.php';require '/actions-package/MymarBookingFailure.php';require '/actions-package/MymarBookingSource.php';
$config=json_decode(file_get_contents('/var/lib/cofrac-mymar-actions-gateway/private/config.json'),true,16,JSON_THROW_ON_ERROR);
$pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR',$config['database_user'],$config['database_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$source=new Cofrac2\Deploy\Mymar\MymarBookingSource($pdo,$config['technical_author_id'],$config['technician_ids'],enabled:true,writesEnabled:true);
$items=$source->pending(0,100)['items'];$first=$items[0];$second=$items[1];
$client=new App\Services\Mymar\MymarBookingClient(new Illuminate\Http\Client\Factory,enabled:true,writesEnabled:true,port:19848,token:$config['token']);
$make=fn($item,$action,$extra)=>App\Services\Mymar\MymarBookingCommand::fromArray(['format'=>'cofrac.mymar-booking-command','version'=>1,'request_id'=>Illuminate\Support\Str::uuid()->toString(),
 'action'=>$action,'dossier_id'=>$item['dossier_id'],'expected_revision'=>$item['expected_revision'],'cofrac_actor_id'=>101,...$extra]);
$place=$make($first,'place',['technician_id'=>5,'starts_at'=>'2030-11-05T10:00:00+01:00','duration_minutes'=>60]);
$result=$client->submit($place);assert($result->state==='confirmed');
$repeated=$client->submit($place);assert($repeated->state==='confirmed'&&$repeated->result===$result->result);
$problem=$make($second,'problem',['comment'=>'Synthetic scheduling issue','reason'=>'Callback','recall_at'=>null]);
assert($client->submit($problem)->state==='confirmed');
$root=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock','root','');
$reader=json_decode(file_get_contents('/var/lib/cofrac-mymar-booking-gateway/private/config.json'),true,16,JSON_THROW_ON_ERROR);
assert(preg_match('/^cofrac_mymar_br_[a-f0-9]{8}$/D',$reader['database_user'])===1);
$root->exec("ALTER USER '".$reader['database_user']."'@'localhost' ACCOUNT UNLOCK");
$readerPdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR',$reader['database_user'],$reader['database_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$receipts=new Cofrac2\Deploy\Mymar\MymarBookingSource($readerPdo,$reader['technical_author_id'],$reader['technician_ids'],enabled:true,readOnlyCredentials:true);
assert($receipts->result($place->payload['request_id'])===$result->result);
$root->exec("ALTER USER '".$reader['database_user']."'@'localhost' ACCOUNT LOCK");
assert((int)$root->query('SELECT COUNT(*) FROM cofrac_mymar_integration.booking_commands')->fetchColumn()===2);
assert((int)$root->query('SELECT COUNT(*) FROM MYMAR.dossier_historiques')->fetchColumn()===2);
assert((int)$root->query('SELECT COUNT(*) FROM MYMAR.users WHERE id='.$config['technical_author_id'].' AND enabled=0')->fetchColumn()===1);
assert(!str_contains(file_get_contents('/etc/cofrac2-mymar-actions/actions.json'),$config['database_password']));
file_put_contents('/fixtures/actions-secret-check.json',json_encode(['token'=>$config['token'],'password'=>$config['database_password']],JSON_THROW_ON_ERROR));
echo "Writer HTTP: confirmed place/problem, identical replay, source history, disabled technical author verified.\n";
PHP
# Shutdown remains possible with an obsolete request; it keeps reservation protection on.
printf '{}\n' > /actions-package/REQUEST.json
php8.4 /actions-package/control.php --disable "$RUN"
kill -TERM "$HTTP" "$FPM"; wait "$HTTP" 2>/dev/null || true; wait "$FPM" 2>/dev/null || true
php8.4 /actions-package/control.php --lock "$RUN"
php -d zend.assertions=1 -d assert.exception=1 -r '
$config=json_decode(file_get_contents("/etc/cofrac2-mymar-actions/actions.json"),true);assert($config["writes_enabled"]===false&&$config["guard_enabled"]===true);
$marker=json_decode(file_get_contents("/var/lib/cofrac2-production/MYMAR-ACTIONS.json"),true);assert($marker["writes_enabled"]===false&&$marker["stage"]==="pilot_actions_disabled");
$secret=json_decode(file_get_contents("/fixtures/actions-secret-check.json"),true);$report=file_get_contents($argv[1]."/summary.json");assert(!str_contains($report,$secret["token"])&&!str_contains($report,$secret["password"]));
$p=json_decode(file_get_contents($argv[1]."/ownership.private.json"),true);$db=new PDO("mysql:unix_socket=/var/run/mysqld/mysqld.sock","root","");$q=$db->prepare("SELECT account_locked FROM mysql.user WHERE User=?");$q->execute([$p["database_user"]]);assert($q->fetchColumn()==="Y");
' "$RUN"
printf 'Writer pilot activation and independent stop checked in disposable fixture; existing code and users not modified.\n'
