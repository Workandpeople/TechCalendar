#!/usr/bin/env bash
# Explicit pilot activation. No production placement/problem/message is used as a test.
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] && [ "$1" = --enable-pilot-actions ] || stop 'sudo sur ns365601 avec --enable-pilot-actions.'
PACKAGE=$(cd "$(dirname "$0")" && pwd -P)
cd "$PACKAGE"; sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
for command in php8.4 php-fpm8.4 systemctl systemd-analyze timeout flock ss useradd install runuser git; do command -v "$command" >/dev/null || stop "outil absent : $command"; done
for unit in cofrac-mymar-fpm.service cofrac-mymar-http.service cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service; do
    systemctl is-active --quiet "$unit" || stop "lecteur MAR inactif : $unit";
done
exec 7>"$STATE/mymar-actions.lock"; flock -n 7 || stop 'activation identique en cours.'
[ -z "$(ss -H -ltn 'sport = :19848')" ] || stop 'port 19848 deja occupe.'
if getent passwd cofrac-mymar-actions >/dev/null || getent group cofrac-mymar-actions >/dev/null; then stop 'compte systeme deja present.'; fi
for unit in cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service; do
    [ "$(systemctl show "$unit" -p LoadState --value)" = not-found ] || stop 'unite deja presente.'
    [ ! -e "/etc/systemd/system/$unit" ] && [ ! -L "/etc/systemd/system/$unit" ] || stop 'fichier unite deja present.'
done
RUN=$(mktemp -d "$STATE/mymar-actions-XXXXXXXX")
install -d -m 0700 "$RUN/bundle"
for file in "$PACKAGE/actions/"*; do [ -f "$file" ] && [ ! -L "$file" ] || stop 'fichier inattendu.'; install -o root -g root -m 0600 "$file" "$RUN/bundle/"; done
SUCCESS=0 UNITS_WRITTEN=0
finish() {
    result=$?; trap - EXIT
    if [ "$SUCCESS" != 1 ]; then
        if [ -f "$RUN/ownership.private.json" ]; then
            php8.4 "$RUN/bundle/control.php" --disable "$RUN" > "$RUN/disable.log" 2>&1 || result=1
            if [ "$UNITS_WRITTEN" = 1 ]; then systemctl disable --now cofrac-mymar-actions-http.service cofrac-mymar-actions-fpm.service > "$RUN/stop.log" 2>&1 || result=1; fi
            php8.4 "$RUN/bundle/control.php" --lock "$RUN" > "$RUN/lock.log" 2>&1 || result=1
        fi
        printf 'Activation non confirmee. Diagnostic prive : %s\nNe pas relancer ; ne pas restaurer la base. Les lecteurs MAR existants ne sont pas arretes.\n' "$RUN" >&2
    fi
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
(
    exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement en cours.'
    exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'operation MYMAR en cours.'
    timeout --kill-after=3 90 php8.4 "$RUN/bundle/control.php" --check "$RUN"
    useradd --system --user-group --no-create-home --home-dir /var/lib/cofrac-mymar-actions-gateway --shell /usr/sbin/nologin cofrac-mymar-actions
    php8.4 "$RUN/bundle/control.php" --stage "$RUN"
    install -d -o root -g root -m 0755 /run/cofrac-mymar-actions-fpm /run/cofrac-mymar-actions-http
    php-fpm8.4 --test --fpm-config /etc/cofrac-mymar-actions-gateway/php-fpm.conf > "$RUN/fpm-check.log" 2>&1
    /usr/sbin/apache2 -t -f /etc/cofrac-mymar-actions-gateway/apache.conf > "$RUN/apache-check.log" 2>&1
    for unit in cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service; do install -o root -g root -m 0644 "$RUN/$unit" "/etc/systemd/system/$unit"; done
    systemd-analyze verify /etc/systemd/system/cofrac-mymar-actions-{fpm,http}.service > "$RUN/units-check.log" 2>&1
)
UNITS_WRITTEN=1
systemctl daemon-reload
bash "$PACKAGE/deploy.sh"
(
    exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement concurrent.'
    exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'operation MYMAR concurrente.'
    REV=$(cat "$PACKAGE/revision.txt")
    [ "$(runuser -u cofrac2-deploy -- env HOME=/home/cofrac2-deploy git -C /var/www/cofrac2 rev-parse HEAD)" = "$REV" ] || stop 'revision inattendue.'
    MIGRATION=tools/deploy/mymar/booking/inactive-migrations/2026_10_31_110000_create_mymar_booking_requests_table.php
    printf 'Creation du seul journal technique V2, sans copie de dossier MAR ni import...\n'
    timeout --kill-after=3 90 runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app \
        php8.4 /var/www/cofrac2/artisan migrate --path="$MIGRATION" --force --no-interaction > "$RUN/journal-migration.log" 2>&1
    php8.4 "$RUN/bundle/control.php" --app "$RUN"
    php8.4 "$RUN/bundle/control.php" --unlock "$RUN"
    systemctl start cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service
    for _ in $(seq 1 30); do
        if [ -S /run/cofrac-mymar-actions-fpm/php.sock ] && [ -n "$(ss -H -ltn 'src 127.0.0.1 and sport = :19848')" ]; then break; fi
        sleep 1
    done
    [ -S /run/cofrac-mymar-actions-fpm/php.sock ] && [ "$(ss -H -ltn 'sport = :19848' | wc -l)" = 1 ] || stop 'passerelle non prete.'
    timeout --kill-after=3 30 php8.4 "$RUN/bundle/control.php" --probe "$RUN"
    timeout --kill-after=3 90 runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app \
        php8.4 /var/www/cofrac2/artisan mymar:actions-check --no-interaction --no-ansi > "$RUN/application-probe.json" 2> "$RUN/application-probe.stderr"
    php8.4 "$RUN/bundle/control.php" --enable "$RUN"
    systemctl enable cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service > "$RUN/enable.log" 2>&1
)
cat > "$RUN/stop.sh" <<'STOP'
#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
RUN=$(cd "$(dirname "$0")" && pwd -P)
exec 7>/var/lib/cofrac2-production/mymar-actions.lock; flock -n 7 || exit 1
php8.4 "$RUN/bundle/control.php" --disable "$RUN"
result=0
systemctl disable --now cofrac-mymar-actions-http.service cofrac-mymar-actions-fpm.service || result=1
php8.4 "$RUN/bundle/control.php" --lock "$RUN" || result=1
[ "$result" = 0 ] || { printf 'Arret non entierement confirme ; verifier les services et le verrou SQL.\n' >&2; exit 1; }
printf 'Actions MAR fermees, reservations conservees ; lecteurs et sites inchanges.\n'
STOP
chmod 0600 "$RUN/stop.sh"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-actions-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/summary.json" "$REPORT/summary.json"
(cd "$REPORT" && sha256sum summary.json > SHA256SUMS && chown ubuntu:ubuntu SHA256SUMS && chmod 0600 SHA256SUMS)
SUCCESS=1
printf 'REPORT_DIR=%s\nPilote actif : placement et probleme RDV MAR. Aucun placement de test, mail ou SMS envoye.\nArret des seules actions : sudo bash %s/stop.sh\n' "$REPORT" "$RUN"
