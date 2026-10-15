#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ "${1:-}" != '--switch-v2' || $EUID -ne 0 || "$(hostname -s)" != 'techcalendar' ]]; then
    echo 'A executer en root sur le CT techcalendar : bash activate-demo.sh --switch-v2' >&2
    exit 1
fi

APP=/var/www/TechCalendar-v2
V1=/var/www/TechCalendar
BASE=/srv/techcalendar-v2-demo
BACKUP="$BASE/rollback-v1"
VHOST=/etc/nginx/sites-available/techcalendar.lucas-dinnichert.fr
PROBE=/etc/nginx/sites-enabled/techcalendar-v2-preflight
CRON=/etc/cron.d/laravel-techcalendar.lucas-dinnichert.fr
V1_QUEUE=laravel-queue-techcalendar.lucas-dinnichert.fr.service
V1_REVERB=laravel-reverb-techcalendar.lucas-dinnichert.fr.service
V2_QUEUE=techcalendar-v2-queue.service
V2_REVERB=techcalendar-v2-reverb.service
DOMAIN=techcalendar.lucas-dinnichert.fr
HELPER="$(cd -- "$(dirname -- "$0")" && pwd)/demo-cutover.php"

exec 9>/run/lock/techcalendar-v2-cutover.lock
flock -n 9 || { echo 'Une bascule est deja en cours.' >&2; exit 1; }
test -f "$HELPER"
test -f "$VHOST"
test -f "$CRON"
test -S /run/php/techcalendar-v2-demo.sock
test ! -e "$BACKUP"
test ! -e "$PROBE" && test ! -L "$PROBE"
test ! -e "$V1/storage/framework/down"
test ! -e "$APP/storage/framework/down"
test "$(readlink -f /etc/nginx/sites-enabled/techcalendar.lucas-dinnichert.fr)" = "$VHOST"
if ss -H -ltn 'sport = :18081 or sport = :8081' | grep -q .; then
    echo 'Port de controle ou Reverb V2 deja utilise. Bascule annulee.' >&2
    exit 1
fi
for service in "$V1_QUEUE" "$V1_REVERB"; do
    systemctl is-active --quiet "$service"
    # Known initial state allows a deterministic rollback, even after a reboot.
    systemctl is-enabled --quiet "$service"
done
for service in "$V2_QUEUE" "$V2_REVERB"; do
    if systemctl is-active --quiet "$service" || systemctl is-enabled --quiet "$service"; then
        echo "Service V2 deja actif/active au demarrage : $service" >&2
        exit 1
    fi
done
test "$(mysql --no-defaults --protocol=socket --user=root --batch --skip-column-names -e 'SELECT CURRENT_USER()')" = 'root@localhost'
nginx -t
cd "$APP"
runuser -u www-data -- php8.4 artisan demo:check

echo '=== Acces admin V2 : aucun changement de mot de passe en V1 ==='
php8.4 "$HELPER" admin </dev/tty

install -d -m 0700 "$BACKUP"
cp -p "$VHOST" "$BACKUP/nginx-v1.conf"
cp -p "$CRON" "$BACKUP/cron-v1"
cp "$V1/.env" "$BACKUP/v1.env"
chmod 0600 "$BACKUP/v1.env"
for service in "$V1_QUEUE" "$V1_REVERB"; do
    systemctl cat "$service" > "$BACKUP/$service.txt"
done

cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
BACKUP=/srv/techcalendar-v2-demo/rollback-v1
VHOST=/etc/nginx/sites-available/techcalendar.lucas-dinnichert.fr
PROBE=/etc/nginx/sites-enabled/techcalendar-v2-preflight
CRON=/etc/cron.d/laravel-techcalendar.lucas-dinnichert.fr
test "$EUID" -eq 0
cd /var/www/TechCalendar
echo 'Retour a la V1, sans restaurer ni ecraser de base de donnees...'
if [[ -e "$PROBE" ]]; then
    mv "$PROBE" "$BACKUP/probe-retired.conf"
fi
cp -p "$BACKUP/nginx-v1.conf" "$VHOST"
nginx -t
systemctl reload nginx
if [[ -e "$BACKUP/v1-maintenance-started" ]]; then
    runuser -u www-data -- /usr/bin/php8.3 artisan up
fi
for service in laravel-queue-techcalendar.lucas-dinnichert.fr.service laravel-reverb-techcalendar.lucas-dinnichert.fr.service; do
    systemctl enable --now "$service"
done
if [[ ! -e "$CRON" ]]; then
    cp -p "$BACKUP/cron-v1" "$CRON"
fi
systemctl disable --now techcalendar-v2-queue.service techcalendar-v2-reverb.service
echo 'V1 retablie. Donnees et fichiers V2 conserves pour diagnostic.'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"

COMPLETED=0
finish() {
    local status=$?
    trap - EXIT INT TERM HUP
    if [[ $COMPLETED -ne 1 ]]; then
        echo "Bascule interrompue (code $status). Retour a la V1..." >&2
        bash "$BACKUP/rollback.sh" || echo "Retour automatique incomplet. Lancer : bash $BACKUP/rollback.sh" >&2
        exit 1
    fi
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

echo '=== Preparation du vhost, avec conservation des en-tetes existants ==='
php8.4 "$HELPER" nginx "$VHOST" "$BACKUP/nginx-v2.conf" "$BACKUP/nginx-probe.conf"

echo '=== Demarrage V2 et controle HTTP local avant bascule ==='
systemctl start "$V2_QUEUE" "$V2_REVERB"
for attempt in {1..15}; do
    if ss -H -ltn 'sport = :8081' | grep -q .; then break; fi
    sleep 1
done
systemctl is-active --quiet "$V2_QUEUE" "$V2_REVERB"
install -o root -g root -m 0644 "$BACKUP/nginx-probe.conf" "$PROBE"
nginx -t
systemctl reload nginx
sleep 1

check_http() {
    local port=$1
    local code
    code=$(curl --noproxy '*' -sS --connect-timeout 5 --max-time 30 \
        -H "Host: $DOMAIN" -D "$BACKUP/http-headers" -o "$BACKUP/login.html" \
        -w '%{http_code}' "http://127.0.0.1:$port/login")
    test "$code" = 200
    grep -qi '^X-TechCalendar-Release: v2-demo' "$BACKUP/http-headers"
    grep -q 'name="password"' "$BACKUP/login.html"
    for resource in /up /build/manifest.json; do
        code=$(curl --noproxy '*' -sS --connect-timeout 5 --max-time 30 \
            -H "Host: $DOMAIN" -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port$resource")
        test "$code" = 200
    done
    code=$(curl --noproxy '*' -sS --connect-timeout 5 --max-time 15 \
        -H "Host: $DOMAIN" -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/v2/dossiers")
    test "$code" = 302
    php8.4 "$HELPER" websocket "$port"
}
check_http 18081

echo '=== Courte maintenance V1, arret des traitements et sauvegarde SQL ==='
mv "$CRON" "$BACKUP/cron-v1-disabled"
touch "$BACKUP/v1-maintenance-started"
cd "$V1"
runuser -u www-data -- /usr/bin/php8.3 artisan down --retry=60
# Let an in-flight queue job finish; never force-kill it to accelerate the switch.
systemctl stop "$V1_QUEUE" "$V1_REVERB"
V1_DATABASE=$(/usr/bin/php8.3 "$HELPER" v1-database)
mysqldump --no-defaults --protocol=socket --user=root \
    --single-transaction --quick --skip-lock-tables --no-tablespaces \
    --set-gtid-purged=OFF --default-character-set=utf8mb4 --hex-blob \
    --routines --events --triggers "$V1_DATABASE" \
    2> "$BACKUP/mysqldump.stderr" | gzip -1 > "$BACKUP/database-v1.sql.gz"
test -s "$BACKUP/database-v1.sql.gz"
gzip -t "$BACKUP/database-v1.sql.gz"
(cd "$BACKUP" && sha256sum database-v1.sql.gz nginx-v1.conf cron-v1 > SHA256SUMS)

echo '=== Bascule du meme domaine vers la V2 ==='
install -o root -g root -m 0644 "$BACKUP/nginx-v2.conf" "$VHOST"
mv "$PROBE" "$BACKUP/probe-retired.conf"
nginx -t
systemctl reload nginx
sleep 1
check_http 80
systemctl enable "$V2_QUEUE" "$V2_REVERB"
systemctl disable "$V1_QUEUE" "$V1_REVERB"
systemctl is-active --quiet "$V2_QUEUE" "$V2_REVERB"
date -Iseconds > "$BACKUP/SWITCH-SUCCEEDED.txt"
COMPLETED=1
echo "V2 ACTIVE : https://$DOMAIN"
echo "Connexion : le compte admin et le mot de passe que tu viens de definir."
echo "Retour V1 : bash $BACKUP/rollback.sh"
echo 'Controles locaux HTTP, authentification obligatoire et handshake WebSocket : OK.'
echo 'Verifier maintenant une connexion HTTPS et un document depuis le navigateur.'
