#!/usr/bin/env bash
# Real SELECTs plus a full collector rehearsal. Disposable containers, no external network or published port.
set -Eeuo pipefail
[ "${MYMAR_LIVE_TEST:-}" = 1 ] || { echo 'MYMAR_LIVE_TEST=1 requis, conteneurs jetables uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-live-test-XXXXXXXX)
DB= PHP=
cleanup() {
    result=$?
    if [ -n "$PHP" ]; then
        docker cp "$PHP:/fixtures" "$WORK/reports" >/dev/null 2>&1 || true
        if [ "$result" != 0 ]; then tail -n 20 "$WORK/reports/"*.log 2>/dev/null || true; fi
    fi
    [ -z "$PHP" ] || docker rm -fv "$PHP" >/dev/null
    [ -z "$DB" ] || docker rm -fv "$DB" >/dev/null
    printf 'Diagnostic local : %s\n' "$WORK"
    exit "$result"
}
trap cleanup EXIT
DB=$(docker run -d --network none -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46)
for _ in $(seq 1 90); do
    if [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
[ "$(docker exec "$DB" mysql -NBe 'SELECT @@port')" = 3306 ]
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/tests/mymar-audit-fixture.sql"
docker exec -i "$DB" mysql <<'SQL'
UPDATE MYMAR.roles SET name='Technicien Terrain' WHERE id=4;
INSERT INTO MYMAR.status VALUES (3,'RDV ATTENTE VISITE',NULL),(16,'RDV APT ATTENTE',NULL);
INSERT INTO MYMAR.users VALUES (26,'Source reference','reference@example.test','CANARY_SOURCE_SECRET',1,900);
INSERT INTO MYMAR.model_has_roles VALUES (4,26,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'));
INSERT INTO cofrac2production.users VALUES (151,'Target','Reference','reference@example.test','CANARY_TARGET_SECRET',2,NULL,NULL);
INSERT INTO cofrac2production.coffrac_user_links VALUES (900,151);
INSERT INTO MYMAR.dossiers VALUES (91,16,26,NULL,0),(92,16,NULL,NULL,0);
INSERT INTO MYMAR.dossier_rdv VALUES
 (10,91,DATE(UTC_TIMESTAMP())+INTERVAL 1 DAY+INTERVAL 9 HOUR,1,0,UTC_TIMESTAMP()),
 (11,91,DATE(UTC_TIMESTAMP())+INTERVAL 1 DAY+INTERVAL 13 HOUR,2,0,UTC_TIMESTAMP()),
 (12,91,DATE(UTC_TIMESTAMP())+INTERVAL 1 DAY+INTERVAL 16 HOUR,1,1,UTC_TIMESTAMP()),
 (13,92,DATE(UTC_TIMESTAMP())+INTERVAL 1 DAY+INTERVAL 10 HOUR,1,0,UTC_TIMESTAMP());
SQL
snapshot() { docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.status,MYMAR.roles,MYMAR.model_has_roles,MYMAR.users,MYMAR.dossiers,MYMAR.dossier_rdv,cofrac2production.users,cofrac2production.coffrac_user_links;'; }
snapshot > "$WORK/before"
for mode in metadata data; do
    php -r 'require $argv[1];echo Cofrac2\Deploy\MymarReadOnlyAudit::{$argv[2]."Sql"}();' "$ROOT/tools/deploy/mymar/MymarReadOnlyAudit.php" "$mode" > "$WORK/$mode.sql"
done
php -r '
require $argv[1]."/vendor/autoload.php";
require $argv[1]."/tools/deploy/mymar/gateway/MymarReadOnlySource.php";
require $argv[1]."/tools/deploy/mymar/observation/MymarLiveObservation.php";
$from=(new DateTimeImmutable("now",new DateTimeZone("Europe/Paris")))->setTime(0,0);
$window=new App\Services\Mymar\MymarReadWindow($from->format(DATE_ATOM),$from->modify("+7 days")->format(DATE_ATOM),[1,3,26]);
foreach(Cofrac2\Deploy\Mymar\MymarLiveObservation::sql($window) as $mode=>$sql)file_put_contents($argv[2]."/live-$mode.sql",$sql);
' "$ROOT" "$WORK"
for mode in metadata data live-explain live-observation; do
    docker exec -i "$DB" mysql --user=audit_reader --default-character-set=utf8mb4 --batch --raw --skip-column-names < "$WORK/$mode.sql" > "$WORK/$mode.out"
done
snapshot > "$WORK/after"
cmp "$WORK/before" "$WORK/after"
PHP=$(docker run -d --hostname ns365601 --network none --mount "type=bind,source=$ROOT,target=/workspace,readonly" php:8.5-fpm-bookworm sleep 600)
docker exec -i "$PHP" sh <<'SH'
set -eu
useradd -M ubuntu
ln -s /usr/local/bin/php /usr/bin/php8.4
mkdir -m 700 -p /package /fixtures /etc/mysql /var/lib/cofrac2-production
printf '[client]\n' > /etc/mysql/debian.cnf
chmod 600 /etc/mysql/debian.cnf
for command in curl wget artisan composer systemctl service; do
    printf '#!/bin/sh\necho forbidden > /fixtures/FORBIDDEN_CALL\nexit 99\n' > "/usr/bin/$command"
    chmod 700 "/usr/bin/$command"
done
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /workspace/tools/deploy/mymar/collect.php /package/
cp /workspace/tools/deploy/mymar/observation/MymarLiveObservation.php /workspace/tools/deploy/mymar/observation/observe-live.php /workspace/tools/deploy/mymar/observation/collect-live.sh /workspace/tools/deploy/mymar/observation/fetch-live.sh /package/
cp /workspace/app/Services/Mymar/MymarObservation.php /workspace/app/Services/Mymar/MymarObservationPolicy.php /workspace/app/Services/Mymar/MymarReadWindow.php /workspace/tools/deploy/mymar/gateway/MymarReadOnlySource.php /package/
printf '%040d\n' 1 > /package/SOURCE_REVISION
chmod 600 /package/*
cd /package
sha256sum * > SHA256SUMS
SH
for mode in metadata data live-explain live-observation; do docker cp "$WORK/$mode.out" "$PHP:/fixtures/$mode.out"; done
docker exec -i "$PHP" php <<'PHP'
<?php
$paths=['routes/api.php','app/Http/Kernel.php','app/Providers/RouteServiceProvider.php','app/Http/Controllers/ApiController.php',
 'app/Http/Controllers/DossierController.php','app/Models/Dossier.php','app/Models/DossierRDV.php','app/Models/Technicien.php',
 'app/Models/User.php','app/Console/Commands/RdvCron.php','config/app.php'];
foreach($paths as $path) {
 $file='/home/ubuntu/www/MYMAR/'.$path;if(!is_dir(dirname($file)))mkdir(dirname($file),0700,true);
 file_put_contents($file,'<?php file_put_contents("/fixtures/EXECUTED", "CANARY");');
}
foreach(['/home/ubuntu/www/MYMAR'=>'MYMAR','/var/www/cofrac2'=>'cofrac2production'] as $root=>$db) {
 mkdir($root.'/bootstrap/cache',0700,true);
 file_put_contents($root.'/.env',"DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=$db\nDB_PASSWORD=CANARY_SECRET\n");
 $config=['app'=>['timezone'=>'Europe/Paris'],'database'=>['default'=>'mysql','connections'=>['mysql'=>[
 'driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>$db,'password'=>'CANARY_SECRET']]]];
 file_put_contents($root.'/bootstrap/cache/config.php','<?php return '.var_export($config,true).';');
}
$state='/var/lib/cofrac2-production';$revision=trim(file_get_contents('/package/SOURCE_REVISION'));
file_put_contents($state.'/RUNTIME-PREPARED.json',json_encode(['revision'=>$revision]));
file_put_contents($state.'/INTERNAL-SITE-OPEN.json','{}');
$files=[];
foreach(['MymarObservation','MymarObservationPolicy','MymarReadWindow','MymarReadOnlySource'] as $name) {
 $relative=$name==='MymarReadOnlySource'?'tools/deploy/mymar/gateway/'.$name.'.php':'app/Services/Mymar/'.$name.'.php';
 $files[$relative]=hash_file('sha256','/package/'.$name.'.php');
}
mkdir($state.'/mymar-gateway-prepared/'.$revision,0700,true);
file_put_contents($state.'/mymar-gateway-prepared/'.$revision.'/DISABLED.json',json_encode(['revision'=>$revision,'enabled'=>false,'configured'=>false,'files'=>$files]));
PHP
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
cat > /usr/bin/mysql <<'MYSQL'
#!/bin/sh
printf 'call\n' >> /fixtures/sql-calls
sql=$(cat)
case "$sql" in
 *information_schema.TABLES*) cat /fixtures/metadata.out ;;
 *"'section','counts'"*) cat /fixtures/data.out ;;
 *"EXPLAIN SELECT"*) cat /fixtures/live-explain.out ;;
 *"'section','appointment'"*)
   if [ -f /fixtures/fail ]; then printf 'ERROR 3024 (HY000): CANARY_PRIVATE_ERROR\n' >&2; exit 1; fi
   if [ -f /fixtures/partial ]; then sed '$d' /fixtures/live-observation.out; else cat /fixtures/live-observation.out; fi
   if [ -f /fixtures/changed ]; then printf '\n// changed\n' >> /home/ubuntu/www/MYMAR/routes/api.php; fi
   ;;
 *) exit 99 ;;
esac
MYSQL
chmod 700 /usr/bin/mysql
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.before
cd /package
bash ./collect-live.sh > /fixtures/success.log 2>&1
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.after
cmp /fixtures/apps.before /fixtures/apps.after
cp /var/lib/cofrac2-production/RUNTIME-PREPARED.json /fixtures/runtime.before
calls=$(wc -l < /fixtures/sql-calls)
printf '{"revision":"unexpected"}\n' > /var/lib/cofrac2-production/RUNTIME-PREPARED.json
if bash ./collect-live.sh > /fixtures/revision-refused.log 2>&1; then exit 1; fi
test "$(wc -l < /fixtures/sql-calls)" = "$calls"
cp /fixtures/runtime.before /var/lib/cofrac2-production/RUNTIME-PREPARED.json
marker="/var/lib/cofrac2-production/mymar-gateway-prepared/$(cat SOURCE_REVISION)/DISABLED.json"
cp "$marker" /fixtures/disabled.before
php -r '$p=$argv[1];$r=json_decode(file_get_contents($p),true);$r["configured"]=true;file_put_contents($p,json_encode($r));' "$marker"
if bash ./collect-live.sh > /fixtures/activation-refused.log 2>&1; then exit 1; fi
test "$(wc -l < /fixtures/sql-calls)" = "$calls"
cp /fixtures/disabled.before "$marker"
for mode in fail partial changed; do
    touch "/fixtures/$mode"
    if bash ./collect-live.sh > "/fixtures/$mode.log" 2>&1; then exit 1; fi
    test "$(find /home/ubuntu -maxdepth 1 -type d -name 'cofrac2-mymar-live-report-*' | wc -l)" = 1
    mv "/fixtures/$mode" "/fixtures/$mode.done"
done
test ! -f /fixtures/EXECUTED && test ! -f /fixtures/FORBIDDEN_CALL
SH
docker exec -i "$PHP" php <<'PHP'
<?php
$log=file_get_contents('/fixtures/success.log');
if(!preg_match('~^REPORT_DIR=(/home/ubuntu/cofrac2-mymar-live-report-[A-Za-z0-9]+)$~m',$log,$m))exit(1);
$dir=$m[1];$summary=json_decode(file_get_contents($dir.'/observation-summary.json'),true,32,JSON_THROW_ON_ERROR);
if(!$summary['collection_complete']||$summary['connector_enabled']||$summary['can_affect_planning']||$summary['database_writes']
 ||$summary['active_source_technicians']!==3||$summary['proposed_links_not_approved']!==1||$summary['counts']['candidates']!==2
 ||$summary['unassigned_source_rows_in_envelope']!==1)exit(1);
$report=json_decode(gzdecode(file_get_contents($dir.'/occupations-observation.json.gz')),true,32,JSON_THROW_ON_ERROR);
if(array_column($report['candidates'],'source_rdv_id')!==[10,11]||array_column($report['candidates'],'cofrac_user_id')!==[null,null])exit(1);
foreach(new DirectoryIterator($dir) as $file) {
 if($file->isDot())continue;
 if($file->isLink()||($file->getPerms()&0777)!==0600||$file->getOwner()!==posix_getpwnam('ubuntu')['uid'])exit(1);
 if($file->getFilename()==='SHA256SUMS')continue;
 $text=file_get_contents($file->getPathname());if(str_ends_with($file->getFilename(),'.gz'))$text=gzdecode($text);
 if(preg_match('/CANARY|@example|DB_PASSWORD|password|email/i',$text)) {
  // Machine reason codes may describe an email comparison; no address or configuration is exported.
  if(preg_match('/CANARY|@example|DB_PASSWORD|"password"|"email"/i',$text))exit(1);
 }
}
echo "MySQL 8.0.46 : requete reelle SELECT seul et checksums source/V2 inchanges.\n";
echo "Pipeline complet : occupations sans mapping, hypotheses explicites, publication privee ; SQL interrompu, source modifiee et resultat partiel refuses.\n";
PHP
