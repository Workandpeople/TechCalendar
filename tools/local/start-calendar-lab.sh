#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT=$(cd "$(dirname "$0")/../.." && pwd -P)
PORT=${1:-8808}
if ! [[ "$PORT" =~ ^[0-9]{4,5}$ ]] || (( PORT < 1024 || PORT > 65535 )); then
    echo "Port local invalide." >&2
    exit 1
fi
cd "$ROOT"
test -f vendor/autoload.php
if [[ ${2:-} != --no-build ]]; then npm run build; fi
test -f public/build/manifest.json

LAB=$(mktemp -d "${TMPDIR:-/tmp}/cofrac-calendar-lab-XXXXXXXX")
trap 'rm -rf -- "$LAB"' EXIT
mkdir -p "$LAB/storage/framework/views" "$LAB/storage/framework/cache/data" "$LAB/storage/framework/sessions" "$LAB/storage/logs"
KEY=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
echo "Calendrier fictif : http://127.0.0.1:$PORT/local/calendar"
echo "Base SQLite en memoire, aucun acces a votre .env, aucun envoi. Ctrl+C pour arreter."

env -i PATH="$PATH" HOME="$HOME" \
    COFRAC_CALENDAR_LAB=1 COFRAC_LAB_STORAGE="$LAB/storage" \
    APP_ENV=local APP_NAME='Genius Controle' APP_DEBUG=false APP_KEY="$KEY" APP_URL="http://127.0.0.1:$PORT" \
    APP_CONFIG_CACHE="$LAB/config.php" APP_ROUTES_CACHE="$LAB/routes.php" \
    DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' \
    SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync \
    MAIL_MAILER=array MAIL_DELIVERY_ENABLED=false BROADCAST_CONNECTION=null \
    SIMULATION_MODE=true SIMULATION_ALLOW_EXTERNAL_APIS=false SMSFACTOR_ENABLED=false \
    php -S "127.0.0.1:$PORT" -t "$ROOT/public" "$ROOT/tools/local/calendar-lab-router.php"
