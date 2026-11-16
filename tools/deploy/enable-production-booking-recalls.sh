#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] \
    && { [ "$1" = --enable ] || [ "$1" = --disable ]; } || stop 'sudo sur ns365601 avec --enable ou --disable.'
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ "$HERE" = "$APP/tools/deploy" ] && [ "$(realpath "$APP")" = "$APP" ] \
    && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'chemins V2 inattendus.'
exec 8>"$STATE/two-lots-release.lock"
flock -n 8 || stop 'un deploiement est en cours.'
for suffix in service timer; do
    unit="/etc/systemd/system/cofrac2-booking-recalls.$suffix"
    [ ! -L "$unit" ] || stop 'unite indirecte refusee.'
    if [ -e "$unit" ]; then cmp -s "$unit" "$HERE/cofrac2-booking-recalls.$suffix.example" || stop 'unite existante differente.'; fi
done
if [ "$1" = --disable ]; then
    systemctl disable --now cofrac2-booking-recalls.timer
    systemctl stop cofrac2-booking-recalls.service
    printf 'Seul le traitement automatique des rappels est arrete ; site et donnees conserves.\n'
    exit 0
fi
git_app() { runuser -u cofrac2-deploy -- env HOME=/home/cofrac2-deploy git -C "$APP" "$@"; }
[ "$(git_app branch --show-current)" = v2 ] && [ -z "$(git_app status --porcelain)" ] \
    && [ -f "$STATE/INTERNAL-SITE-OPEN.json" ] || stop 'version V2 propre et deja ouverte requise.'
REV=$(git_app rev-parse HEAD)
php8.4 -r '$p=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(($p["revision"]??null)!==$argv[2])exit(1);' \
    "$STATE/RUNTIME-PREPARED.json" "$REV" || stop 'revision runtime differente.'
RUN=$(mktemp -d "$STATE/booking-recalls-XXXXXXXX")
activated=0
finish() {
    result=$?; trap - EXIT
    if [ "$result" != 0 ]; then
        if [ "$activated" = 1 ]; then systemctl disable --now cofrac2-booking-recalls.timer >/dev/null 2>&1 || true; fi
        printf 'Activation non confirmee. Site inchange. Diagnostic prive : %s\n' "$RUN" >&2
    fi
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
app_cli() { runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app php8.4 "$@"; }
app_cli > "$RUN/probe.json" <<'PHP'
<?php
chdir('/var/www/cofrac2'); require 'vendor/autoload.php';
$app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('production') || !config('crm.v2_enabled') || config('simulation.enabled')
    || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'cofrac2production'
    || !Illuminate\Support\Facades\Schema::hasColumns('dossier_operations', ['booking_recall_at','booking_recall_activity_id'])) exit(1);
echo json_encode(['ready'=>true,'business_writes'=>false,'messages_sent'=>false]).PHP_EOL;
PHP
app_cli "$APP/artisan" dossiers:process-recalls --limit=1 --no-interaction --no-ansi > "$RUN/dry-run.json"
for suffix in service timer; do
    install -o root -g root -m 0644 "$HERE/cofrac2-booking-recalls.$suffix.example" "/etc/systemd/system/cofrac2-booking-recalls.$suffix"
done
systemd-analyze verify /etc/systemd/system/cofrac2-booking-recalls.{service,timer} > "$RUN/units-check.log" 2>&1
systemctl daemon-reload
activated=1
systemctl enable --now cofrac2-booking-recalls.timer > "$RUN/enable.log" 2>&1
systemctl start cofrac2-booking-recalls.service
systemctl is-active --quiet cofrac2-booking-recalls.timer
printf 'Rappels automatiques actifs, verification chaque minute. Aucun schedule:run general active.\n'
printf 'Anciens rappels : commencer par dossiers:process-recalls --backfill --limit=2000 (lecture seule).\n'
printf 'Diagnostic prive : %s\n' "$RUN"
