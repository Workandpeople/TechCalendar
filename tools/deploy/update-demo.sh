#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ "${1:-}" != '--update-v2' || $EUID -ne 0 || "$(hostname -s)" != techcalendar ]]; then
    echo 'A executer en root sur le CT techcalendar : bash update-demo.sh --update-v2' >&2
    exit 1
fi

APP=/var/www/TechCalendar-v2
BASE=/srv/techcalendar-v2-demo
PACKAGE=$(cd "$(dirname "$0")" && pwd)
QUEUE=techcalendar-v2-queue.service
REVERB=techcalendar-v2-reverb.service
DOMAIN=techcalendar.lucas-dinnichert.fr
DATABASE=techcalendar_v2_demo_simulation
COMPOSER=$(command -v composer)

# Share the cutover lock: an update and a V1/V2 switch must never overlap.
exec 9>/run/lock/techcalendar-v2-cutover.lock
flock -n 9 || { echo 'Une operation de deploiement est deja en cours.' >&2; exit 1; }
for tool in php8.4 rsync mysql mysqldump gzip curl; do command -v "$tool" >/dev/null; done
test -f "$APP/.env"
test ! -e "$APP/storage/framework/down"
test -S /run/php/techcalendar-v2-demo.sock
test ! -e "$APP/public/hot"
systemctl is-active --quiet "$QUEUE" "$REVERB" php8.4-fpm nginx
grep -Fq 'root /var/www/TechCalendar-v2/public;' /etc/nginx/sites-available/techcalendar.lucas-dinnichert.fr
(cd "$PACKAGE" && sha256sum -c SHA256SUMS)
grep -Eq '^[0-9a-f]{40}$' "$PACKAGE/revision.txt"
gzip -t "$PACKAGE/code-v2.tar.gz"
test "$(df -Pk "$APP" | awk 'NR == 2 {print $4}')" -gt 5242880 || { echo 'Au moins 5 Gio libres requis.' >&2; exit 1; }
mysql --no-defaults --protocol=socket --user=root --batch --skip-column-names \
    -e "SELECT COUNT(*) FROM $DATABASE.migrations" >/dev/null
test "$(mysql --no-defaults --protocol=socket --user=root --batch --skip-column-names \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DATABASE' AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'")" = 0

check_target() {
    (cd "$APP" && runuser -u www-data -- php8.4 <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('demo') || ! config('crm.v2_enabled')
    || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'techcalendar_v2_demo_simulation'
    || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "STOP : cible differente de la demonstration V2 locale au CT.\n");
    exit(1);
}
$app->make(App\Services\Simulation\SimulationSafety::class)->assertDemoConfiguration();
echo "Isolation V2 verifiee.\n";
PHP
    )
}
check_target

install -d -m 0700 "$BASE/updates"
WORK=$(mktemp -d "$BASE/updates/update-$(date +%Y%m%d-%H%M%S)-XXXXXXXX")
STAGE="$WORK/source"
BACKUP="$WORK/backup"
mkdir -m 0700 "$STAGE" "$BACKUP"
# Packages are generated from git archive, never from a production filesystem.
tar -tzf "$PACKAGE/code-v2.tar.gz" > "$WORK/archive-paths.txt"
if grep -Eq '(^/|(^|/)\.\.(/|$))' "$WORK/archive-paths.txt"; then
    echo 'Archive avec un chemin non autorise.' >&2; exit 1
fi
while IFS= read -r path; do
    relative=${path#./}
    case "${relative%%/*}" in
        ''|app|bootstrap|config|database|lang|public|resources|routes|tools|vendor|artisan|composer.json|composer.lock|package.json|package-lock.json|vite.config.js) ;;
        *) echo 'Contenu inattendu dans le paquet de code.' >&2; exit 1 ;;
    esac
done < "$WORK/archive-paths.txt"
tar -tvzf "$PACKAGE/code-v2.tar.gz" > "$WORK/archive-details.txt"
if grep -Eq '^[lh]' "$WORK/archive-details.txt"; then
    echo 'Les liens ne sont pas autorises dans le paquet.' >&2; exit 1
fi
tar --no-same-owner -xzf "$PACKAGE/code-v2.tar.gz" -C "$STAGE"
for path in .env .git storage public/storage public/hot bootstrap/cache/config.php; do
    test ! -e "$STAGE/$path" && test ! -L "$STAGE/$path"
done
test -s "$STAGE/public/build/manifest.json"
COMPOSER_ALLOW_SUPERUSER=1 php8.4 "$COMPOSER" install --working-dir="$STAGE" \
    --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
php8.4 "$COMPOSER" check-platform-reqs --working-dir="$STAGE" --no-dev

cd "$APP"
MAINTENANCE=0
SUCCESS=0
finish() {
    local status=$?
    trap - EXIT INT TERM HUP
    if [[ $SUCCESS -ne 1 && $MAINTENANCE -eq 1 ]]; then
        # Never roll migrations back or restore a dump automatically.
        runuser -u www-data -- php8.4 artisan down --retry=60 || true
        systemctl stop "$QUEUE" "$REVERB" || true
        echo "ECHEC ($status) : V2 laissee en maintenance. Diagnostic/sauvegardes : $WORK" >&2
        echo 'Ne pas relancer ni restaurer la base sans analyser cet echec.' >&2
        exit 1
    fi
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

echo 'Courte maintenance V2 et sauvegarde avant modification...'
MAINTENANCE=1
runuser -u www-data -- php8.4 artisan down --retry=60
# systemd allows the in-flight worker to finish (TimeoutStopSec=1900).
systemctl stop "$QUEUE" "$REVERB"
cp -p .env "$BACKUP/app.env"
chmod 0600 "$BACKUP/app.env"
sha256sum .env > "$BACKUP/env-checksum"
tar -czf "$BACKUP/code-before.tar.gz" --exclude=public/storage --exclude=public/hot \
    app bootstrap config database lang public resources routes tools vendor \
    artisan composer.json composer.lock package.json package-lock.json vite.config.js
mysqldump --no-defaults --protocol=socket --user=root --single-transaction --quick \
    --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF --hex-blob \
    --default-character-set=utf8mb4 "$DATABASE" \
    2> "$BACKUP/dump.stderr" | gzip -1 > "$BACKUP/database-before.sql.gz"
gzip -t "$BACKUP/database-before.sql.gz"
(cd "$BACKUP" && sha256sum code-before.tar.gz database-before.sql.gz app.env > SHA256SUMS)

# No --delete: keep local runtime data and the previous hashed frontend assets.
rsync -rt --chown=root:root --chmod=D755,F644 \
    --exclude='/.env*' --exclude='/.git/' --exclude='/storage/' \
    --exclude='/bootstrap/cache/' --exclude='/public/storage' --exclude='/public/hot' \
    "$STAGE/" "$APP/"
sha256sum -c "$BACKUP/env-checksum"
runuser -u www-data -- php8.4 artisan config:clear
runuser -u www-data -- php8.4 artisan package:discover
runuser -u www-data -- php8.4 artisan migrate --force
runuser -u www-data -- php8.4 artisan config:cache
runuser -u www-data -- php8.4 artisan route:cache
runuser -u www-data -- php8.4 artisan view:cache
check_target
runuser -u www-data -- php8.4 artisan migrate:status
runuser -u www-data -- php8.4 artisan schedule:list
# Informative dry run only: review assignments before applying reconciliation.
runuser -u www-data -- php8.4 artisan clients:reconcile-legacy | tee "$WORK/client-reconciliation-preview.txt"
systemctl reload php8.4-fpm
systemctl start "$QUEUE" "$REVERB"
systemctl is-active --quiet "$QUEUE" "$REVERB"
for attempt in {1..15}; do
    if php8.4 tools/deploy/demo-cutover.php websocket 80 > "$WORK/websocket-check.log" 2>&1; then break; fi
    sleep 1
done
php8.4 tools/deploy/demo-cutover.php websocket 80
runuser -u www-data -- php8.4 artisan up
for path in /login /up; do
    code=$(curl --noproxy '*' -sS --connect-timeout 5 --max-time 30 \
        -H "Host: $DOMAIN" -o /dev/null -w '%{http_code}' "http://127.0.0.1$path")
    test "$code" = 200
done
cp "$PACKAGE/revision.txt" "$WORK/DEPLOYED-REVISION.txt"
SUCCESS=1
echo "V2 mise a jour. Sauvegarde et bilan : $WORK"
echo 'Verifier la connexion HTTPS, un dossier et un document dans le navigateur.'
echo 'Base preservee, migrations appliquees. Ni seed, ni reimport, ni changement de mot de passe.'
