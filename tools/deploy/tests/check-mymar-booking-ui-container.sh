#!/usr/bin/env bash
# Only inside the existing disposable SELECT-only gateway fixture; never on production.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ -d /reader-package ] && [ -f /fixtures/reader-business.before ] || exit 1
mkdir -m 0700 /ui-package
cp /workspace/tools/deploy/mymar/booking-ui/control.php /ui-package/
cp /workspace/tools/deploy/mymar/runtime/MymarRuntimeState.php /ui-package/
cp /workspace/app/Services/Mymar/MymarPendingBookingPage.php /ui-package/
useradd --system --user-group -M --shell /usr/sbin/nologin cofrac2-app
RUN=$(mktemp -d /var/lib/cofrac2-production/mymar-booking-ui-XXXXXXXX)
php <<'PHP'
<?php
umask(0077);
$state='/var/lib/cofrac2-production';
$reader=json_decode(file_get_contents($state.'/MYMAR-BOOKING-HTTP.json'),true,16,JSON_THROW_ON_ERROR);
$runtime=json_decode(file_get_contents($state.'/RUNTIME-PREPARED.json'),true,16,JSON_THROW_ON_ERROR);
file_put_contents('/ui-package/REQUEST.json',json_encode(['format'=>'cofrac.mymar-booking-ui-request','version'=>1,
 'revision'=>$runtime['revision'],'base_revision'=>$runtime['revision'],'reader_directory'=>$reader['run_directory'],
 'reader_summary_sha256'=>hash_file('sha256',$reader['run_directory'].'/summary.json')],JSON_THROW_ON_ERROR));
PHP
chmod 0600 /ui-package/*
# Reject wrong release, altered source proof, and an unlocked writer before runtime metadata exists.
cp /ui-package/REQUEST.json /fixtures/ui-request.valid
php -r '$p="/ui-package/REQUEST.json";$r=json_decode(file_get_contents($p),true);$r["base_revision"]=$r["revision"]=str_repeat("b",40);file_put_contents($p,json_encode($r));'
if php8.4 /ui-package/control.php --check "$RUN" > /fixtures/ui-wrong-release.log 2>&1; then exit 1; fi
test ! -e /etc/cofrac2-mymar-booking
cp /fixtures/ui-request.valid /ui-package/REQUEST.json
php -r '$p="/ui-package/REQUEST.json";$r=json_decode(file_get_contents($p),true);$r["reader_summary_sha256"]=str_repeat("0",64);file_put_contents($p,json_encode($r));'
if php8.4 /ui-package/control.php --check "$RUN" > /fixtures/ui-altered-proof.log 2>&1; then exit 1; fi
test ! -e /etc/cofrac2-mymar-booking
cp /fixtures/ui-request.valid /ui-package/REQUEST.json
WRITER=$(php -r '$m=json_decode(file_get_contents("/var/lib/cofrac2-production/MYMAR-BOOKING-PREPARED.json"),true);$p=json_decode(file_get_contents($m["run_directory"]."/config.private.json"),true);echo $p["database_user"];')
[[ "$WRITER" =~ ^cofrac_mymar_rw_[a-f0-9]{8}$ ]]
mysql --defaults-file=/etc/mysql/debian.cnf -e "ALTER USER '$WRITER'@'localhost' ACCOUNT UNLOCK;"
if php8.4 /ui-package/control.php --check "$RUN" > /fixtures/ui-unlocked-writer.log 2>&1; then exit 1; fi
test ! -e /etc/cofrac2-mymar-booking
mysql --defaults-file=/etc/mysql/debian.cnf -e "ALTER USER '$WRITER'@'localhost' ACCOUNT LOCK;"
php8.4 /ui-package/control.php --check "$RUN"
php8.4 /ui-package/control.php --enable "$RUN"
runuser -u cofrac2-app -- php -d zend.assertions=1 -d assert.exception=1 <<'PHP' > "$RUN/application-probe.json"
<?php
require '/workspace/vendor/autoload.php';
$settings=new App\Services\Mymar\MymarBookingReaderSettings;
$metadata=$settings->load();assert($metadata['read_enabled']===true && $metadata['writes_enabled']===false);
assert(!is_readable('/var/lib/cofrac-mymar-booking-gateway/private/config.json'));
$client=new App\Services\Mymar\MymarBookingClient(new Illuminate\Http\Client\Factory,enabled:true,writesEnabled:false,token:$settings->token());
$page=$client->pending(0,1);
echo json_encode(['reader_ready'=>true,'pending_items_probed'=>count($page->items),'writes_enabled'=>false],JSON_THROW_ON_ERROR);
PHP
chmod 0600 "$RUN/application-probe.json"
php8.4 /ui-package/control.php --publish "$RUN"
chmod 0644 /etc/cofrac2-mymar-booking/reader.json
runuser -u cofrac2-app -- php -d zend.assertions=1 -d assert.exception=1 -r 'require "/workspace/vendor/autoload.php";try{(new App\Services\Mymar\MymarBookingReaderSettings)->load();exit(1);}catch(RuntimeException $e){assert($e->getMessage()==="mymar.unsafe_reader_settings");}'
chmod 0640 /etc/cofrac2-mymar-booking/reader.json
php -d zend.assertions=1 -d assert.exception=1 -r '
$source=json_decode(file_get_contents("/var/lib/cofrac-mymar-booking-gateway/private/config.json"),true);
$report=file_get_contents($argv[1]."/summary.json");assert(!str_contains($report,$source["token"])&&!str_contains($report,$source["database_password"]));
foreach(glob("/etc/cofrac2-mymar-booking/*")as$file){assert(fileowner($file)===0&&(fileperms($file)&0777)===0640);assert(!str_contains(file_get_contents($file),$source["database_password"]));}
' "$RUN"
if php8.4 /ui-package/control.php --enable "$RUN" > /fixtures/ui-repeat.log 2>&1; then exit 1; fi
printf '{}\n' > /ui-package/REQUEST.json
php8.4 /ui-package/control.php --disable "$RUN"
runuser -u cofrac2-app -- php -d zend.assertions=1 -d assert.exception=1 -r 'require "/workspace/vendor/autoload.php";assert((new App\Services\Mymar\MymarBookingReaderSettings)->load()===null);'
test "$(mysql --defaults-file=/etc/mysql/debian.cnf -NBe "SELECT account_locked FROM mysql.user WHERE User='$WRITER'")" = Y
printf 'UI reader: root ACLs, runtime HTTP client, no SQL password, wrong release/unlocked writer refused and independent disable checked.\n'
