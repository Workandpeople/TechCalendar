#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] \
    && [ "$1" = --enable-observation-only ] || stop 'sudo sur ns365601 avec --enable-observation-only.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
cd "$BASE"; sha256sum -c SETUP-SHA256SUMS
for command in php8.4 mysql systemctl systemd-analyze timeout runuser flock install; do command -v "$command" >/dev/null || stop "outil absent : $command"; done
for unit in cofrac2-mymar-observation.service cofrac2-mymar-observation.timer; do
    [ "$(systemctl show "$unit" -p LoadState --value)" = not-found ] || stop "unite pilote deja presente : $unit"
done
systemctl is-active --quiet cofrac-mymar-http.service cofrac-mymar-fpm.service || stop 'API MYMAR privee non active.'
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'un deploiement est en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'une preparation MYMAR est en cours.'
RUN=$(mktemp -d "$STATE/mymar-cache-pilot-XXXXXXXX")
UNITS_WRITTEN=0 STAGED=0 SUCCESS=0
finish() {
    code=$?; trap - EXIT
    if [ "$SUCCESS" != 1 ]; then
        if [ "$UNITS_WRITTEN" = 1 ]; then systemctl disable --now cofrac2-mymar-observation.timer cofrac2-mymar-observation.service > "$RUN/stop.log" 2>&1 || code=1; fi
        if [ "$STAGED" = 1 ]; then php8.4 "$BASE/pilot-control.php" --disable "$RUN" > "$RUN/disable.log" 2>&1 || code=1; fi
        printf 'Pilote non confirme ; site et API prives non arretes. Diagnostic prive : %s\n' "$RUN" >&2
    fi
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
install -d -m 0700 "$RUN/bundle"
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] && [ ! -L "$BASE/$file" ] || stop 'entree de paquet inattendue.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done < SETUP-SHA256SUMS
install -o root -g root -m 0600 SETUP-SHA256SUMS "$RUN/bundle/SETUP-SHA256SUMS"
BASE="$RUN/bundle"; (cd "$BASE" && sha256sum -c SETUP-SHA256SUMS)
shared_state() { systemctl show apache2.service mysql.service cofrac2-php-fpm.service cofrac2-queue.service cofrac2-reverb.service cofrac-mymar-fpm.service cofrac-mymar-http.service -p Id -p MainPID -p ExecMainStartTimestampMonotonic -p ActiveState; }
shared_state > "$RUN/services.before"
timeout --kill-after=3 60 php8.4 "$BASE/pilot-control.php" --prepare "$RUN"
STAGED=1
php8.4 "$BASE/pilot-control.php" --stage "$RUN"
app_cli() { runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app php8.4 /var/www/cofrac2/artisan "$@" --no-interaction --no-ansi; }
printf 'Premiere synchronisation en cache uniquement ; affichage encore masque...\n'
app_cli mymar:sync-observation > "$RUN/first-sync.json" 2> "$RUN/first-sync.stderr"
app_cli mymar:observation-status > "$RUN/cache-status.json" 2> "$RUN/cache-status.stderr"
for unit in cofrac2-mymar-observation.service cofrac2-mymar-observation.timer; do install -o root -g root -m 0644 "$BASE/$unit" "/etc/systemd/system/$unit"; done
UNITS_WRITTEN=1
systemd-analyze verify /etc/systemd/system/cofrac2-mymar-observation.{service,timer} > "$RUN/units-check.log" 2>&1
systemctl daemon-reload
php8.4 "$BASE/pilot-control.php" --display "$RUN"
systemctl enable --now cofrac2-mymar-observation.timer > "$RUN/enable.log" 2>&1
systemctl start cofrac2-mymar-observation.service
systemctl is-active --quiet cofrac2-mymar-observation.timer
app_cli mymar:observation-status > "$RUN/cache-status.json" 2> "$RUN/cache-status.stderr"
shared_state > "$RUN/services.after"; cmp "$RUN/services.before" "$RUN/services.after" || stop 'un service existant a change pendant la configuration du pilote.'
php8.4 "$BASE/pilot-control.php" --report "$RUN"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-cache-pilot-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/summary.json" "$REPORT/summary.json"
(cd "$REPORT" && sha256sum summary.json > SHA256SUMS && chown ubuntu:ubuntu SHA256SUMS && chmod 0600 SHA256SUMS)
SUCCESS=1
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Pilote cache/affichage actif ; aucun blocage de creneau. Arret : sudo bash %s/bundle/stop-pilot.sh\n' "$RUN"
