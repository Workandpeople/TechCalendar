#!/usr/bin/env bash

# Application-only release while the dedicated V2 vhost and services stay closed.
set +x
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 3 ] \
    && [ "$1" = --apply ] && [[ "$3" =~ ^[a-f0-9]{64}$ ]] || stop 'Usage : sudo bash update-production-release.sh --apply PAQUET SHA256'
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ "$HERE" = "$APP/tools/deploy" ] && [ "$(realpath "$APP")" = "$APP" ] || stop 'checkout V2 attendu.'
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
for command in php8.4 php-fpm8.4 runuser systemctl git sha256sum mysqldump gzip flock install cmp tar; do
    command -v "$command" >/dev/null || stop "outil manquant : $command"
done
exec 9>"$STATE/runtime-operation.lock"
flock -n 9 || stop 'operation runtime deja en cours.'
SITE=/etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf
[ ! -e "$STATE/INTERNAL-SITE-OPEN.json" ] && [ ! -e /etc/apache2/conf-enabled/cofrac2-runtime-modules.conf ] \
    && [ ! -L /etc/apache2/conf-enabled/cofrac2-runtime-modules.conf ] \
    && [ ! -L "$SITE" ] && cmp -s "$SITE" "$HERE/apache-production-https.conf.example" \
    || stop 'mettre uniquement la V2 en maintenance avant le pull et cette mise a jour.'
[ -f "$STATE/RUNTIME-PREPARED.json" ] && [ ! -L "$STATE/RUNTIME-PREPARED.json" ] \
    && [ "$(stat -c %u:%a "$STATE/RUNTIME-PREPARED.json")" = 0:600 ] \
    && [ ! -L "$APP/.env" ] && [ "$(stat -c %U:%G:%a "$APP/.env")" = root:cofrac2-app:640 ] \
    || stop 'configuration et preparation privees attendues.'
for name in php-fpm queue reverb; do
    ! systemctl is-active --quiet "cofrac2-$name.service" || stop 'les seuls services V2 doivent etre arretes.'
    cmp -s "/etc/systemd/system/cofrac2-$name.service" "$HERE/cofrac2-$name.service.example" || stop 'unite dediee V2 modifiee.'
done
git_app() { runuser -u cofrac2-deploy -- git -C "$APP" "$@"; }
[ "$(git_app branch --show-current)" = v2 ] && [ -z "$(git_app status --porcelain)" ] || stop 'checkout V2 propre requis.'
REVISION=$(git_app rev-parse HEAD)
WORK=$(mktemp -d "$STATE/release-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
printf 'Diagnostic et sauvegardes prives : %s\n' "$WORK"
snapshot_configs() {
    find -L /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled -type f -print0 | sort -z | xargs -0 -r sha256sum
}
snapshot_services() {
    systemctl show apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service php8.3-fpm.service php8.4-fpm.service \
        --property=Id,ActiveState,MainPID,ExecMainStartTimestampMonotonic
}
snapshot_configs > "$WORK/apache.before"
snapshot_services > "$WORK/services.before"
cp -p "$STATE/RUNTIME-PREPARED.json" "$WORK/prepared.before.json"
success=0
fpm_written=0
next_build=
cleanup() {
    result=$?
    trap - EXIT
    rm -f -- "$WORK/mysql.cnf"
    if [ -n "$next_build" ] && [[ "$next_build" = "$APP/public"/.cofrac2-build-* ]]; then rm -rf -- "$next_build"; fi
    if [ "$success" != 1 ]; then
        if [ "$fpm_written" = 1 ]; then
            install -o root -g root -m 0600 "$WORK/php-fpm.before.conf" /etc/cofrac2/php-fpm.conf
        fi
        printf 'Mise a jour non validee : V2 maintenue fermee, aucune restauration SQL automatique. Diagnostic : %s\n' "$WORK" >&2
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
[ -d "$2" ] && [ ! -L "$2" ] && [ "$(realpath "$2")" = "$2" ] || stop 'paquet absent ou chemin indirect.'
install -d -m 0700 "$WORK/bundle"
cp -a --no-preserve=ownership "$2/." "$WORK/bundle/"
[ -z "$(find "$WORK/bundle" ! -type d ! -type f -print -quit)" ] || stop 'paquet contenant un lien ou fichier special.'
find "$WORK/bundle" -type d -exec chmod 0700 {} +
find "$WORK/bundle" -type f -exec chmod 0600 {} +
php8.4 "$HERE/update-production-release.php" --check "$WORK/bundle" "$3" "$REVISION" "$WORK/mysql.cnf" \
    > "$WORK/preflight.json" || stop 'precontrole refuse ; aucun changement SQL ou service.'
previous=$(php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);echo $r["from_revision"];' "$WORK/preflight.json")
[ "$previous" != "$REVISION" ] && git_app merge-base --is-ancestor "$previous" "$REVISION" || stop 'revision identique ou non descendante.'
[ -f /etc/cofrac2/php-fpm.conf ] && [ ! -L /etc/cofrac2/php-fpm.conf ] \
    && [ "$(stat -c %u:%a /etc/cofrac2/php-fpm.conf)" = 0:600 ] || stop 'configuration PHP privee attendue.'
git_app show "$previous:tools/deploy/cofrac2-php-fpm.conf.example" > "$WORK/php-fpm.expected.conf"
cmp -s /etc/cofrac2/php-fpm.conf "$WORK/php-fpm.expected.conf" || stop 'configuration PHP differente de la revision preparee.'
cp -p /etc/cofrac2/php-fpm.conf "$WORK/php-fpm.before.conf"
php-fpm8.4 --test --fpm-config "$HERE/cofrac2-php-fpm.conf.example" > "$WORK/php-fpm-check.log" 2>&1 \
    || stop 'nouvelle configuration PHP dediee invalide.'
git_app diff --quiet "$previous" "$REVISION" -- composer.json composer.lock package.json package-lock.json \
    || stop 'dependances modifiees : ce correctif ne les installe pas.'
git_app diff --name-only "$previous" "$REVISION" > "$WORK/changed-files.txt"
while IFS= read -r file; do
    case "$file" in
        app/*|resources/*|routes/*|tests/*|docs/*|tools/deploy/*|lang/fr/passwords.php) ;;
        public/favicon.ico|tools/fixtures/collect-techcalendar-references.php|tools/fixtures/prepare-techcalendar-export.php|tools/fixtures/fetch-techcalendar-internal-users.sh) ;;
        config/admin.php|database/seeders/CoffracServiceAliasSeeder.php) ;;
        config/mail.php|database/migrations/2026_10_25_110000_add_reversible_dossier_deletion.php|database/migrations/2026_10_25_120000_add_automatic_capcee_reports.php|database/migrations/2026_10_26_100000_activate_existing_mail_templates.php|database/migrations/2026_10_26_110000_add_dossier_inspection_geocode.php) ;;
        database/migrations/2026_10_28_140000_add_appointment_planning_window_index.php) ;;
        *) stop 'changement hors perimetre applicatif du correctif.' ;;
    esac
done < "$WORK/changed-files.txt"
for directory in app config routes resources tools database lang; do
    find "$APP/$directory" -type d -exec chgrp cofrac2-app {} + -exec chmod 2750 {} +
    find "$APP/$directory" -type f -exec chgrp cofrac2-app {} + -exec chmod u+rw,g=r,o= {} +
done
test -f "$APP/public/favicon.ico" && test ! -L "$APP/public/favicon.ico" || stop 'favicon applicatif absent ou indirect.'
chgrp cofrac2-app "$APP/public/favicon.ico"
chmod 0644 "$APP/public/favicon.ico"
app_cli() { runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app php8.4 -d memory_limit=768M "$@"; }
cd "$APP"
app_cli "$HERE/production-runtime.php" --check > "$WORK/application.before.json"
printf 'Sauvegarde V2 avec son seul compte SQL limite, sans acces a Coffrac original...\n'
mysqldump --defaults-file="$WORK/mysql.cnf" --single-transaction --skip-lock-tables --no-tablespaces \
    --set-gtid-purged=OFF --hex-blob cofrac2production 2> "$WORK/dump.stderr" | gzip -1 > "$WORK/database-before.sql.gz"
gzip -t "$WORK/database-before.sql.gz"
test -s "$WORK/database-before.sql.gz"
git_app archive "$previous" | gzip -1 > "$WORK/code-before.tar.gz"
cp -p "$APP/.env" "$WORK/env.before"
[ -d "$APP/public/build" ] && [ ! -L "$APP/public/build" ] || stop 'assets actuels inattendus.'
tar -czf "$WORK/assets-before.tar.gz" -C "$APP/public" build
(cd "$WORK" && sha256sum database-before.sql.gz code-before.tar.gz env.before assets-before.tar.gz php-fpm.before.conf > BACKUP-SHA256SUMS)
fpm_written=1
install -o root -g root -m 0600 "$HERE/cofrac2-php-fpm.conf.example" /etc/cofrac2/php-fpm.conf
next_build=$(mktemp -d "$APP/public/.cofrac2-build-XXXXXXXX")
cp -a --no-preserve=ownership "$WORK/bundle/build/." "$next_build/"
chown -R root:cofrac2-app "$next_build"
find "$next_build" -type d -exec chmod 0755 {} +
find "$next_build" -type f -exec chmod 0644 {} +
mv -- "$APP/public/build" "$WORK/build.before"
mv -- "$next_build" "$APP/public/build"
next_build=
printf 'Migrations V2 ciblees et recompilation des caches, sans seeder ni import...\n'
for migration in 2026_10_25_110000_add_reversible_dossier_deletion 2026_10_25_120000_add_automatic_capcee_reports \
    2026_10_26_100000_activate_existing_mail_templates 2026_10_26_110000_add_dossier_inspection_geocode \
    2026_10_28_140000_add_appointment_planning_window_index; do
    app_cli artisan migrate --force --no-interaction --no-ansi --path="database/migrations/$migration.php" >> "$WORK/migrations.log" 2>&1 \
        || stop 'migration en echec ; consulter le journal prive, ne pas reinitialiser la base.'
done
for command in package:discover config:cache route:cache view:cache; do
    app_cli artisan "$command" --no-interaction --no-ansi >> "$WORK/caches.log" 2>&1 || stop 'cache en echec ; consulter le journal prive.'
done
app_cli "$HERE/production-runtime.php" --check > "$WORK/application.after.json"
cmp -s "$APP/.env" "$WORK/env.before" || stop 'configuration modifiee pendant la mise a jour.'
snapshot_configs > "$WORK/apache.after"
snapshot_services > "$WORK/services.after"
cmp -s "$WORK/apache.before" "$WORK/apache.after" && cmp -s "$WORK/services.before" "$WORK/services.after" \
    || stop 'configuration ou service partage modifie pendant la mise a jour.'
php8.4 -r '
    $r=json_decode(file_get_contents($argv[1]."/prepared.before.json"),true,32,JSON_THROW_ON_ERROR);
    $p=json_decode(file_get_contents($argv[1]."/preflight.json"),true,32,JSON_THROW_ON_ERROR);
    $r["runtime_updates"][]=["kind"=>"meeting_workflow_assets_schema","from_revision"=>$r["revision"],"to_revision"=>$p["revision"],"at_utc"=>gmdate(DATE_ATOM),"work_directory"=>$argv[1]];
    $r["revision"]=$p["revision"];$r["asset_probe"]=$p["asset_probe"];
    $check=json_decode(file_get_contents($argv[1]."/application.after.json"),true,32,JSON_THROW_ON_ERROR);
    $r["external_portals_enabled"]=$check["external_portals_enabled"];
    $r["enabled_external_accounts"]=$check["enabled_external_accounts"];
    $r["mail_delivery_enabled"]=$check["mail_delivery_enabled"];
    $r["outbound_delivery_enabled"]=$check["outbound_delivery_enabled"];
    file_put_contents($argv[1]."/prepared.updated.json",json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
' "$WORK"
mv -- "$WORK/prepared.updated.json" "$STATE/RUNTIME-PREPARED.json"
success=1
cat "$WORK/application.after.json"
printf 'Correctifs V2 prepares. Aucun service demarre ou recharge ; ancien domaine inchange.\n'
printf 'Mode mail approuve conserve. SMS en simulation/desactives, CAPCEE desactive, Global+ TEST conserves.\n'
printf 'Ouverture : sudo bash /var/www/cofrac2/tools/deploy/configure-production-runtime.sh --open --reload-shared-apache\n'
