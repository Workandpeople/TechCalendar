#!/usr/bin/env bash
# Real standalone Apache/FPM/MySQL over local HTTP; systemd syntax checked, lifecycle fixture only.
set -Eeuo pipefail
[ "${MYMAR_PRIVATE_HTTP_TEST:-}" = 1 ] || { echo 'MYMAR_PRIVATE_HTTP_TEST=1 requis, conteneurs jetables uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-private-http-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="mymar-http-db-$SUFFIX" PHP="mymar-http-php-$SUFFIX" SOCKET="mymar-http-socket-$SUFFIX"
cleanup() {
    result=$?
    docker cp "$PHP:/fixtures" "$WORK/reports" >/dev/null 2>&1 || true
    docker cp "$PHP:/var/lib/cofrac2-production" "$WORK/private-state" >/dev/null 2>&1 || true
    docker cp "$PHP:/var/log/cofrac-mymar-gateway" "$WORK/gateway-logs" >/dev/null 2>&1 || true
    if [ "$result" != 0 ]; then tail -n 20 "$WORK/reports/"*.log "$WORK/gateway-logs/"*.log 2>/dev/null || true; fi
    docker rm -fv "$PHP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$SOCKET" >/dev/null 2>&1 || true
    printf 'Diagnostic local : %s\n' "$WORK"
    exit "$result"
}
trap cleanup EXIT
docker image inspect cofrac2-runtime-check:local >/dev/null
docker volume create "$SOCKET" >/dev/null
docker run -d --name "$DB" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46 >/dev/null
for _ in $(seq 1 90); do
    if [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
[ "$(docker exec "$DB" mysql -NBe 'SELECT @@port')" = 3306 ]
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/tests/mymar-audit-fixture.sql"
docker run -d --name "$PHP" --hostname ns365601 --network none \
    --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" \
    --entrypoint sleep cofrac2-runtime-check:local 600 >/dev/null
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
useradd -M ubuntu
test -e /usr/bin/php8.4 || ln -s /usr/bin/php /usr/bin/php8.4
mkdir -p /package /fixtures /etc/mysql /var/lib/cofrac2-production
chmod 700 /package /fixtures /var/lib/cofrac2-production
cp /workspace/tools/deploy/mymar/sql-gateway/* /package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /package/
cp /workspace/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php /package/
cp /workspace/tools/deploy/mymar/gateway/MymarGatewayPreparation.php /package/
php /workspace/tools/deploy/tests/mymar-sql-gateway-fixture.php setup
php /workspace/tools/deploy/tests/mymar-sql-gateway-fixture.php request
cd /package
chmod 600 *
sha256sum * > SHA256SUMS
bash collect.sh --prepare-restricted-sql-and-probe > /fixtures/sql-preparation.log 2>&1
mkdir -m 700 /runtime-package
cp /workspace/tools/deploy/mymar/runtime/* /runtime-package/
cp MymarSqlGatewayPreparation.php MymarApprovedTechnicianMap.php MymarGatewayPreparation.php MymarReadOnlyAudit.php /runtime-package/
php <<'PHP'
<?php
$r=json_decode(file_get_contents(glob('/home/ubuntu/cofrac2-mymar-sql-gateway-report-*/summary.json')[0]),true,32,JSON_THROW_ON_ERROR);
$q=json_decode(file_get_contents('/package/REQUEST.json'),true,32,JSON_THROW_ON_ERROR);
$q['format']='cofrac.mymar-private-runtime-request';$q['connection_directory']=$r['private_connection_directory'];
file_put_contents('/runtime-package/REQUEST.json',json_encode($q,JSON_THROW_ON_ERROR));
PHP
cd /runtime-package
chmod 600 *
sha256sum * > SHA256SUMS
cp /workspace/tools/deploy/tests/mymar-private-http-systemctl-fixture.sh /usr/bin/systemctl
chmod 700 /usr/bin/systemctl
for command in artisan composer service; do
    printf '#!/bin/sh\necho forbidden > /fixtures/FORBIDDEN_CALL\nexit 99\n' > "/usr/bin/$command"
    chmod 700 "/usr/bin/$command"
done
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.before
SH
snapshot() { docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.roles,MYMAR.model_has_roles,MYMAR.dossiers,MYMAR.dossier_rdv,cofrac2production.users,cofrac2production.coffrac_user_links;'; }
snapshot > "$WORK/business.before"
if [ "${MYMAR_PRIVATE_HTTP_FAILSTART:-}" = 1 ]; then
    docker exec "$PHP" touch /fixtures/failstart
    if docker exec "$PHP" bash -c 'cd /runtime-package && bash install.sh --install-private-http-and-observe > /fixtures/runtime.log 2>&1'; then exit 1; fi
    test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_ro_%' AND account_locked='Y'")" = 1
    docker exec "$PHP" bash -c 'test ! -e /var/lib/cofrac2-production/MYMAR-HTTP-RUNTIME.json; grep -q stopped /fixtures/unit-actions; test -z "$(ss -H -ltn '\''sport = :19846'\'')"; test ! -f /fixtures/FORBIDDEN_CALL'
    snapshot > "$WORK/business.after"; cmp "$WORK/business.before" "$WORK/business.after"
    echo 'Echec simule apres deverrouillage : services dedies arretes, compte SQL reverrouille, aucune donne metier changee.'
    exit
fi
docker exec "$PHP" bash -c 'cd /runtime-package && bash install.sh --install-private-http-and-observe > /fixtures/runtime.log 2>&1'
snapshot > "$WORK/business.after"; cmp "$WORK/business.before" "$WORK/business.after"
docker exec -i "$PHP" php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$reports=glob('/home/ubuntu/cofrac2-mymar-private-http-report-*');assert(count($reports)===1);
$summary=json_decode(file_get_contents($reports[0].'/summary.json'),true,32,JSON_THROW_ON_ERROR);
assert($summary['production_http_tested']===true && $summary['validated_links']===2 && $summary['sql_account_locked']===false);
assert($summary['connector_enabled']===false && $summary['can_affect_planning']===false && $summary['business_database_writes']===false);
$report=json_decode(file_get_contents($reports[0].'/observation.json'),true,32,JSON_THROW_ON_ERROR);
assert(count($report['candidates'])===2 && array_column($report['candidates'],'cofrac_user_id')===[101,102]);
$config=json_decode(file_get_contents('/var/lib/cofrac-mymar-gateway/private/config.json'),true,32,JSON_THROW_ON_ERROR);
foreach(new DirectoryIterator($reports[0]) as $file){
 if($file->isDot())continue;
 $content=file_get_contents($file->getPathname());
 assert(!preg_match('/CANARY|@example|DB_PASSWORD|"password"|"email"/',$content));
 assert(!str_contains($content,$config['token'])&&!str_contains($content,$config['database_password']));
}
$user=posix_getpwnam('cofrac-mymar');
assert(fileowner('/var/lib/cofrac-mymar-gateway/private/config.json')===$user['uid']);
assert((fileperms('/var/lib/cofrac-mymar-gateway/private/config.json')&0777)===0600);
assert(!file_exists('/fixtures/FORBIDDEN_CALL')&&!file_exists('/fixtures/EXECUTED'));
echo "Apache/FPM/MySQL : HTTP authentifie, scope borne, deux occupations, aucun secret exporte, aucun effet CRM.\n";
PHP
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_ro_%' AND account_locked='N'")" = 1
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.after
cmp /fixtures/apps.before /fixtures/apps.after
cd /runtime-package
if bash install.sh --install-private-http-and-observe > /fixtures/repeat.log 2>&1; then exit 1; fi
run=$(php -r '$r=json_decode(file_get_contents("/var/lib/cofrac2-production/MYMAR-HTTP-RUNTIME.json"),true);echo $r["run_directory"];')
# Cleanup must not depend on the continuing validity of an old identity approval file.
php -r '$q=json_decode(file_get_contents("REQUEST.json"),true);file_put_contents($q["mapping_path"],"{}\n");'
bash "$run/bundle/stop.sh" > /fixtures/rollback.log 2>&1
for _ in $(seq 1 30); do [ -n "$(ss -H -ltn 'sport = :19846')" ] || break; sleep 0.1; done
test -z "$(ss -H -ltn 'sport = :19846')"
test ! -f /fixtures/FORBIDDEN_CALL
SH
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_ro_%' AND account_locked='Y'")" = 1
snapshot > "$WORK/business.stopped"; cmp "$WORK/business.before" "$WORK/business.stopped"
echo 'Installation, controles HTTP, reexecution refusee et arret : reussis, source inchangee.'
