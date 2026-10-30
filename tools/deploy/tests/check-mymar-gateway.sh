#!/usr/bin/env bash
# Local disposable containers only. No published port, production access or application bootstrap.
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
[ "${MYMAR_GATEWAY_TEST:-}" = 1 ] || { echo 'MYMAR_GATEWAY_TEST=1 requis, fixtures locales uniquement.' >&2; exit 1; }
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
MYSQL="cofrac-mymar-gateway-mysql-$SUFFIX"
PHP="cofrac-mymar-gateway-php-$SUFFIX"
SOCKET="cofrac-mymar-gateway-socket-$SUFFIX"
cleanup() {
    docker rm -fv "$PHP" "$MYSQL" >/dev/null 2>&1 || true
    docker volume rm "$SOCKET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker build --network none -t cofrac-mymar-gateway-test:local - <<'DOCKERFILE'
FROM php:8.5-fpm-bookworm
RUN docker-php-ext-install pdo_mysql
DOCKERFILE
docker volume create "$SOCKET" >/dev/null
docker run -d --name "$MYSQL" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46 >/dev/null
READY=0
for _ in $(seq 1 90); do
    if [ "$(docker exec "$MYSQL" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then READY=1; break; fi
    sleep 1
done
[ "$READY" = 1 ] || { echo 'MySQL jetable non disponible.' >&2; exit 1; }
docker run -d --name "$PHP" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" \
    --entrypoint sleep cofrac-mymar-gateway-test:local 300 >/dev/null
docker exec "$PHP" php /workspace/tools/deploy/tests/mymar-gateway-integration.php
