#!/usr/bin/env bash
# Tests run only in disposable networkless containers, with the repository mounted read-only.
set -Eeuo pipefail
[ "${APPOINTMENT_ADDRESS_TEST:-}" = 1 ] || { echo 'APPOINTMENT_ADDRESS_TEST=1 requis, conteneurs jetables uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/cofrac-appointment-address-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="cofrac-address-db-$SUFFIX" PHP="cofrac-address-php-$SUFFIX" SOCKET="cofrac-address-socket-$SUFFIX"
cleanup() {
    result=$?
    docker rm -fv "$PHP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$SOCKET" >/dev/null 2>&1 || true
    printf 'MYSQL_TEST_EXIT=%s DIAGNOSTIC=%s\n' "$result" "$WORK"
    exit "$result"
}
trap cleanup EXIT
docker image inspect cofrac2-runtime-check:local >/dev/null
docker image inspect mysql:8.4 >/dev/null
docker volume create "$SOCKET" >/dev/null
docker run -d --pull=never --name "$DB" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.4 >/dev/null
for _ in $(seq 1 90); do
    [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ] && break
    sleep 1
done
docker exec "$DB" mysql -e 'CREATE DATABASE appointment_address_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
docker run --rm --pull=never --name "$PHP" --network none --tmpfs /workspace/storage \
    --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" \
    -e APP_ENV=testing -e DB_CONNECTION=mysql -e DB_DATABASE=appointment_address_test -e DB_USERNAME=root -e DB_PASSWORD= -e DB_URL= \
    -e DB_SOCKET=/var/run/mysqld/mysqld.sock -e LOG_CHANNEL=stderr -e VIEW_COMPILED_PATH=/tmp/views \
    -e APP_CONFIG_CACHE=/tmp/test-config.php -e APP_ROUTES_CACHE=/tmp/test-routes.php \
    --entrypoint php -w /workspace cofrac2-runtime-check:local \
    -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/appointment-address.php > "$WORK/result.log" 2>&1
tail -1 "$WORK/result.log"
