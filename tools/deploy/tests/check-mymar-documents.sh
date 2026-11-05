#!/usr/bin/env bash
# Only synthetic files and databases in disposable containers, without a network.
set -Eeuo pipefail
[ "${MYMAR_DOCUMENTS_TEST:-}" = 1 ] || { echo 'MYMAR_DOCUMENTS_TEST=1 requis, test local uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-documents-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="mymar-documents-db-$SUFFIX" PHP="mymar-documents-php-$SUFFIX" SOCKET="mymar-documents-socket-$SUFFIX"
cleanup() {
    result=$?
    docker cp "$PHP:/fixtures" "$WORK/reports" >/dev/null 2>&1 || true
    if [ "$result" != 0 ]; then tail -n 40 "$WORK/reports/documents.log" 2>/dev/null || true; fi
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
docker exec -i "$DB" mysql --default-character-set=utf8mb4 < "$ROOT/tools/deploy/tests/mymar-documents-fixture.sql"
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/mymar/documents/journal.sql"
docker run -d --name "$PHP" --network none \
    --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" \
    --entrypoint sleep cofrac2-runtime-check:local 300 >/dev/null
docker exec "$PHP" mkdir -m 700 /fixtures
docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.clients,MYMAR.roles,MYMAR.model_has_roles' > "$WORK/before"
docker exec "$PHP" bash -c 'php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-documents-fixture.php > /fixtures/documents.log 2>&1'
docker exec "$PHP" bash -c '
set -Eeuo pipefail
php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-documents-fixture.php race /fixtures/race-one-command.json > /fixtures/race-one.json 2>/fixtures/race-one.log &
a=$!
php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-documents-fixture.php race /fixtures/race-two-command.json > /fixtures/race-two.json 2>/fixtures/race-two.log &
b=$!
wait "$a"; wait "$b"
php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-documents-fixture.php verify-race >> /fixtures/documents.log
'
docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.clients,MYMAR.roles,MYMAR.model_has_roles' > "$WORK/after"
cmp "$WORK/before" "$WORK/after"
docker exec "$PHP" cat /fixtures/documents.log
echo 'MySQL 8.0.46 : lecture limitee, autorisation courante, transaction VT2 et reprise idempotente verifies hors production.'
