#!/usr/bin/env bash
# Real SQL and production shell chain, disposable containers only. No external network or exposed port.
set -Eeuo pipefail
[ "${MYMAR_SQL_GATEWAY_TEST:-}" = 1 ] || { echo 'MYMAR_SQL_GATEWAY_TEST=1 requis, conteneurs jetables uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-sql-gateway-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="mymar-sql-db-$SUFFIX" PHP="mymar-sql-php-$SUFFIX" SOCKET="mymar-sql-socket-$SUFFIX"
cleanup() {
    result=$?
    docker cp "$PHP:/fixtures" "$WORK/reports" >/dev/null 2>&1 || true
    if [ "$result" != 0 ]; then tail -n 25 "$WORK/reports/"*.log 2>/dev/null || true; fi
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
id ubuntu >/dev/null 2>&1 || useradd -M ubuntu
test -e /usr/bin/php8.4 || ln -s /usr/bin/php /usr/bin/php8.4
mkdir -p /package /fixtures /etc/mysql /var/lib/cofrac2-production
chmod 700 /package /fixtures /var/lib/cofrac2-production
for command in curl wget artisan composer systemctl service; do
    printf '#!/bin/sh\necho forbidden > /fixtures/FORBIDDEN_CALL\nexit 99\n' > "/usr/bin/$command"
    chmod 700 "/usr/bin/$command"
done
cp /workspace/tools/deploy/mymar/sql-gateway/* /package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /package/
cp /workspace/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php /package/
cp /workspace/tools/deploy/mymar/gateway/MymarGatewayPreparation.php /package/
php /workspace/tools/deploy/tests/mymar-sql-gateway-fixture.php setup
php /workspace/tools/deploy/tests/mymar-sql-gateway-fixture.php request
cd /package
chmod 600 *
sha256sum * > SHA256SUMS
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.before
SH
snapshot() { docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.roles,MYMAR.model_has_roles,MYMAR.dossiers,MYMAR.dossier_rdv,cofrac2production.users,cofrac2production.coffrac_user_links;'; }
snapshot > "$WORK/business.before"
docker exec "$PHP" bash -c 'cd /package && bash collect.sh --prepare-restricted-sql-and-probe > /fixtures/success.log 2>&1'
snapshot > "$WORK/business.after"
cmp "$WORK/business.before" "$WORK/business.after"
docker exec "$PHP" php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-sql-gateway-fixture.php verify
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
cd /package
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.after
cmp /fixtures/apps.before /fixtures/apps.after
if bash collect.sh --prepare-restricted-sql-and-probe > /fixtures/repeat-refused.log 2>&1; then exit 1; fi
grep -q mymar.connection_already_prepared_or_requires_review /fixtures/repeat-refused.log
test "$(find /var/lib/cofrac2-production/mymar-sql-connections -name '*.json' | wc -l)" = 1
SH
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_ro_%'")" = 1
# A different pinned request reaches fresh validation, which must reject an inactive target before CREATE.
sleep 1
docker exec "$PHP" php /workspace/tools/deploy/tests/mymar-sql-gateway-fixture.php request
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
cd /package
seal() { sha256sum $(find . -maxdepth 1 -type f ! -name SHA256SUMS -printf '%f\n' | sort) > SHA256SUMS; }
cp REQUEST.json /fixtures/REQUEST.valid
php -r '$p="REQUEST.json";$r=json_decode(file_get_contents($p),true,32,JSON_THROW_ON_ERROR);$r["mapping_sha256"]=str_repeat("0",64);file_put_contents($p,json_encode($r));'
seal
if bash collect.sh --prepare-restricted-sql-and-probe > /fixtures/pin-refused.log 2>&1; then exit 1; fi
grep -q mymar.approved_mapping_not_pinned /fixtures/pin-refused.log
cp /fixtures/REQUEST.valid REQUEST.json
seal
file=/var/lib/cofrac2-production/mymar-gateway-prepared/0000000000000000000000000000000000000001/tools/deploy/mymar/gateway/MymarGateway.php
cp "$file" /fixtures/gateway.valid
printf '\n// modified fixture\n' >> "$file"
if bash collect.sh --prepare-restricted-sql-and-probe > /fixtures/code-refused.log 2>&1; then exit 1; fi
grep -q mymar.gateway_code_changed /fixtures/code-refused.log
cp /fixtures/gateway.valid "$file"
SH
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_ro_%'")" = 1
docker exec "$DB" mysql -e 'UPDATE cofrac2production.users SET deleted_at=UTC_TIMESTAMP() WHERE id=102;'
docker exec "$PHP" bash -c 'cd /package; sha256sum $(find . -maxdepth 1 -type f ! -name SHA256SUMS -printf "%f\n" | sort) > SHA256SUMS; if bash collect.sh --prepare-restricted-sql-and-probe > /fixtures/inactive-refused.log 2>&1; then exit 1; fi; grep -q mymar.approved_identity_inactive_or_changed /fixtures/inactive-refused.log'
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_ro_%'")" = 1
docker exec "$DB" mysql -e 'UPDATE cofrac2production.users SET deleted_at=NULL WHERE id=102;'
# A post-CREATE source failure must leave the newly created account locked and mark the attempt for review.
docker exec "$PHP" php /workspace/tools/deploy/tests/mymar-sql-gateway-fixture.php overflow
snapshot > "$WORK/failure.before"
docker exec "$PHP" bash -c 'cd /package; if bash collect.sh --prepare-restricted-sql-and-probe > /fixtures/source-failure.log 2>&1; then exit 1; fi; grep -q "phase=authenticated_handler_probe" /fixtures/source-failure.log'
snapshot > "$WORK/failure.after"
cmp "$WORK/failure.before" "$WORK/failure.after"
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_ro_%' AND account_locked='Y'")" = 2
docker exec -i "$PHP" php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$markers=glob('/var/lib/cofrac2-production/mymar-sql-connections/*.json');
assert(count($markers)===2);
$failed=array_values(array_filter(array_map(fn($p)=>json_decode(file_get_contents($p),true,32,JSON_THROW_ON_ERROR),$markers),fn($r)=>$r['stage']==='failed_requires_review'));
assert(count($failed)===1 && $failed[0]['sql_account_lock_confirmed']===true);
assert(count(glob('/home/ubuntu/cofrac2-mymar-sql-gateway-report-*'))===1);
assert(!file_exists('/fixtures/EXECUTED') && !file_exists('/fixtures/FORBIDDEN_CALL'));
$run=$failed[0]['run_directory'];
$account=json_decode(file_get_contents($run.'/sql-account.private.json'),true,32,JSON_THROW_ON_ERROR)['database_user'];
assert(preg_match('/^cofrac_mymar_ro_[a-f0-9]{8}$/D',$account)===1);
$db=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("ALTER USER '$account'@'localhost' ACCOUNT UNLOCK");
file_put_contents('/fixtures/relock-run',$run);
echo "MySQL 8.0.46 : succes, reexecution refusee, cible inactive et echec apres CREATE ; comptes SQL verrouilles, donnees metier et services inchanges.\n";
PHP
docker exec "$PHP" bash -c 'run=$(cat /fixtures/relock-run); php8.4 "$run/bundle/prepare-sql-gateway.php" --lock-only "$run"'
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_ro_%' AND account_locked='Y'")" = 2
