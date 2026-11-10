#!/usr/bin/env bash
# Mutations are confined to disposable, networkless containers, never a live database.
set -Eeuo pipefail
[ "${DOSSIER_DUPLICATE_TEST:-}" = 1 ] || { echo 'DOSSIER_DUPLICATE_TEST=1 requis, conteneurs jetables uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/cofrac-duplicates-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="cofrac-duplicates-db-$SUFFIX" PHP="cofrac-duplicates-php-$SUFFIX" SOCKET="cofrac-duplicates-socket-$SUFFIX"
cleanup() {
    result=$?
    docker rm -fv "$PHP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$SOCKET" >/dev/null 2>&1 || true
    printf 'MYSQL_TEST_EXIT=%s DIAGNOSTIC=%s\n' "$result" "$WORK"
    exit "$result"
}
trap cleanup EXIT
docker image inspect cofrac2-runtime-check:local >/dev/null
docker volume create "$SOCKET" >/dev/null
docker run -d --name "$DB" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46 >/dev/null
for _ in $(seq 1 90); do
    [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ] && break
    sleep 1
done
docker exec "$DB" mysql -e 'CREATE DATABASE dossier_duplicate_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
docker run -d --name "$PHP" --network none --tmpfs /workspace/storage --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" --mount "type=bind,source=$WORK,target=/fixtures" \
    -e APP_ENV=testing -e DB_CONNECTION=mysql -e DB_DATABASE=dossier_duplicate_test -e DB_USERNAME=root -e DB_PASSWORD= -e DB_URL= \
    -e DB_SOCKET=/var/run/mysqld/mysqld.sock -e LOG_CHANNEL=stderr -e VIEW_COMPILED_PATH=/tmp/views \
    -e APP_CONFIG_CACHE=/tmp/test-config.php -e APP_ROUTES_CACHE=/tmp/test-routes.php \
    --entrypoint sleep cofrac2-runtime-check:local 300 >/dev/null
run() { docker exec -w /workspace "$PHP" php -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/dossier-duplicate-race.php "$@"; }
run setup > "$WORK/setup.log" 2>&1
run a > "$WORK/a.log" 2>&1 & A=$!
run b > "$WORK/b.log" 2>&1 & B=$!
wait "$A"; wait "$B"
run check > "$WORK/result.json" 2> "$WORK/check.log"
cat "$WORK/result.json"
