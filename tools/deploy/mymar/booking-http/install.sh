#!/usr/bin/env bash
# Only a new SELECT-only SQL account and a new loopback Apache/FPM pair. No application deployment.
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] \
    && [ "$1" = --install-reader-only ] || stop 'sudo sur ns365601 avec --install-reader-only.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"; sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
for command in php8.4 php-fpm8.4 apache2 mysql timeout systemctl systemd-analyze ss flock install getent useradd; do
    command -v "$command" >/dev/null || stop "outil absent : $command"
done
for module in mpm_event authz_core authz_host proxy proxy_fcgi reqtimeout; do
    [ -f "/usr/lib/apache2/modules/mod_$module.so" ] || stop "module Apache absent : $module"
done
[ -z "$(ss -H -ltn 'sport = :19847')" ] || stop 'port 19847 deja utilise.'
if getent passwd cofrac-mymar-booking >/dev/null || getent group cofrac-mymar-booking >/dev/null; then stop 'compte systeme deja present.'; fi
for unit in cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service; do
    [ "$(systemctl show "$unit" -p LoadState --value)" = not-found ] || stop 'unite deja presente.'
done
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'operation MYMAR en cours.'
RUN=$(mktemp -d "$STATE/mymar-booking-http-XXXXXXXX")
UNITS_WRITTEN=0 SUCCESS=0
finish() {
    code=$?
    trap - EXIT
    if [ "$SUCCESS" != 1 ]; then
        if [ "$UNITS_WRITTEN" = 1 ]; then
            systemctl disable --now cofrac-mymar-booking-http.service cofrac-mymar-booking-fpm.service > "$RUN/stop.log" 2>&1 || code=1
        fi
        if [ -f "$RUN/rollback-account.private.json" ]; then
            timeout --kill-after=3 30 php8.4 "$BASE/control.php" --disable "$RUN" > "$RUN/safety-lock.log" 2>&1 || {
                printf 'STOP : verrouillage du nouveau lecteur a verifier dans safety-lock.log.\n' >&2; code=1;
            }
        fi
        printf 'Installation non confirmee. Diagnostic prive : %s\nNe pas relancer sans revue.\n' "$RUN" >&2
    fi
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
install -d -m 0700 "$RUN/bundle"
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] \
        && [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'fichier de paquet inattendu.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done < SHA256SUMS
install -o root -g root -m 0600 SHA256SUMS "$RUN/bundle/SHA256SUMS"
BASE="$RUN/bundle"
(cd "$BASE" && sha256sum -c SHA256SUMS)
SHARED=(apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service php8.3-fpm.service php8.4-fpm.service cofrac2-php-fpm.service cofrac2-queue.service cofrac2-reverb.service cofrac-mymar-fpm.service cofrac-mymar-http.service cofrac2-mymar-observation.timer)
shared_state() { systemctl show "${SHARED[@]}" -p Id -p MainPID -p ExecMainStartTimestampMonotonic -p ActiveState -p SubState; }
apache_state() { find /etc/apache2 -type f -exec sha256sum {} + | sort; }
shared_state > "$RUN/services.before"
apache_state > "$RUN/apache.before"
timeout --kill-after=3 90 php8.4 "$BASE/control.php" --preflight "$RUN"
useradd --system --user-group --no-create-home --home-dir /var/lib/cofrac-mymar-booking-gateway --shell /usr/sbin/nologin cofrac-mymar-booking
timeout --kill-after=3 60 php8.4 "$BASE/control.php" --stage "$RUN"
install -d -o root -g root -m 0755 /run/cofrac-mymar-booking-fpm /run/cofrac-mymar-booking-http
php-fpm8.4 --test --fpm-config /etc/cofrac-mymar-booking-gateway/php-fpm.conf > "$RUN/fpm-check.log" 2>&1
apache2 -t -f /etc/cofrac-mymar-booking-gateway/apache.conf > "$RUN/apache-check.log" 2>&1
for unit in cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service; do
    install -o root -g root -m 0644 "$RUN/$unit" "/etc/systemd/system/$unit"
done
UNITS_WRITTEN=1
systemd-analyze verify /etc/systemd/system/cofrac-mymar-booking-{fpm,http}.service > "$RUN/units-check.log" 2>&1
systemctl daemon-reload
php8.4 "$BASE/control.php" --unlock "$RUN"
printf 'Demarrage des seuls nouveaux services de lecture MAR. Aucun rechargement du site.\n'
systemctl start cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service
READY=0
for _ in $(seq 1 30); do
    if [ -S /run/cofrac-mymar-booking-fpm/php.sock ] && [ -n "$(ss -H -ltn 'src 127.0.0.1 and sport = :19847')" ]; then READY=1; break; fi
    sleep 1
done
[ "$READY" = 1 ] && [ "$(ss -H -ltn 'sport = :19847' | wc -l)" = 1 ] || stop 'ecoute privee non confirmee.'
timeout --kill-after=3 90 php8.4 "$BASE/control.php" --observe "$RUN"
shared_state > "$RUN/services.after"; apache_state > "$RUN/apache.after"
cmp "$RUN/services.before" "$RUN/services.after" || stop 'service existant modifie pendant le controle.'
cmp "$RUN/apache.before" "$RUN/apache.after" || stop 'configuration Apache existante modifiee.'
systemctl enable cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service > "$RUN/enable.log" 2>&1
php8.4 "$BASE/control.php" --publish "$RUN"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-booking-http-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/summary.json" "$REPORT/summary.json"
(cd "$REPORT" && sha256sum summary.json > SHA256SUMS && chown ubuntu:ubuntu SHA256SUMS && chmod 0600 SHA256SUMS)
SUCCESS=1
printf 'REPORT_DIR=%s\nLecteur interne actif, POST desactive et compte de placement toujours verrouille.\nArret : sudo bash %s/bundle/stop.sh\n' "$REPORT" "$RUN"
