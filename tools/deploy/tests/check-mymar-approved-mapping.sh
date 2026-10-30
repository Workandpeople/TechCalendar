#!/usr/bin/env bash
# Synthetic identities only. No external network, published port or production access.
set -Eeuo pipefail
[ "${MYMAR_MAPPING_TEST:-}" = 1 ] || { echo 'MYMAR_MAPPING_TEST=1 requis, conteneurs jetables uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-approved-mapping-test-XXXXXXXX)
DB= PHP=
cleanup() {
    result=$?
    if [ -n "$PHP" ]; then
        docker cp "$PHP:/fixtures" "$WORK/reports" >/dev/null 2>&1 || true
        if [ "$result" != 0 ]; then tail -n 20 "$WORK/reports/"*.log 2>/dev/null || true; fi
        docker rm -fv "$PHP" >/dev/null
    fi
    [ -z "$DB" ] || docker rm -fv "$DB" >/dev/null
    printf 'Diagnostic local : %s\n' "$WORK"
    exit "$result"
}
trap cleanup EXIT
cat > "$WORK/approval.json" <<'JSON'
{"format":"cofrac.mymar-approved-identities","version":1,"bindings":[
{"source_technician_id":1,"source_name":"Technicien Un","cofrac_user_id":101,"cofrac_name":"Tech Un"},
{"source_technician_id":3,"source_name":"Technicien Trois","cofrac_user_id":102,"cofrac_name":"Tech Deux"}]}
JSON
php -r 'require $argv[1];echo Cofrac2\Deploy\Mymar\MymarApprovedTechnicianMap::sql(json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR));' \
    "$ROOT/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php" "$WORK/approval.json" > "$WORK/validation.sql"
DB=$(docker run -d --network none -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46)
for _ in $(seq 1 90); do
    if [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
[ "$(docker exec "$DB" mysql -NBe 'SELECT @@port')" = 3306 ]
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/tests/mymar-audit-fixture.sql"
docker exec "$DB" mysql -e "UPDATE MYMAR.roles SET name='Technicien Terrain' WHERE id=4;"
snapshot() { docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.roles,MYMAR.model_has_roles,cofrac2production.users;'; }
read_snapshot() { docker exec -i "$DB" mysql --user=audit_reader --default-character-set=utf8mb4 --batch --raw --skip-column-names < "$WORK/validation.sql"; }
snapshot > "$WORK/before"
read_snapshot > "$WORK/valid.jsonl"
snapshot > "$WORK/after"
cmp "$WORK/before" "$WORK/after"
docker exec "$DB" mysql -e "UPDATE cofrac2production.users SET deleted_at=UTC_TIMESTAMP() WHERE id=102;"
snapshot > "$WORK/disabled.before"
read_snapshot > "$WORK/disabled.jsonl"
snapshot > "$WORK/disabled.after"
cmp "$WORK/disabled.before" "$WORK/disabled.after"
php -r '
require $argv[1];$a=json_decode(file_get_contents($argv[2]."/approval.json"),true,32,JSON_THROW_ON_ERROR);
$read=fn($p)=>array_map(fn($l)=>json_decode($l,true,32,JSON_THROW_ON_ERROR),file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$map=Cofrac2\Deploy\Mymar\MymarApprovedTechnicianMap::validate($a,$read($argv[2]."/valid.jsonl"),new DateTimeImmutable("now"));
if(array_column($map["bindings"],"cofrac_user_id")!==[101,102]||$map["connector_enabled"]||$map["can_affect_planning"]||$map["account_writes"])exit(1);
try{Cofrac2\Deploy\Mymar\MymarApprovedTechnicianMap::validate($a,$read($argv[2]."/disabled.jsonl"),new DateTimeImmutable("now"));exit(1);}
catch(RuntimeException $e){if($e->getMessage()!=="mymar.approved_identity_inactive_or_changed")throw $e;}
echo "MySQL 8.0.46 : vrais SELECT, IDs explicites, cible desactivee refusee et checksums inchanges.".PHP_EOL;
' "$ROOT/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php" "$WORK"
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
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /package/
cp /workspace/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php /workspace/tools/deploy/mymar/mapping/prepare-mapping.php /workspace/tools/deploy/mymar/mapping/collect.sh /workspace/tools/deploy/mymar/mapping/fetch-mapping.sh /package/
printf '%040d\n' 1 > /package/SOURCE_REVISION
SH
docker cp "$WORK/approval.json" "$PHP:/package/approved-identities.json"
docker cp "$WORK/valid.jsonl" "$PHP:/fixtures/valid.jsonl"
docker cp "$WORK/disabled.jsonl" "$PHP:/fixtures/disabled.jsonl"
docker exec -i "$PHP" php <<'PHP'
<?php
foreach(['/home/ubuntu/www/MYMAR'=>'MYMAR','/var/www/cofrac2'=>'cofrac2production'] as $root=>$db) {
 mkdir($root.'/bootstrap/cache',0700,true);
 file_put_contents($root.'/.env',"DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=$db\nDB_PASSWORD=CANARY_ENV_SECRET\n");
 $config=['app'=>['timezone'=>'Europe/Paris'],'database'=>['default'=>'mysql','connections'=>['mysql'=>[
 'driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>$db,'password'=>'CANARY_CONFIG_SECRET']]]];
 file_put_contents($root.'/bootstrap/cache/config.php','<?php return '.var_export($config,true).';');
 file_put_contents($root.'/artisan','<?php file_put_contents("/fixtures/EXECUTED","CANARY");');
}
$state='/var/lib/cofrac2-production';$revision=trim(file_get_contents('/package/SOURCE_REVISION'));
file_put_contents($state.'/RUNTIME-PREPARED.json',json_encode(['revision'=>$revision]));
file_put_contents($state.'/INTERNAL-SITE-OPEN.json','{}');
mkdir($state.'/mymar-gateway-prepared/'.$revision,0700,true);
file_put_contents($state.'/mymar-gateway-prepared/'.$revision.'/DISABLED.json',json_encode([
 'format'=>'cofrac.mymar-disabled-preparation','version'=>1,'revision'=>$revision,'enabled'=>false,'configured'=>false]));
PHP
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
cat > /usr/bin/mysql <<'MYSQL'
#!/bin/sh
printf 'call\n' >> /fixtures/sql-calls
cat > /dev/null
if [ -f /fixtures/fail ]; then printf 'ERROR 3024 (HY000): CANARY_PRIVATE_SQL_ERROR\n' >&2; exit 1; fi
if [ -f /fixtures/disabled ]; then cat /fixtures/disabled.jsonl;
elif [ -f /fixtures/partial ]; then sed '$d' /fixtures/valid.jsonl;
else cat /fixtures/valid.jsonl; fi
if [ -f /fixtures/changed ]; then printf '\n# changed\n' >> /var/www/cofrac2/.env; fi
MYSQL
chmod 700 /usr/bin/mysql
cd /package
chmod 600 *
sha256sum * > SHA256SUMS
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.before
bash ./collect.sh > /fixtures/success.log 2>&1
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.after
cmp /fixtures/apps.before /fixtures/apps.after
cp /var/lib/cofrac2-production/RUNTIME-PREPARED.json /fixtures/runtime.before
calls=$(wc -l < /fixtures/sql-calls)
printf '{"revision":"unexpected"}\n' > /var/lib/cofrac2-production/RUNTIME-PREPARED.json
if bash ./collect.sh > /fixtures/revision-refused.log 2>&1; then exit 1; fi
test "$(wc -l < /fixtures/sql-calls)" = "$calls"
cp /fixtures/runtime.before /var/lib/cofrac2-production/RUNTIME-PREPARED.json
for mode in fail disabled partial changed; do
    touch "/fixtures/$mode"
    if bash ./collect.sh > "/fixtures/$mode.log" 2>&1; then exit 1; fi
    test "$(find /var/lib/cofrac2-production -name approved-map.json | wc -l)" = 1
    test "$(find /home/ubuntu -maxdepth 1 -name 'cofrac2-mymar-mapping-report-*' | wc -l)" = 1
    mv "/fixtures/$mode" "/fixtures/$mode.done"
done
test ! -f /fixtures/EXECUTED && test ! -f /fixtures/FORBIDDEN_CALL
SH
docker exec -i "$PHP" php <<'PHP'
<?php
$log=file_get_contents('/fixtures/success.log');
if(!preg_match('~^REPORT_DIR=(/home/ubuntu/cofrac2-mymar-mapping-report-[A-Za-z0-9]+)$~m',$log,$m))exit(1);
$dir=$m[1];$r=json_decode(file_get_contents($dir.'/mapping-summary.json'),true,32,JSON_THROW_ON_ERROR);
if($r['validated_links']!==2||$r['connector_enabled']||$r['can_affect_planning']||$r['database_writes']||!$r['requires_runtime_revalidation'])exit(1);
$path=$r['private_mapping_path'];$map=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
if(!hash_equals($r['mapping_sha256'],hash_file('sha256',$path))||($map['source_revision']!==str_repeat('0',39).'1')
 ||(fileperms($path)&0777)!==0600||(fileperms(dirname($path))&0777)!==0700||fileowner($path)!==0)exit(1);
foreach(new DirectoryIterator($dir) as $file){
 if($file->isDot())continue;
 if($file->isLink()||($file->getPerms()&0777)!==0600||$file->getOwner()!==posix_getpwnam('ubuntu')['uid'])exit(1);
 if(preg_match('/CANARY|@example|DB_PASSWORD|"password"|"email"/',file_get_contents($file->getPathname())))exit(1);
}
echo "Preparation complete : fichier root-prive inactif, profils et configuration verifies, comptes/source inchanges ; echecs refuses sans correspondance publiee.".PHP_EOL;
PHP
