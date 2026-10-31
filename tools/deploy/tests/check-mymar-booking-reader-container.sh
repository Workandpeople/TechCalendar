#!/usr/bin/env bash
# Invoked only inside the disposable booking/preparation MySQL fixture.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ "$(hostname -s)" = ns365601 ] && [ -f /backend-package/REQUEST.json ] \
    && [ -f /var/lib/cofrac2-production/MYMAR-BOOKING-PREPARED.json ] || exit 1
trap 'printf "READER_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2; tail -25 /fixtures/reader.log /fixtures/reader-{http,fpm}.log /var/log/cofrac-mymar-booking-gateway/*.log 2>/dev/null || true' ERR
mkdir -m 700 /reader-package
cp /workspace/tools/deploy/mymar/booking-http/* /reader-package/
cp /workspace/tools/deploy/mymar/booking/MymarBooking*.php /reader-package/
cp /workspace/tools/deploy/mymar/runtime/{MymarRuntimePlan,MymarRuntimeState}.php /reader-package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /reader-package/
cp /workspace/tools/deploy/mymar/workflow/MymarWorkflowAudit.php /reader-package/
cp /workspace/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php /reader-package/
cp /workspace/app/Services/Mymar/{MymarBookingCommand,MymarObservation,MymarObservationPolicy,MymarPendingBookingPage}.php /reader-package/
php <<'PHP'
<?php
$state='/var/lib/cofrac2-production';
$marker=json_decode(file_get_contents($state.'/MYMAR-BOOKING-PREPARED.json'),true,16,JSON_THROW_ON_ERROR);
$run=$marker['run_directory'];
$runtime=json_decode(file_get_contents($state.'/RUNTIME-PREPARED.json'),true,16,JSON_THROW_ON_ERROR);
file_put_contents('/reader-package/REQUEST.json',json_encode(['format'=>'cofrac.mymar-booking-http-request','version'=>1,
 'runtime_revision'=>$runtime['revision'],'preparation_directory'=>$run,'preparation_summary_sha256'=>hash_file('sha256',$run.'/summary.json')],JSON_THROW_ON_ERROR));
PHP
cp /workspace/tools/deploy/tests/mymar-booking-reader-systemctl-fixture.sh /usr/bin/systemctl
chmod 700 /usr/bin/systemctl
(cd /reader-package; chmod 600 *; sha256sum * > SHA256SUMS)
cat > /fixtures/original-api.php <<'PHP'
<?php
$server=stream_socket_server('tcp://127.0.0.1:19846');
while($client=stream_socket_accept($server,120)){
 stream_set_timeout($client,2);
 while(($line=fgets($client))!==false&&trim($line)!==''){}
 fwrite($client,"HTTP/1.1 200 OK\r\nContent-Length: 12\r\nConnection: close\r\n\r\nORIGINAL_API");fclose($client);
}
PHP
php /fixtures/original-api.php > /fixtures/original-api.log 2>&1 &
OLD_PID=$!
sleep 0.2
original_api() { php -r '$c=curl_init("http://127.0.0.1:19846/");curl_setopt_array($c,[CURLOPT_PROXY=>"",CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>2]);if(curl_exec($c)!=="ORIGINAL_API")exit(1);'; }
original_api
snapshot() {
    mysql --defaults-file=/etc/mysql/debian.cnf -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.clients,MYMAR.roles,MYMAR.model_has_roles,MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_historiques,MYMAR.status,cofrac2production.users,cofrac_mymar_integration.booking_commands;'
}
snapshot > /fixtures/reader-business.before
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/reader-apps.before
cp /reader-package/REQUEST.json /fixtures/reader-request.valid
php -r '$p="/reader-package/REQUEST.json";$q=json_decode(file_get_contents($p),true);$q["runtime_revision"]=str_repeat("b",40);file_put_contents($p,json_encode($q,JSON_THROW_ON_ERROR));'
(cd /reader-package; sha256sum *.php *.sh *.txt REQUEST.json > SHA256SUMS)
if (cd /reader-package && bash install.sh --install-reader-only > /fixtures/reader-wrong-release.log 2>&1); then exit 1; fi
test "$(mysql --defaults-file=/etc/mysql/debian.cnf -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_br_%'")" = 0
if getent passwd cofrac-mymar-booking >/dev/null; then exit 1; fi
cp /fixtures/reader-request.valid /reader-package/REQUEST.json
(cd /reader-package; sha256sum *.php *.sh *.txt REQUEST.json > SHA256SUMS)
if [ "${MYMAR_BOOKING_READER_FAILSTART:-}" = 1 ]; then
    touch /fixtures/reader-failstart
    if (cd /reader-package && bash install.sh --install-reader-only > /fixtures/reader.log 2>&1); then exit 1; fi
    test "$(mysql --defaults-file=/etc/mysql/debian.cnf -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_br_%' AND account_locked='Y'")" = 1
    test ! -e /var/lib/cofrac2-production/MYMAR-BOOKING-HTTP.json
    grep -q stopped /fixtures/reader-unit-actions
else
    (cd /reader-package && bash install.sh --install-reader-only > /fixtures/reader.log 2>&1)
    php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$reports=glob('/home/ubuntu/cofrac2-mymar-booking-http-report-*');assert(count($reports)===1);
$summary=json_decode(file_get_contents($reports[0].'/summary.json'),true,32,JSON_THROW_ON_ERROR);
assert($summary['complete']===true && $summary['read_enabled']===true && $summary['writes_enabled']===false);
assert($summary['prepared_writer_still_locked']===true && $summary['reader_account_locked']===false && $summary['new_reader_select_only']===true);
assert($summary['cofrac_client_enabled']===false && $summary['can_affect_planning']===false && $summary['pending_items_probed']===1);
assert($summary['post_refused']===true && $summary['receipt_lookup_tested']===true && $summary['validated_links']===2);
$path='/var/lib/cofrac-mymar-booking-gateway/private/config.json';$config=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
assert(fileowner($path)===0 && (fileperms($path)&0777)===0640);
$user=posix_getpwnam('cofrac-mymar-booking');assert(filegroup($path)===$user['gid']);
foreach(glob($reports[0].'/*')as$file){$body=file_get_contents($file);assert(!str_contains($body,$config['token'])&&!str_contains($body,$config['database_password'])&&!preg_match('/CANARY|BENEFICIARY|@example|"email"|"password"/',$body));}
$pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR',$config['database_user'],$config['database_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['UPDATE MYMAR.dossiers SET status_id=3 WHERE id=10','SELECT password FROM MYMAR.users','DELETE FROM cofrac_mymar_integration.booking_commands']as$sql){try{$pdo->exec($sql);throw new LogicException('Unexpected SQL permission');}catch(PDOException $error){assert(in_array($error->errorInfo[1],[1142,1143],true));}}
$curl=curl_init('http://127.0.0.1:19847/v1/pending-bookings?limit=1');curl_setopt_array($curl,[CURLOPT_PROXY=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['token']],CURLOPT_TIMEOUT=>5]);
// Changing a local feature flag must not turn this reader into a write-capable service.
$config['writes_enabled']=true;file_put_contents($path,json_encode($config,JSON_THROW_ON_ERROR));
$response=json_decode(curl_exec($curl),true,16,JSON_THROW_ON_ERROR);assert(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===503 && $response['error']==='booking_reader_unavailable');
$config['writes_enabled']=false;file_put_contents($path,json_encode($config,JSON_THROW_ON_ERROR));
assert(!file_exists('/fixtures/FORBIDDEN_CALL')&&!file_exists('/fixtures/APPLICATION_EXECUTED'));
echo "Reader HTTP: real Apache/FPM/MySQL, SELECT-only permissions, POST refused, secrets private, old application untouched.\n";
PHP
    bash /workspace/tools/deploy/tests/check-mymar-booking-ui-container.sh
    if (cd /reader-package && bash install.sh --install-reader-only > /fixtures/reader-repeat.log 2>&1); then exit 1; fi
    RUN=$(php -r '$r=json_decode(file_get_contents("/var/lib/cofrac2-production/MYMAR-BOOKING-HTTP.json"),true);echo $r["run_directory"];')
    # Cleanup cannot depend on the continuing validity of the original request or mapping.
    cp /reader-package/REQUEST.json /fixtures/reader-request.before
    printf '{}\n' > "$RUN/bundle/REQUEST.json"
    # Keep the sealed manifest consistent: this simulates a later obsolete request, not a corrupted package.
    (cd "$RUN/bundle"; sha256sum *.php *.sh *.txt REQUEST.json > SHA256SUMS)
    bash "$RUN/bundle/stop.sh" > /fixtures/reader-stop.log 2>&1
    test "$(mysql --defaults-file=/etc/mysql/debian.cnf -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_br_%' AND account_locked='Y'")" = 1
fi
for _ in $(seq 1 30); do [ -n "$(ss -H -ltn 'sport = :19847')" ] || break; sleep 0.1; done
test -z "$(ss -H -ltn 'sport = :19847')"
snapshot > /fixtures/reader-business.after
cmp /fixtures/reader-business.before /fixtures/reader-business.after
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/reader-apps.after
cmp /fixtures/reader-apps.before /fixtures/reader-apps.after
original_api; kill -0 "$OLD_PID"
php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$state='/var/lib/cofrac2-production';$prep=json_decode(file_get_contents($state.'/MYMAR-BOOKING-PREPARED.json'),true,16,JSON_THROW_ON_ERROR);
$config=json_decode(file_get_contents($prep['run_directory'].'/config.private.json'),true,16,JSON_THROW_ON_ERROR);
$db=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock','root','');
$q=$db->prepare('SELECT account_locked FROM mysql.user WHERE User=?');$q->execute([$config['database_user']]);assert($q->fetchColumn()==='Y');
assert(!file_exists('/fixtures/FORBIDDEN_CALL')&&!file_exists('/fixtures/APPLICATION_EXECUTED'));
PHP
echo 'Wrong release refused before resource creation; reader installation/stop checked; writer and business/source/occupation API unchanged.'
