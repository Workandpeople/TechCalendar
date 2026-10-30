#!/usr/bin/env bash
# Installs only a new loopback Apache/FPM pair. Never reload the shared Apache, SQL or application services.
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] \
    && [ "$1" = --install-private-http-and-observe ] || stop 'sudo sur ns365601 avec --install-private-http-and-observe.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"
sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
for command in php8.4 php-fpm8.4 apache2 mysql timeout systemctl systemd-analyze ss flock install getent useradd; do
    command -v "$command" >/dev/null || stop "outil absent : $command"
done
for module in mpm_event authz_core authz_host proxy proxy_fcgi reqtimeout; do
    [ -f "/usr/lib/apache2/modules/mod_$module.so" ] || stop "module Apache absent : $module (aucune installation automatique)."
done
[ -z "$(ss -H -ltn 'sport = :19846')" ] || stop 'port 19846 deja utilise.'
if getent passwd cofrac-mymar >/dev/null || getent group cofrac-mymar >/dev/null; then stop 'compte systeme dedie deja present : revue requise.'; fi
for unit in cofrac-mymar-fpm.service cofrac-mymar-http.service; do
    [ "$(systemctl show "$unit" -p LoadState --value)" = not-found ] || stop "unite dediee deja presente : $unit"
done
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'un deploiement est en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'une preparation MYMAR est en cours.'
RUN=$(mktemp -d "$STATE/mymar-http-runtime-XXXXXXXX")
UNITS_WRITTEN=0 VALIDATED=0 SUCCESS=0
finish() {
    code=$?
    trap - EXIT
    if [ "$SUCCESS" != 1 ]; then
        if [ "$UNITS_WRITTEN" = 1 ]; then
            systemctl disable --now cofrac-mymar-http.service cofrac-mymar-fpm.service > "$RUN/stop.log" 2>&1 || code=1
        fi
        if [ "$VALIDATED" = 1 ]; then
            timeout --kill-after=3 30 php8.4 "$BASE/runtime-control.php" --lock "$RUN" > "$RUN/safety-lock.log" 2>&1 || code=1
        fi
        printf 'Installation non confirmee. Diagnostic prive : %s\nNe pas relancer sans revue ; aucun planning branche.\n' "$RUN" >&2
    fi
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
install -d -m 0700 "$RUN/bundle"
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] \
        && [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'entree de paquet inattendue.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done < SHA256SUMS
install -o root -g root -m 0600 SHA256SUMS "$RUN/bundle/SHA256SUMS"
BASE="$RUN/bundle"
(cd "$BASE" && sha256sum -c SHA256SUMS)
SHARED=(apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service php8.3-fpm.service php8.4-fpm.service cofrac2-php-fpm.service cofrac2-queue.service cofrac2-reverb.service)
shared_state() { systemctl show "${SHARED[@]}" -p Id -p MainPID -p ExecMainStartTimestampMonotonic -p ActiveState -p SubState; }
apache_state() { find /etc/apache2 -type f -exec sha256sum {} + | sort; }
shared_state > "$RUN/services.before"
apache_state > "$RUN/apache.before"
timeout --kill-after=3 60 php8.4 "$BASE/runtime-control.php" --preflight "$RUN"
VALIDATED=1
useradd --system --user-group --no-create-home --home-dir /var/lib/cofrac-mymar-gateway --shell /usr/sbin/nologin cofrac-mymar
php8.4 "$BASE/runtime-control.php" --stage "$RUN"
install -d -o root -g root -m 0755 /run/cofrac-mymar-fpm /run/cofrac-mymar-http
php-fpm8.4 --test --fpm-config /etc/cofrac-mymar-gateway/php-fpm.conf > "$RUN/fpm-check.log" 2>&1
apache2 -t -f /etc/cofrac-mymar-gateway/apache.conf > "$RUN/apache-check.log" 2>&1
for unit in cofrac-mymar-fpm.service cofrac-mymar-http.service; do
    install -o root -g root -m 0644 "$RUN/$unit" "/etc/systemd/system/$unit"
done
UNITS_WRITTEN=1
systemd-analyze verify /etc/systemd/system/cofrac-mymar-{fpm,http}.service > "$RUN/units-check.log" 2>&1
systemctl daemon-reload
php8.4 "$BASE/runtime-control.php" --unlock "$RUN"
printf 'Demarrage des deux SEULS services MYMAR prives, puis controle HTTP. Aucun rechargement Apache public.\n'
systemctl start cofrac-mymar-fpm.service cofrac-mymar-http.service
READY=0
for _ in $(seq 1 30); do
    if [ -S /run/cofrac-mymar-fpm/php.sock ] && [ -n "$(ss -H -ltn 'src 127.0.0.1 and sport = :19846')" ]; then READY=1; break; fi
    sleep 1
done
[ "$READY" = 1 ] || stop 'passerelle privee non disponible, consulter les journaux des deux nouvelles unites.'
[ "$(ss -H -ltn 'sport = :19846' | wc -l)" = 1 ] || stop 'ecoute HTTP inattendue.'
timeout --kill-after=3 75 php8.4 "$BASE/runtime-control.php" --observe "$RUN"
shared_state > "$RUN/services.after"
apache_state > "$RUN/apache.after"
cmp "$RUN/services.before" "$RUN/services.after" || stop 'un service existant a change pendant cette operation.'
cmp "$RUN/apache.before" "$RUN/apache.after" || stop 'une configuration Apache existante a change pendant cette operation.'
systemctl enable cofrac-mymar-fpm.service cofrac-mymar-http.service > "$RUN/enable.log" 2>&1
php8.4 "$BASE/runtime-control.php" --publish "$RUN"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-private-http-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
for file in summary.json observation.json; do install -o ubuntu -g ubuntu -m 0600 "$RUN/$file" "$REPORT/$file"; done
(cd "$REPORT" && sha256sum summary.json observation.json > SHA256SUMS && chown ubuntu:ubuntu SHA256SUMS && chmod 0600 SHA256SUMS)
SUCCESS=1
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'API interne seule active. Le CRM, les disponibilites et les suggestions restent inchanges.\nArret : sudo bash %s/bundle/stop.sh\n' "$RUN"
