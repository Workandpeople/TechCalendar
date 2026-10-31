#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || stop 'sudo sur ns365601, sans argument.'
PACKAGE=$(cd "$(dirname "$0")" && pwd -P)
cd "$PACKAGE"; sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
exec 7>"$STATE/mymar-booking-ui.lock"; flock -n 7 || stop 'operation identique en cours.'
RUN=$(mktemp -d "$STATE/mymar-booking-ui-XXXXXXXX")
install -d -m 0700 "$RUN/bundle"
for file in control.php MymarRuntimeState.php MymarPendingBookingPage.php REQUEST.json; do
    install -o root -g root -m 0600 "$PACKAGE/reader-ui/$file" "$RUN/bundle/$file"
done
SUCCESS=0
finish() {
    result=$?; trap - EXIT
    if [ "$SUCCESS" != 1 ]; then
        if [ -f "$RUN/ownership.private.json" ] && [ -f /etc/cofrac2-mymar-booking/reader.json ]; then
            php8.4 "$RUN/bundle/control.php" --disable "$RUN" > "$RUN/safety-disable.log" 2>&1 || {
                printf 'STOP : desactivation a verifier dans safety-disable.log.\n' >&2; result=1;
            }
        fi
        printf 'Lot non confirme. Diagnostic prive : %s\nLe placement MAR reste desactive. Ne pas relancer sans revue.\n' "$RUN" >&2
    fi
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# Do not hold the release lock while invoking the existing, independently locked updater.
(
    exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement en cours.'
    exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'operation MYMAR en cours.'
    timeout --kill-after=3 45 php8.4 "$RUN/bundle/control.php" --check "$RUN"
)
printf 'Mise a jour applicative avec courte maintenance, puis reouverture des deux domaines.\n'
bash "$PACKAGE/deploy.sh"
(
    exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement concurrent.'
    exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'operation MYMAR concurrente.'
    REV=$(php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);echo $r["revision"];' "$RUN/bundle/REQUEST.json")
    [ "$(runuser -u cofrac2-deploy -- env HOME=/home/cofrac2-deploy git -C /var/www/cofrac2 rev-parse HEAD)" = "$REV" ] || stop 'code non conforme au lot.'
    for unit in cofrac2-php-fpm cofrac2-queue cofrac2-reverb cofrac-mymar-booking-fpm cofrac-mymar-booking-http; do
        systemctl is-active --quiet "$unit.service" || stop "service requis inactif : $unit"
    done
    timeout --kill-after=3 45 php8.4 "$RUN/bundle/control.php" --enable "$RUN"
    timeout --kill-after=3 20 runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app \
        php8.4 /var/www/cofrac2/artisan mymar:pending-probe --no-interaction --no-ansi > "$RUN/application-probe.json" 2> "$RUN/application-probe.stderr"
    timeout --kill-after=3 45 php8.4 "$RUN/bundle/control.php" --publish "$RUN"
)
cat > "$RUN/disable.sh" <<'DISABLE'
#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
RUN=$(cd "$(dirname "$0")" && pwd -P)
exec 7>/var/lib/cofrac2-production/mymar-booking-ui.lock
flock -n 7 || exit 1
php8.4 "$RUN/bundle/control.php" --disable "$RUN"
DISABLE
chmod 0600 "$RUN/disable.sh"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-booking-ui-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/summary.json" "$REPORT/summary.json"
(cd "$REPORT" && sha256sum summary.json > SHA256SUMS && chown ubuntu:ubuntu SHA256SUMS && chmod 0600 SHA256SUMS)
SUCCESS=1
printf 'REPORT_DIR=%s\nListe MAR disponible dans Prendre un RDV > RDV MAR. Aucun placement ni probleme active.\nDesactivation de cette seule liste : sudo bash %s/disable.sh\n' "$REPORT" "$RUN"
