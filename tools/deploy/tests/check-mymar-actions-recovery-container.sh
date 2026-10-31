#!/usr/bin/env bash
# Only runs inside the disposable, networkless writer fixture, before any action.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] || exit 1
RUN=$1
[[ "$RUN" =~ ^/var/lib/cofrac2-production/mymar-actions-[A-Za-z0-9]+$ ]] || exit 1
mkdir -m 0700 "$RUN/bundle"
cp /actions-package/*.php /actions-package/REQUEST.json "$RUN/bundle/"
chmod 0600 "$RUN/bundle/"*
RECOVERY=$(mktemp -d /var/lib/cofrac2-production/mymar-actions-recovery-XXXXXXXX)
mkdir -m 0700 "$RECOVERY/bundle"
cp /workspace/tools/deploy/mymar/actions-recovery/repair.php /workspace/tools/deploy/mymar/actions/MymarActionsConfiguration.php "$RECOVERY/bundle/"
chmod 0600 "$RECOVERY/bundle/"*
ln -s /workspace/vendor /var/www/cofrac2/vendor
mysql --defaults-file=/etc/mysql/debian.cnf -e 'CREATE TABLE cofrac2production.mymar_booking_requests (id bigint primary key);'
php -d zend.assertions=1 -d assert.exception=1 -- "$RUN" "$RECOVERY" <<'PHP'
<?php
umask(0077);
[$script,$run,$recovery]=$argv;
$state='/var/lib/cofrac2-production';
$marker=json_decode(file_get_contents($state.'/MYMAR-BOOKING-PREPARED.json'),true,16,JSON_THROW_ON_ERROR);
$prepared=json_decode(file_get_contents($marker['run_directory'].'/config.private.json'),true,16,JSON_THROW_ON_ERROR);
$configPath='/etc/cofrac2-mymar-actions/actions.json';
$config=json_decode(file_get_contents($configPath),true,16,JSON_THROW_ON_ERROR);
$config['bindings']=$prepared['bindings'];
assert(count($config['bindings'][0])===4);
file_put_contents($configPath,json_encode($config,JSON_THROW_ON_ERROR));
file_put_contents($run.'/application-probe.json',"{\"ready\":false}\n");
$hashes=[];
foreach(glob($run.'/bundle/*')as$path){$hashes[basename($path)]=hash_file('sha256',$path);}
$runtime=json_decode(file_get_contents($state.'/RUNTIME-PREPARED.json'),true,16,JSON_THROW_ON_ERROR);
$request=['format'=>'cofrac.mymar-actions-recovery-request','version'=>1,'runtime_revision'=>$runtime['revision'],
 'original_run'=>$run,'expected_technicians'=>2,'original_bundle'=>$hashes];
file_put_contents($recovery.'/bundle/REQUEST.valid.json',json_encode($request,JSON_THROW_ON_ERROR));
$request['original_bundle']['control.php']=str_repeat('0',64);
file_put_contents($recovery.'/bundle/REQUEST.json',json_encode($request,JSON_THROW_ON_ERROR));
PHP
# The real application rejects the old installed format.
if runuser -u cofrac2-app -- php <<'PHP'
<?php
require '/workspace/vendor/autoload.php';
try{(new App\Services\Mymar\MymarActionSettings)->load();exit(1);}
catch(RuntimeException $error){exit($error->getMessage()==='mymar.invalid_actions_mapping'?0:1);}
PHP
then :; else exit 1; fi
# A mismatched original bundle must fail before the configuration is changed.
sha256sum /etc/cofrac2-mymar-actions/actions.json > /fixtures/recovery-before.sha256
if php8.4 "$RECOVERY/bundle/repair.php" "$RECOVERY" > /fixtures/recovery-refused.log 2>&1; then exit 1; fi
sha256sum -c /fixtures/recovery-before.sha256
test ! -e "$RECOVERY/ownership.json"
cp "$RECOVERY/bundle/REQUEST.valid.json" "$RECOVERY/bundle/REQUEST.json"
php8.4 "$RECOVERY/bundle/repair.php" "$RECOVERY" > /fixtures/recovery.log 2>&1
runuser -u cofrac2-app -- php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
require '/workspace/vendor/autoload.php';
$config=(new App\Services\Mymar\MymarActionSettings)->load();
assert($config['bindings']===[['source_technician_id'=>5,'cofrac_user_id'=>101],['source_technician_id'=>6,'cofrac_user_id'=>102]]);
assert($config['guard_enabled']===true && $config['writes_enabled']===false);
PHP
php -d zend.assertions=1 -d assert.exception=1 -- "$RECOVERY" <<'PHP'
<?php
$run=$argv[1];
$before=json_decode(file_get_contents($run.'/before-actions.json'),true,16,JSON_THROW_ON_ERROR);
assert(count($before['bindings'][0])===4);
$repaired=json_decode(file_get_contents($run.'/repair.json'),true,16,JSON_THROW_ON_ERROR);
assert($repaired['bindings']===2 && $repaired['business_writes']===false && $repaired['writes_enabled']===false);
$source=json_decode(file_get_contents('/var/lib/cofrac-mymar-actions-gateway/private/config.json'),true,16,JSON_THROW_ON_ERROR);
$pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock','root','');
$query=$pdo->prepare('SELECT account_locked FROM mysql.user WHERE User=?');$query->execute([$source['database_user']]);assert($query->fetchColumn()==='Y');
assert((int)$pdo->query('SELECT COUNT(*) FROM cofrac_mymar_integration.booking_commands')->fetchColumn()===0);
assert((int)$pdo->query('SELECT COUNT(*) FROM cofrac2production.mymar_booking_requests')->fetchColumn()===0);
assert($source['writes_enabled']===false);
PHP
printf 'Real installed mapping rejection, pinned recovery, runtime-user readability and locked/empty journals verified.\n'
