#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "$#" -eq 2 ] || [ "$#" -eq 3 ] || [ "$#" -eq 5 ] || stop 'Usage : bash rehearse.sh DATASET_DIRECTORY NEW_REPORT_DIRECTORY [DOCUMENT_PLAN_DIRECTORY|-] [PLANNING_DIRECTORY SHA256]'
[ "$(hostname -s)" != ns365601 ] || stop 'repetition locale uniquement.'
HERE=$(cd -- "$(dirname -- "$0")" && pwd)
REPO=$(cd -- "$HERE/../../.." && pwd -P)
DATASET=$(cd -- "$1" && pwd -P)
OUTPUT=$2
PLAN_MOUNTS=()
PLAN_ARGUMENTS=()
if [ "$#" -ge 3 ] && [ "$3" != - ]; then
    [ -d "$3" ] && [ ! -L "$3" ] || stop 'plan documentaire absent ou symbolique.'
    PLAN=$(cd -- "$3" && pwd -P)
    PLAN_MOUNTS=(--mount "type=bind,source=$PLAN,target=/document-plan,readonly")
    PLAN_ARGUMENTS=(/document-plan)
fi
if [ "$#" -eq 5 ]; then
    [ -d "$4" ] && [ ! -L "$4" ] || stop 'collecte planning absente ou symbolique.'
    [[ "$5" =~ ^[a-f0-9]{64}$ ]] || stop 'empreinte planning attendue invalide.'
    PLANNING=$(cd -- "$4" && pwd -P)
    PLAN_MOUNTS+=(--mount "type=bind,source=$PLANNING,target=/planning,readonly")
    if [ "$3" = - ]; then PLAN_ARGUMENTS=(-); fi
    PLAN_ARGUMENTS+=(/planning "$5")
fi
[ ! -e "$OUTPUT" ] && [ ! -L "$OUTPUT" ] || stop 'sortie deja presente.'
docker image inspect mysql:8.0.46 >/dev/null
docker image inspect sail-8.5/app:latest >/dev/null
mkdir -m 0700 -- "$OUTPUT"
OUTPUT=$(cd -- "$OUTPUT" && pwd -P)
CONTAINER="cofrac2-native-rehearsal-$(date +%s)-$$"
PHP_CONTAINER="$CONTAINER-php"
created=0
cleanup() {
    docker rm -fv "$PHP_CONTAINER" >/dev/null 2>&1 || true
    if [ "$created" = 1 ]; then docker rm -fv "$CONTAINER" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker run -d --name "$CONTAINER" --network none --memory=2g --cpus=2 --pids-limit=128 \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=cofrac2_native_rehearsal \
    -e MYSQL_USER=cofrac2_rehearsal -e MYSQL_PASSWORD=offline-rehearsal-only \
    mysql:8.0.46 --event-scheduler=OFF --local-infile=0 --secure-file-priv=NULL --skip-log-bin >/dev/null
created=1
ready=0
for attempt in $(seq 1 90); do
    if docker exec "$CONTAINER" mysql --protocol=tcp -h127.0.0.1 -uroot -NBe 'SELECT 1' cofrac2_native_rehearsal >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[ "$ready" = 1 ] || stop 'MySQL natif jetable indisponible.'
printf 'Import natif de repetition, sans reseau externe, sans port publie, sans .env local.\n'
docker run --name "$PHP_CONTAINER" --network "container:$CONTAINER" --memory=2g --cpus=2 --pids-limit=128 \
    --read-only --tmpfs /tmp:rw,nosuid,nodev,size=256m --entrypoint php \
    --mount "type=bind,source=$REPO,target=/app,readonly" \
    --mount "type=bind,source=$DATASET,target=/dataset,readonly" \
    --mount "type=bind,source=$OUTPUT,target=/report" \
    "${PLAN_MOUNTS[@]}" \
    -e APP_ENV=testing -e APP_DEBUG=false -e COFFRAC_OFFLINE_REHEARSAL=true \
    -e APP_CONFIG_CACHE=/tmp/config.php -e APP_SERVICES_CACHE=/tmp/services.php -e APP_PACKAGES_CACHE=/tmp/packages.php \
    -e APP_ROUTES_CACHE=/tmp/routes.php -e APP_EVENTS_CACHE=/tmp/events.php \
    -e DB_CONNECTION=mysql -e DB_HOST=127.0.0.1 -e DB_PORT=3306 -e DB_DATABASE=cofrac2_native_rehearsal \
    -e DB_USERNAME=cofrac2_rehearsal -e DB_PASSWORD=offline-rehearsal-only -e DB_URL= \
    -e CRM_V2_ENABLED=true -e SIMULATION_MODE=false -e SMSFACTOR_ENABLED=false -e CAPCEE_ENABLED=false \
    -e MAIL_MAILER=log -e LOG_CHANNEL=stderr -e CACHE_STORE=array -e QUEUE_CONNECTION=database \
    -e SESSION_DRIVER=array -e BROADCAST_CONNECTION=log \
    sail-8.5/app:latest -d memory_limit=1536M /app/tools/fixtures/production/rehearse.php /dataset /report/report.json "${PLAN_ARGUMENTS[@]}" \
    > "$OUTPUT/summary.json" 2> "$OUTPUT/rehearsal.stderr"
cat "$OUTPUT/summary.json"
printf 'Repetition terminee. Rapport prive : %s/report.json\n' "$OUTPUT"
printf 'Conteneurs et volumes jetables supprimes a la sortie ; aucune base existante contactee.\n'
