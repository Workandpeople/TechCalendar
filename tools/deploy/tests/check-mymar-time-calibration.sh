#!/usr/bin/env bash
# Disposable containers only: no published ports or production access.
set -Eeuo pipefail
[ "${MYMAR_CALIBRATION_TEST:-}" = 1 ] || { echo 'MYMAR_CALIBRATION_TEST=1 requis, conteneurs jetables uniquement.' >&2; exit 1; }
BASE=$(cd "$(dirname "$0")/.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-time-calibration-test-XXXXXXXX)
DB= PHP=
cleanup() {
    [ -z "$PHP" ] || docker rm -f -v "$PHP" >/dev/null
    [ -z "$DB" ] || docker rm -f -v "$DB" >/dev/null
}
trap cleanup EXIT
DB=$(docker run -d --network none -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46)
for _ in $(seq 1 60); do
    if [ "$(docker exec "$DB" mysql --skip-column-names -e 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
[ "$(docker exec "$DB" mysql --skip-column-names -e 'SELECT @@port')" = 3306 ]
docker exec -i "$DB" mysql <<'SQL'
CREATE DATABASE MYMAR;
CREATE TABLE MYMAR.dossiers (id bigint PRIMARY KEY, status_id bigint, technicien_id bigint, mar_type int, beneficiary varchar(190)) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossier_rdv (id bigint PRIMARY KEY, dossier_id bigint, date_rdv datetime, duree int, is_old int, INDEX(dossier_id)) ENGINE=InnoDB;
INSERT INTO MYMAR.dossiers VALUES (91,16,26,0,'CANARY_PRIVATE_BENEFICIARY'),(92,3,99,0,'CANARY_OTHER_BENEFICIARY');
INSERT INTO MYMAR.dossier_rdv VALUES (777,91,'2026-11-02 09:30:00',2,0),(778,91,'2026-10-01 10:30:00',1,1),(779,92,'2026-11-02 11:30:00',1,0);
CREATE USER 'calibration_reader'@'localhost';
GRANT SELECT ON MYMAR.* TO 'calibration_reader'@'localhost';
SQL
snapshot() { docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.dossiers,MYMAR.dossier_rdv;'; }
snapshot > "$WORK/before"
for mode in metadata data; do
    php -r 'require $argv[1]; echo Cofrac2\Deploy\MymarTimeCalibration::{$argv[2]."Sql"}();' \
        "$BASE/mymar/calibration/MymarTimeCalibration.php" "$mode" > "$WORK/$mode.sql"
    docker exec -i "$DB" mysql --user=calibration_reader --default-character-set=utf8mb4 --batch --raw --skip-column-names \
        < "$WORK/$mode.sql" > "$WORK/$mode.jsonl"
done
snapshot > "$WORK/after"
cmp "$WORK/before" "$WORK/after"
php -r '
require $argv[1];
$read=fn($p)=>array_map(fn($line)=>json_decode($line,true,32,JSON_THROW_ON_ERROR),file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
Cofrac2\Deploy\MymarTimeCalibration::validateMetadata($read($argv[2]."/metadata.jsonl"));
$r=Cofrac2\Deploy\MymarTimeCalibration::validateData($read($argv[2]."/data.jsonl"));
if(!$r["active_reference_unique"] || !$r["stored_start_matches_observed_display"] || $r["references"][0]["rdv_id"]!==777
 || $r["references"][0]["raw_duration"]!==2 || $r["duration_unit_confirmed"] || $r["timezone_interpretation_confirmed"]
 || str_contains(file_get_contents($argv[2]."/data.jsonl"),"CANARY")) exit(1);
echo "MySQL 8.0.46 : SELECT seul, un seul dossier, anciennes lignes exclues, duree brute preservee, checksums inchanges.".PHP_EOL;
' "$BASE/mymar/calibration/MymarTimeCalibration.php" "$WORK"

PHP=$(docker run -d --hostname ns365601 --network none --mount "type=bind,source=$BASE/mymar,target=/audit,readonly" php:8.5-fpm-bookworm sleep 600)
docker exec -i "$PHP" sh <<'SH'
set -eu
useradd -M ubuntu
ln -s /usr/local/bin/php /usr/bin/php8.4
mkdir -m 700 -p /package /fixtures /etc/mysql /var/lib/cofrac2-production
cp /audit/MymarReadOnlyAudit.php /package/
cp /audit/calibration/MymarTimeCalibration.php /audit/calibration/calibrate.php /audit/calibration/collect.sh /audit/calibration/fetch-audit.sh /audit/calibration/README.txt /package/
chmod 600 /package/*
printf '[client]\n' > /etc/mysql/debian.cnf
chmod 600 /etc/mysql/debian.cnf
for command in curl wget artisan composer; do
    printf '#!/bin/sh\necho forbidden > /fixtures/FORBIDDEN_CALL\nexit 99\n' > "/usr/bin/$command"
    chmod 700 "/usr/bin/$command"
done
cd /package
sha256sum MymarReadOnlyAudit.php MymarTimeCalibration.php calibrate.php collect.sh fetch-audit.sh README.txt > SHA256SUMS
SH
docker cp "$WORK/metadata.jsonl" "$PHP:/fixtures/metadata.jsonl"
docker cp "$WORK/data.jsonl" "$PHP:/fixtures/data.jsonl"
docker exec -i "$PHP" php <<'PHP'
<?php
$root='/home/ubuntu/www/MYMAR';
$files=[
 'app/Http/Controllers/ApiController.php'=>'<?php file_put_contents("/fixtures/EXECUTED", "CANARY"); function planning(){ $date = Carbon::parse($row->date_rdv); $date->addHour(); }',
 'app/Http/Controllers/DossierController.php'=>'<?php throw new RuntimeException("CANARY_APPLICATION"); return view("calendar");',
 'app/Http/Controllers/HomeController.php'=>'<?php return view("calendar");',
 'app/Models/DossierRDV.php'=>'<?php throw new RuntimeException("CANARY_MODEL");',
 'resources/js/app.js'=>"import Home from './views/Home.vue';",
 'resources/js/views/Home.vue'=>'<template><select id="other-name" v-model="rdv_duree"><option value="1">1 heure</option><option value="2">2 heures</option></select></template><script>const password = "CANARY_SCRIPT_SECRET"; const date = time.toISOString();</script>',
 'resources/views/calendar.blade.php'=>'<input name="password" value="CANARY_PASSWORD">',
 '.env'=>"DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=MYMAR\nDB_PASSWORD=CANARY_ENV_SECRET\n",
];
foreach($files as $path=>$text) { if(!is_dir(dirname($root.'/'.$path))) mkdir(dirname($root.'/'.$path),0700,true); file_put_contents($root.'/'.$path,$text); }
mkdir($root.'/bootstrap/cache',0700,true);
$config=['app'=>['timezone'=>'UTC'],'database'=>['default'=>'mysql','connections'=>['mysql'=>[
 'driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>'MYMAR','password'=>'CANARY_CACHE_SECRET']]]];
file_put_contents($root.'/bootstrap/cache/config.php','<?php return '.var_export($config,true).';');
PHP
docker exec -i "$PHP" sh <<'SH'
set -eu
cat > /usr/bin/mysql <<'MYSQL'
#!/bin/sh
sql=$(cat)
case "$sql" in
 *information_schema.TABLES*) cat /fixtures/metadata.jsonl ;;
 *"'section','reference'"*)
   cat /fixtures/data.jsonl
   if [ -f /fixtures/source-change ]; then printf '\n// changed fixture\n' >> /home/ubuntu/www/MYMAR/resources/js/views/Home.vue; fi
   if [ -f /fixtures/sql-failure ]; then printf 'ERROR 3024 (HY000) at line 8: CANARY_PRIVATE_ERROR private@example.test\n' >&2; exit 1; fi
   ;;
 *) exit 99 ;;
esac
MYSQL
chmod 700 /usr/bin/mysql
find /home/ubuntu/www/MYMAR -type f -exec sha256sum {} + | sort > /fixtures/before
cd /package
bash ./collect.sh > /fixtures/success.log
find /home/ubuntu/www/MYMAR -type f -exec sha256sum {} + | sort > /fixtures/after
cmp /fixtures/before /fixtures/after
test ! -e /fixtures/EXECUTED
test ! -e /fixtures/FORBIDDEN_CALL
touch /fixtures/sql-failure
bash ./collect.sh > /fixtures/failure.log
rm /fixtures/sql-failure
touch /fixtures/source-change
bash ./collect.sh > /fixtures/changed.log
SH
docker exec -i "$PHP" php <<'PHP'
<?php
foreach(['success','failure','changed'] as $case) {
 $log=file_get_contents('/fixtures/'.$case.'.log');
 if(!preg_match('~^REPORT_DIR=(/home/ubuntu/cofrac2-mymar-calibration-report-[A-Za-z0-9]+)$~m',$log,$m)) exit(1);
 $dir=$m[1]; $summary=json_decode(file_get_contents($dir.'/summary.json'),true,32,JSON_THROW_ON_ERROR);
 if(($summary['complete']===true)!==($case==='success') || $summary['application_executed'] || $summary['database_writes'] || $summary['connector_enabled']) exit(1);
 if(is_file($dir.'/reference.json')!==($case==='success')) exit(1);
 if($case==='changed' && $summary['selected_source_unchanged']) exit(1);
 if($case==='failure' && $summary['sql_errors']!==[['stage'=>'reference','mysql_code'=>3024,'sqlstate'=>'HY000']]) exit(1);
 if((fileperms($dir)&0777)!==0700) exit(1);
 foreach(new DirectoryIterator($dir) as $f) {
  if($f->isDot()) continue;
  if(!in_array($f->getFilename(),['source-facts.json.gz','summary.json','reference.json','SHA256SUMS'],true)
   || $f->isLink() || ($f->getPerms()&0777)!==0600 || $f->getOwner()!==posix_getpwnam('ubuntu')['uid']) exit(1);
  if($f->getFilename()==='SHA256SUMS') continue;
  $text=file_get_contents($f->getPathname()); if(str_ends_with($f->getFilename(),'.gz')) $text=gzdecode($text);
  if(preg_match('/CANARY|@example|file_put_contents|throw new|PRIVATE_ERROR/',$text)) exit(1);
 }
}
echo "Pipeline teste : fichiers applicatifs inchanges, aucune execution/HTTP, publication privee ; echec SQL et modification concurrente refuses.".PHP_EOL;
PHP
printf 'Diagnostic local synthetique : %s\n' "$WORK"
