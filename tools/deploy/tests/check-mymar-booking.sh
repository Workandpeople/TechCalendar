#!/usr/bin/env bash
# No production access. All mutations below are confined to disposable, networkless containers.
set -Eeuo pipefail
[ "${MYMAR_BOOKING_TEST:-}" = 1 ] || { echo 'MYMAR_BOOKING_TEST=1 requis, conteneurs jetables uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-booking-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="mymar-booking-db-$SUFFIX" PHP="mymar-booking-php-$SUFFIX" SOCKET="mymar-booking-socket-$SUFFIX"
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
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/tests/mymar-booking-fixture.sql"
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/mymar/booking/journal.sql"
docker run -d --name "$PHP" --hostname ns365601 --network none \
    --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" \
    --entrypoint sleep cofrac2-runtime-check:local 300 >/dev/null
docker exec "$PHP" mkdir /fixtures
docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.clients,MYMAR.roles,MYMAR.model_has_roles,cofrac2production.users' > "$WORK/accounts.before"
docker exec "$PHP" php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-booking-fixture.php prepare
docker exec "$PHP" bash -c 'php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-booking-fixture.php check > /fixtures/booking.log 2>&1'
docker exec "$PHP" bash -c '
set -Eeuo pipefail
php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-booking-fixture.php race /fixtures/race-first-command.json > /fixtures/race-first.json 2>/fixtures/race-first.log &
a=$!
php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-booking-fixture.php race /fixtures/race-second-command.json > /fixtures/race-second.json 2>/fixtures/race-second.log &
b=$!
wait "$a"; wait "$b"
php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-booking-fixture.php verify-race
'
# Client address was intentionally changed by the fixture to exercise optimistic concurrency.
docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.roles,MYMAR.model_has_roles,cofrac2production.users' > "$WORK/accounts.after"
awk '$1!="MYMAR.clients"' "$WORK/accounts.before" > "$WORK/accounts.expected"
cmp "$WORK/accounts.expected" "$WORK/accounts.after"
echo 'MySQL 8.0.46 : commandes transactionnelles, reprise idempotente, conflit concurrent et rollback verifies ; aucun compte/droit applicatif modifie.'

# A second, fresh fixture exercises the exact disabled operator preparation.
docker exec "$DB" mysql -e 'DROP DATABASE cofrac_mymar_integration; DROP DATABASE MYMAR; DROP DATABASE cofrac2production;'
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/tests/mymar-booking-fixture.sql"
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
useradd -M ubuntu
test -e /usr/bin/php8.4 || ln -s /usr/bin/php /usr/bin/php8.4
mkdir -m 700 /backend-package
mkdir -p /etc/mysql
cp /workspace/tools/deploy/mymar/booking/* /backend-package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /backend-package/
cp /workspace/tools/deploy/mymar/workflow/MymarWorkflowAudit.php /backend-package/
cp /workspace/tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php /backend-package/
cp /workspace/app/Services/Mymar/MymarBookingCommand.php /workspace/app/Services/Mymar/MymarObservation.php /workspace/app/Services/Mymar/MymarObservationPolicy.php /backend-package/
php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-booking-preparation-fixture.php setup
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/preparation-source.before
for command in systemctl service composer artisan curl; do
    printf '#!/bin/sh\ntouch /fixtures/FORBIDDEN_COMMAND\nexit 99\n' > "/usr/bin/$command"
    chmod 700 "/usr/bin/$command"
done
cd /backend-package
chmod 600 *
sha256sum * > SHA256SUMS
SH
docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_historiques,MYMAR.clients,MYMAR.roles,MYMAR.model_has_roles,cofrac2production.users' > "$WORK/preparation.before"
docker exec "$PHP" bash -c 'cd /backend-package; bash collect-backend.sh --prepare-locked-booking-backend > /fixtures/preparation.log 2>&1'
docker exec "$PHP" php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-booking-preparation-fixture.php verify
docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_historiques,MYMAR.clients,MYMAR.roles,MYMAR.model_has_roles,cofrac2production.users' > "$WORK/preparation.after"
cmp "$WORK/preparation.before" "$WORK/preparation.after"
docker exec "$PHP" bash -c 'find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/preparation-source.after; cmp /fixtures/preparation-source.before /fixtures/preparation-source.after; test ! -e /fixtures/FORBIDDEN_COMMAND'
docker exec "$PHP" bash -c 'cd /backend-package; if bash collect-backend.sh --prepare-locked-booking-backend > /fixtures/preparation-repeat.log 2>&1; then exit 1; fi'
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM MYMAR.users WHERE email='cofrac-planning@integration.invalid' AND enabled=0")" = 1
test "$(docker exec "$DB" mysql -NBe "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'cofrac_mymar_rw_%' AND User<>'cofrac_mymar_rw_abcdef01' AND account_locked='Y'")" = 1
echo 'Preparation operateur et reexecution refusee : aucune modification des visites, dossiers, comptes existants, sources ou services.'
