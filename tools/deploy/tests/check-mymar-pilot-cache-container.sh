#!/usr/bin/env bash
# Run only by check-mymar-private-http.sh in its fresh synthetic container, after the real private API started.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ "$(hostname -s)" = ns365601 ] && [ -f /fixtures/sql-preparation.log ] || exit 1
trap 'printf "PILOT_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2; tail -20 /fixtures/pilot-*.log /fixtures/pilot-migrations.log /fixtures/timer-sync.log 2>/dev/null || true' ERR
APP=/var/www/cofrac2
useradd --system --user-group --no-create-home --home-dir /var/lib/cofrac2-app --shell /usr/sbin/nologin cofrac2-app
mkdir -p /var/lib/cofrac2-app
chown cofrac2-app:cofrac2-app /var/lib/cofrac2-app
# The earlier synthetic config cache intentionally does not contain a Laravel app configuration.
mv "$APP/bootstrap/cache/config.php" /fixtures/synthetic-config.before.php
for directory in app config lang routes vendor; do cp -R "/workspace/$directory" "$APP/"; done
mkdir -p "$APP/database" "$APP/resources" "$APP/public"
for directory in migrations factories seeders; do cp -R "/workspace/database/$directory" "$APP/database/"; done
cp -R /workspace/resources/views "$APP/resources/"
cp /workspace/bootstrap/app.php /workspace/bootstrap/providers.php "$APP/bootstrap/"
cp /workspace/artisan /workspace/composer.json /workspace/composer.lock "$APP/"
# Synthetic API fixtures start root-private; the real app needs traversable code, never the developer's config cache.
find "$APP" -type d -exec chmod 0755 {} +
find "$APP" -type f -exec chmod 0644 {} +
mkdir -p "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" "$APP/storage/framework/views" "$APP/storage/logs"
chown -R cofrac2-app:cofrac2-app "$APP/storage" "$APP/bootstrap/cache"
php <<'PHP'
<?php
$db=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("DROP DATABASE cofrac2production; CREATE DATABASE cofrac2production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cofrac2_fixture'@'localhost' IDENTIFIED BY 'local-cache-fixture-password'; GRANT ALL ON cofrac2production.* TO 'cofrac2_fixture'@'localhost';");
$env="APP_NAME=Fixture\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nAPP_URL=https://example.test\nCRM_V2_ENABLED=true\nSIMULATION_MODE=false\nDB_CONNECTION=mysql\nDB_HOST=localhost\nDB_DATABASE=cofrac2production\nDB_USERNAME=cofrac2_fixture\nDB_PASSWORD=local-cache-fixture-password\nCACHE_STORE=database\nQUEUE_CONNECTION=database\nMAIL_MAILER=log\nMAIL_DELIVERY_ENABLED=false\nSMSFACTOR_ENABLED=false\nSMSFACTOR_MODE=simulate\nCAPCEE_ENABLED=false\nSESSION_DRIVER=database\nBROADCAST_CONNECTION=log\n";
file_put_contents('/var/www/cofrac2/.env',$env);chgrp('/var/www/cofrac2/.env','cofrac2-app');chmod('/var/www/cofrac2/.env',0640);
PHP
app_cli() { runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app php8.4 "$APP/artisan" "$@" --no-interaction --no-ansi; }
app_cli migrate --force > /fixtures/pilot-migrations.log 2>&1
runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app php8.4 <<'PHP'
<?php
chdir('/var/www/cofrac2');require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach([101=>['Tech','Un'],102=>['Tech','Deux']] as $id=>[$first,$last]) {
 App\Models\User::query()->forceCreate(['id'=>$id,'first_name'=>$first,'last_name'=>$last,'email'=>"tech$id@example.test",'password'=>'LocalFixturePassword1!','role'=>2,'admin'=>false,'external_client_id'=>null]);
}
PHP
app_cli config:cache > /fixtures/pilot-config-cache.log 2>&1
mkdir -m 700 /pilot-package
cp /workspace/tools/deploy/mymar/pilot/{pilot-control.php,setup-pilot.sh,stop-pilot.sh,*.service,*.timer} /pilot-package/
cp /workspace/tools/deploy/mymar/runtime/MymarRuntimeState.php /workspace/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php \
    /workspace/tools/deploy/mymar/gateway/MymarGatewayPreparation.php /workspace/app/Services/Mymar/MymarPilotConfiguration.php /pilot-package/
php <<'PHP'
<?php
$api=json_decode(file_get_contents('/var/lib/cofrac2-production/MYMAR-HTTP-RUNTIME.json'),true,32,JSON_THROW_ON_ERROR);
$base=json_decode(file_get_contents('/runtime-package/REQUEST.json'),true,32,JSON_THROW_ON_ERROR);
$base['format']='cofrac.mymar-cache-pilot-request';unset($base['connection_directory']);
$base['target_revision']=$base['source_revision'];$base['api_runtime_directory']=$api['run_directory'];
file_put_contents('/pilot-package/REQUEST.json',json_encode($base,JSON_THROW_ON_ERROR));
PHP
snapshot() { mysql --defaults-file=/etc/mysql/debian.cnf -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.dossiers,MYMAR.dossier_rdv,cofrac2production.users,cofrac2production.appointments,cofrac2production.dossiers;'; }
snapshot > /fixtures/pilot-business.before
cd /pilot-package; chmod 600 *; sha256sum * > SETUP-SHA256SUMS
api_account() {
    php -r '$c=json_decode(file_get_contents("/var/lib/cofrac-mymar-gateway/private/config.json"),true,32,JSON_THROW_ON_ERROR);
        $db=new PDO("mysql:unix_socket=/var/run/mysqld/mysqld.sock","root","");
        $db->exec("ALTER USER ".$db->quote($c["database_user"])."@\x27localhost\x27 ACCOUNT ".$argv[1]);' "$1"
}
# A reachable API with unavailable SQL must fail after staging, with no visible pilot or service changes.
api_account LOCK
if bash setup-pilot.sh --enable-observation-only > /fixtures/pilot-failed-setup.log 2>&1; then exit 1; fi
php -r '$c=json_decode(file_get_contents("/etc/cofrac2-mymar/pilot.json"),true,32,JSON_THROW_ON_ERROR);
    if($c["sync_enabled"]||$c["display_enabled"]||$c["blocking_enabled"])exit(1);'
test ! -f /etc/systemd/system/cofrac2-mymar-observation.timer
test -n "$(ss -H -ltn 'sport = :19846')"
snapshot > /fixtures/pilot-business.failed; cmp /fixtures/pilot-business.before /fixtures/pilot-business.failed
mv /etc/cofrac2-mymar /fixtures/failed-pilot-configuration
api_account UNLOCK
echo 'Premiere synchronisation en echec : pilote masque, API active, comptes et dossiers inchanges.'
bash setup-pilot.sh --enable-observation-only > /fixtures/pilot-setup.log 2>&1
snapshot > /fixtures/pilot-business.after; cmp /fixtures/pilot-business.before /fixtures/pilot-business.after
app_cli mymar:observation-status > /fixtures/pilot-cache-status.json
runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app php8.4 <<'PHP'
<?php
chdir('/var/www/cofrac2');require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$feed=app(App\Services\Mymar\MymarCalendarFeed::class)->window([101,102],now(),now()->addDays(7));
if($feed['state']!=='observing'||count($feed['events'])!==2||$feed['blocking_enabled']!==false)exit(1);
if(App\Models\Appointment::count()!==0||App\Models\Dossier::count()!==0)exit(1);
if(config('mail.delivery_enabled')||config('services.smsfactor.enabled')||config('services.smsfactor.mode')!=='simulate')exit(1);
echo "API privee -> commande Laravel -> cache SQL persistant -> calendrier : deux occupations readonly, modes mail/SMS preserves.\n";
PHP
RUN=$(php -r '$p=glob("/var/lib/cofrac2-production/mymar-cache-pilot-*/summary.json");echo dirname($p[0]);')
php -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(!$r["cache_ready"]||$r["blocking_enabled"]||$r["cached_periods"]!==2)exit(1);' "$RUN/summary.json"
bash "$RUN/bundle/stop-pilot.sh" > /fixtures/pilot-stop.log 2>&1
test -n "$(ss -H -ltn 'sport = :19846')"
runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app php8.4 <<'PHP'
<?php
chdir('/var/www/cofrac2');require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$feed=app(App\Services\Mymar\MymarCalendarFeed::class)->window([101,102],now(),now()->addDays(7));if($feed['state']!=='disabled'||$feed['events']!==[])exit(1);
PHP
snapshot > /fixtures/pilot-business.stopped; cmp /fixtures/pilot-business.before /fixtures/pilot-business.stopped
echo 'Arret du pilote : affichage/cache-sync desactives, API toujours active, comptes et dossiers inchanges.'
