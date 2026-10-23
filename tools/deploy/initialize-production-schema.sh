#!/usr/bin/env bash

# Initial V2 schema only. Never start services, seed data or read old app files.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin

if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$#" -ne 1 ] \
    || [ "${1:-}" != '--initialize-empty-v2-schema' ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Usage sur ns365601 : sudo bash initialize-production-schema.sh --initialize-empty-v2-schema\n' >&2
    exit 1
fi

APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
RUNTIME=cofrac2-app
RUNTIME_HOME=/var/lib/cofrac2-app
LOG="$STATE/schema-initialization.log"

stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
for executable in php8.4 useradd runuser passwd git stat find chgrp chown chmod install flock cmp; do
    command -v "$executable" >/dev/null || stop "outil manquant : $executable"
done
[ "$(readlink -f "$APP")" = "$APP" ] || stop 'chemin applicatif redirige.'
[ "$(stat -c %U "$APP")" = cofrac2-deploy ] || stop 'proprietaire applicatif inattendu.'
[ -d "$APP/.git" ] && [ ! -L "$APP/.git" ] || stop 'checkout Git absent ou symbolique.'
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u "$STATE")" = 0 ] \
    && [ "$(stat -c %a "$STATE")" = 700 ] || stop 'dossier prive de preparation inattendu.'
exec 9>"$STATE/schema-initialization.lock"
flock -n 9 || stop 'initialisation du schema deja en cours.'
[ ! -e "$STATE/SCHEMA-READY.json" ] && [ ! -L "$STATE/SCHEMA-READY.json" ] || stop 'schema deja initialise.'
[ ! -e "$APP/bootstrap/cache/config.php" ] && [ ! -L "$APP/bootstrap/cache/config.php" ] || stop 'configuration cachee inattendue.'
[ -f "$APP/.env" ] && [ ! -L "$APP/.env" ] || stop '.env absent ou symbolique.'
cmp -s "$APP/.env" "$STATE/initial-production.env" || stop '.env different de la preparation initiale.'
[ "$(runuser -u cofrac2-deploy -- git -C "$APP" branch --show-current)" = v2 ] || stop 'branche V2 attendue.'
[ -z "$(runuser -u cofrac2-deploy -- git -C "$APP" status --porcelain)" ] || stop 'checkout modifie.'
for directory in "$APP/storage" "$APP/bootstrap/cache" "$APP/public"; do
    [ -d "$directory" ] && [ ! -L "$directory" ] || stop "repertoire absent ou symbolique : $directory"
done
[ -z "$(find "$APP/storage" "$APP/bootstrap/cache" -type l -print -quit)" ] \
    || stop 'lien symbolique dans un repertoire inscriptible.'
if [ -e "$APP/public/storage" ] || [ -L "$APP/public/storage" ]; then
    [ -L "$APP/public/storage" ] && [ "$(readlink -f "$APP/public/storage")" = "$APP/storage/app/public" ] \
        || stop 'lien de stockage public inattendu.'
fi

# No framework boot: validate the private preparation and the empty SQL target.
php8.4 <<'PHP'
<?php
ini_set('display_errors', '0');
try {
    require '/var/www/cofrac2/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(file_get_contents('/var/www/cofrac2/.env'));
    $ready = json_decode(file_get_contents('/var/lib/cofrac2-production/DATABASE-READY.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ([
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'CRM_V2_ENABLED' => 'true',
        'SIMULATION_MODE' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306', 'DB_DATABASE' => 'cofrac2production', 'DB_USERNAME' => 'cofrac2_app',
        'DB_URL' => '', 'DB_SOCKET' => '', 'MAIL_MAILER' => 'log', 'SMSFACTOR_ENABLED' => 'false',
        'SMSFACTOR_MODE' => 'simulate', 'CAPCEE_ENABLED' => 'false',
    ] as $key => $expected) {
        if (($env[$key] ?? null) !== $expected) throw new RuntimeException();
    }
    if (($ready['database'] ?? '') !== 'cofrac2production' || ($ready['empty_database'] ?? false) !== true
        || ! hash_equals($ready['env_sha256'] ?? '', hash_file('sha256', '/var/www/cofrac2/.env'))) throw new RuntimeException();
    $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=cofrac2production;charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() !== 0) throw new RuntimeException();
    echo "Cible V2 vide et configuration initiale verifiees.\n";
} catch (Throwable) {
    file_put_contents('php://stderr', "STOP : cible SQL non vide ou preparation/configuration V2 non validee. Aucune migration lancee.\n");
    exit(1);
}
PHP

if ! getent passwd "$RUNTIME" >/dev/null; then
    [ ! -e "$RUNTIME_HOME" ] && [ ! -L "$RUNTIME_HOME" ] && [ ! -e "$STATE/runtime-identity" ] \
        || stop 'home ou marqueur de runtime deja present.'
    ! getent group "$RUNTIME" >/dev/null || stop 'groupe de runtime deja present sans compte.'
    useradd --system --create-home --user-group --home-dir "$RUNTIME_HOME" --shell /usr/sbin/nologin \
        --comment 'Cofrac 2 PHP runtime only' "$RUNTIME"
    printf '%s:%s:%s\n' "$RUNTIME" "$(id -u "$RUNTIME")" "$(id -g "$RUNTIME")" > "$STATE/runtime-identity"
fi
[ -f "$STATE/runtime-identity" ] && [ ! -L "$STATE/runtime-identity" ] \
    && [ "$(stat -c %u "$STATE/runtime-identity")" = 0 ] || stop 'identite runtime non reconnue.'
[ "$(cat "$STATE/runtime-identity")" = "$RUNTIME:$(id -u "$RUNTIME"):$(id -g "$RUNTIME")" ] || stop 'identite runtime differente.'
[ "$(getent passwd "$RUNTIME" | cut -d: -f6,7)" = "$RUNTIME_HOME:/usr/sbin/nologin" ] || stop 'home ou shell runtime inattendu.'
[ "$(id -gn "$RUNTIME")" = "$RUNTIME" ] && [ "$(id -G "$RUNTIME")" = "$(id -g "$RUNTIME")" ] || stop 'groupes runtime inattendus.'
[ "$(passwd -S "$RUNTIME" | awk '{print $2}')" = L ] || stop 'mot de passe runtime non verrouille.'
[ -d "$RUNTIME_HOME" ] && [ ! -L "$RUNTIME_HOME" ] && [ "$(stat -c %U "$RUNTIME_HOME")" = "$RUNTIME" ] \
    || stop 'repertoire personnel runtime inattendu.'
chmod 0700 "$RUNTIME_HOME"

# Code stays owned by the deployer and read-only for the runtime. Do not follow links.
find "$APP" \( -path "$APP/.git" -o -path "$APP/storage" -o -path "$APP/bootstrap/cache" -o -path "$APP/public" \) -prune -o \
    -type d -exec chgrp "$RUNTIME" {} + -exec chmod 2750 {} + -o \
    -type f -exec chgrp "$RUNTIME" {} + -exec chmod u+rw,g=r,o= {} +
find "$APP" \( -path "$APP/.git" -o -path "$APP/storage" -o -path "$APP/bootstrap/cache" -o -path "$APP/public" \) -prune -o \
    -type f -perm -0100 -exec chmod g+x {} +
chmod 2751 "$APP"
chmod 0700 "$APP/.git"
chown root:"$RUNTIME" "$APP/.env"
chmod 0640 "$APP/.env"

find "$APP/public" -type d -exec chgrp "$RUNTIME" {} + -exec chmod 2755 {} +
find "$APP/public" -type f -exec chgrp "$RUNTIME" {} + -exec chmod 0644 {} +
install -d -m 2770 -o "$RUNTIME" -g cofrac2-deploy \
    "$APP/storage/app/private" "$APP/storage/app/public" "$APP/storage/framework/cache/data" \
    "$APP/storage/framework/sessions" "$APP/storage/framework/views" "$APP/storage/logs" "$APP/bootstrap/cache"
find "$APP/storage" "$APP/bootstrap/cache" -type d -exec chown "$RUNTIME":cofrac2-deploy {} + -exec chmod 2770 {} +
find "$APP/storage" "$APP/bootstrap/cache" -type f -exec chown "$RUNTIME":cofrac2-deploy {} + -exec chmod 0660 {} +

# Expose the public disk only; blind parent traversal does not reveal private files.
chmod 2771 "$APP/storage" "$APP/storage/app"
find "$APP/storage/app/public" -type d -exec chmod 2755 {} +
find "$APP/storage/app/public" -type f -exec chmod 0644 {} +
if [ ! -L "$APP/public/storage" ]; then
    ln -s ../storage/app/public "$APP/public/storage"
fi

app_cli() {
    runuser -u "$RUNTIME" -- env -i PATH="$PATH" HOME="$RUNTIME_HOME" USER="$RUNTIME" LOGNAME="$RUNTIME" \
        php8.4 "$@"
}
app_cli -r 'exit(is_readable("/var/www/cofrac2/.env") && !is_writable("/var/www/cofrac2/.env") && !is_writable("/var/www/cofrac2/artisan") && !is_writable("/var/www/cofrac2") ? 0 : 1);'
runuser -u "$RUNTIME" -- test ! -r /etc/mysql/debian.cnf
runuser -u "$RUNTIME" -- test ! -r "$STATE/initial-production.env"
runuser -u www-data -- test -r "$APP/public/index.php"
runuser -u www-data -- test ! -r "$APP/.env"

cd "$APP"
printf 'Initialisation Laravel avec le compte limite %s. Diagnostic prive : %s\n' "$RUNTIME" "$LOG"
if ! app_cli artisan package:discover --no-interaction --no-ansi > "$LOG" 2>&1 \
    || ! app_cli artisan migrate --force --no-interaction --no-ansi >> "$LOG" 2>&1; then
    stop "initialisation interrompue ; examiner $LOG sans le publier. Aucune remise a zero automatique."
fi

REPORT=$(mktemp "$STATE/schema-check-XXXXXXXX")
app_cli <<'PHP' > "$REPORT"
<?php
ini_set('display_errors', '0');
try {
    require '/var/www/cofrac2/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(file_get_contents('/var/www/cofrac2/.env'));
    $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=cofrac2production;charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['users', 'dossiers', 'appointments', 'lots', 'dossier_operations', 'jobs'] as $table) {
        if ((int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() !== 0) throw new RuntimeException();
    }
    $expected = count(glob('/var/www/cofrac2/database/migrations/*.php'));
    $actual = (int) $db->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    if ($expected === 0 || $actual !== $expected) throw new RuntimeException();
    echo json_encode([
        'database' => 'cofrac2production', 'runtime_user' => 'cofrac2-app',
        'migrations' => $actual,
        'tables' => (int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn(),
        'business_data_empty' => true, 'initialized_at' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    file_put_contents('php://stderr', "STOP : schema ou donnees initiales inattendus. Examiner le diagnostic prive.\n");
    exit(1);
}
PHP
mv -T -- "$REPORT" "$STATE/SCHEMA-READY.json"
printf 'Schema V2 initialise. Aucun utilisateur, dossier, RDV, lot ou job importe ou genere.\n'
printf 'Compte runtime cofrac2-app sans shell, code et .env en lecture seule.\n'
printf 'Aucun service demarre ou recharge. Coffrac original non modifie.\n'
