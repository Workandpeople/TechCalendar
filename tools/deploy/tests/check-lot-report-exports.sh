#!/usr/bin/env bash
# Synthetic database/files only, in disposable containers without network access.
set -Eeuo pipefail
[ "${COFRAC_REPORT_EXPORTS_TEST:-}" = 1 ] || { echo 'COFRAC_REPORT_EXPORTS_TEST=1 requis, test local uniquement.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/cofrac-report-exports-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="cofrac-report-export-db-$SUFFIX" PHP="cofrac-report-export-php-$SUFFIX" SOCKET="cofrac-report-export-socket-$SUFFIX"
cleanup() {
    result=$?
    docker cp "$PHP:/fixtures/tests.log" "$WORK/tests.log" >/dev/null 2>&1 || true
    if [ "$result" != 0 ]; then tail -n 60 "$WORK/tests.log" 2>/dev/null || true; fi
    docker rm -fv "$PHP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$SOCKET" >/dev/null 2>&1 || true
    printf 'Diagnostic local : %s\n' "$WORK"
    exit "$result"
}
trap cleanup EXIT
docker image inspect cofrac2-runtime-check:local mysql:8.0.46 >/dev/null
docker volume create "$SOCKET" >/dev/null
docker run -d --name "$DB" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46 >/dev/null
for _ in $(seq 1 90); do
    if [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
[ "$(docker exec "$DB" mysql -NBe 'SELECT @@port')" = 3306 ]
docker exec "$DB" mysql -e 'CREATE DATABASE cofrac_exports_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
docker run -d --name "$PHP" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" --entrypoint sleep cofrac2-runtime-check:local 600 >/dev/null
docker exec "$PHP" mkdir -p /fixtures/storage/framework/views /fixtures/storage/framework/cache/data /fixtures/storage/logs
docker exec -w /workspace \
    -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    -e APP_CONFIG_CACHE=/fixtures/config.php -e APP_ROUTES_CACHE=/fixtures/routes.php -e LARAVEL_STORAGE_PATH=/fixtures/storage \
    -e DB_CONNECTION=mysql -e DB_HOST=localhost -e DB_SOCKET=/var/run/mysqld/mysqld.sock \
    -e DB_DATABASE=cofrac_exports_fixture -e DB_USERNAME=root -e DB_PASSWORD= -e DB_URL= \
    "$PHP" bash -c 'php -d memory_limit=768M vendor/bin/pest --cache-directory /fixtures/pest-cache --compact tests/Feature/LotReportExportsTest.php tests/Feature/ReportDownloadNamingTest.php tests/Feature/DossierLotReferenceTest.php > /fixtures/tests.log 2>&1'
docker exec "$PHP" cp /workspace/tools/deploy/cofrac2-report-exports.service.example /fixtures/cofrac2-report-exports.service
docker exec "$PHP" bash -c 'test -x /usr/bin/php8.4 || ln -s "$(command -v php)" /usr/bin/php8.4'
docker exec "$PHP" systemd-analyze verify /fixtures/cofrac2-report-exports.service
tail -n 5 "$WORK/tests.log" 2>/dev/null || docker exec "$PHP" tail -n 5 /fixtures/tests.log
echo 'MySQL 8.0.46 : selection exacte, ZIP prives, renommage, revocations et limites verifies hors production.'
