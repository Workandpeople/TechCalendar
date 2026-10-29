#!/usr/bin/env bash

# Invoked after the isolated runtime fixture, never on a real server.
set -Eeuo pipefail
umask 077
[ -f /.dockerenv ] && [ "${COFRAC2_RUNTIME_TEST:-}" = 1 ] && [ "$(hostname -s)" = ns365601 ] \
    && [ -f /etc/mysql/fixture.cnf ] || { echo 'Fixture SQL/runtime jetable uniquement.' >&2; exit 1; }
trap 'printf "RELEASE_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2; tail -35 /tmp/release-update.log 2>/dev/null || true' ERR
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
SCRIPT="$APP/tools/deploy/update-production-release.sh"
sql() { mysql --defaults-file=/etc/mysql/fixture.cnf --batch --skip-column-names -e "$1"; }
git_app() { runuser -u cofrac2-deploy -- git -C "$APP" "$@"; }
BASE=$(git_app rev-parse HEAD)
cp -p "$STATE/RUNTIME-PREPARED.json" /tmp/release-prepared.before.json
printf '\n{{-- Fixture asset/schema release --}}\n' >> "$APP/resources/views/components/user-email-conflict.blade.php"
# Exercise all newly permitted tracked paths, not just an ordinary Blade update.
printf '\n// Fixture export tooling update.\n' >> "$APP/tools/fixtures/collect-techcalendar-references.php"
printf '\n// Fixture export tooling update.\n' >> "$APP/tools/fixtures/prepare-techcalendar-export.php"
printf '\n# Fixture export tooling update.\n' >> "$APP/tools/fixtures/fetch-techcalendar-internal-users.sh"
printf '\0' >> "$APP/public/favicon.ico"
printf '\n// Fixture approved naming-only update.\n' >> "$APP/config/admin.php"
printf '\n// Fixture approved naming-only update.\n' >> "$APP/database/seeders/CoffracServiceAliasSeeder.php"
git_app add resources/views/components/user-email-conflict.blade.php public/favicon.ico \
    config/admin.php database/seeders/CoffracServiceAliasSeeder.php \
    tools/fixtures/collect-techcalendar-references.php tools/fixtures/prepare-techcalendar-export.php tools/fixtures/fetch-techcalendar-internal-users.sh
git_app -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture-schema-release >/dev/null
REVISION=$(git_app rev-parse HEAD)
BUNDLE=$(mktemp -d /tmp/cofrac2-update-XXXXXXXX)
install -d -m 0700 "$BUNDLE/build" "$BUNDLE/build/assets"
php8.4 -r '
    $entries=[];
    foreach(["resources/css/app.css"=>"app-release.css","resources/js/app.js"=>"app-release.js","resources/js/chart.js"=>"chart-release.js"] as $src=>$name) {
        file_put_contents($argv[1]."/build/assets/".$name,"/* release fixture */\n");chmod($argv[1]."/build/assets/".$name,0600);
        $entries[$src]=["src"=>$src,"file"=>"assets/".$name];
    }
    file_put_contents($argv[1]."/build/manifest.json",json_encode($entries));chmod($argv[1]."/build/manifest.json",0600);
    require "/var/www/cofrac2/tools/deploy/lib/ProductionAssetUpdate.php";
    echo Cofrac2\Deploy\ProductionAssetUpdate::seal($argv[1],$argv[2],$argv[3]);
' "$BUNDLE" "$REVISION" "$BASE" > /tmp/release-bundle.sha
SHA=$(cat /tmp/release-bundle.sha)
if bash "$SCRIPT" --apply "$BUNDLE" "$SHA" > /tmp/release-active-refused.log 2>&1; then exit 1; fi
bash "$APP/tools/deploy/configure-production-runtime.sh" --maintenance --reload-shared-apache > /tmp/release-maintenance.log 2>&1
sha256sum "$APP/.env" /srv/cofrac2-runtime/documents.list0 > /tmp/release-inputs.sha256
sql 'UPDATE cofrac2production.dossiers SET deleted_at=NULL;'
# Restore only this disposable fixture to the pre-release schema to exercise the targeted migrations.
runuser -u cofrac2-app -- php8.4 "$APP/artisan" migrate:rollback --step=7 --force --no-interaction > /tmp/release-rollback-fixture.log 2>&1
sql "INSERT INTO cofrac2production.dossiers (id,reference,address,city,created_at,updated_at) VALUES (80001,'LEGACY-DELETED-TEST','Fixture','Paris',NOW(),NOW());"
sql "INSERT INTO cofrac2production.mail_templates (name,slug,subject,markdown_body,is_active,created_at,updated_at) VALUES
    ('Active content','runtime-active-content','Fixture','Fixture',1,NOW(),NOW()),
    ('Inactive content','runtime-inactive-content','Fixture','Fixture',0,NOW(),NOW());"
PORTAL_ID=$(sql 'SELECT u.id FROM cofrac2production.users u JOIN cofrac2production.external_delegataires o ON o.id=u.external_client_id WHERE u.role=3 AND u.admin=0 AND u.deleted_at IS NULL AND o.is_active=1 ORDER BY u.id LIMIT 1;')
test -n "$PORTAL_ID"
sql "UPDATE cofrac2production.users SET portal_enabled=1 WHERE id=$PORTAL_ID;"
sql 'SELECT id,email,password,role,admin,deleted_at,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/release-users.before.tsv
if bash "$SCRIPT" --apply "$BUNDLE" "$(printf '0%.0s' {1..64})" > /tmp/release-bad-sha.log 2>&1; then exit 1; fi
cmp -s "$STATE/RUNTIME-PREPARED.json" /tmp/release-prepared.before.json
cp -p "$APP/.env" /tmp/release-original.env
printf '\n# Unexpected environment change\n' >> "$APP/.env"
if bash "$SCRIPT" --apply "$BUNDLE" "$SHA" > /tmp/release-bad-env.log 2>&1; then exit 1; fi
cp -p /tmp/release-original.env "$APP/.env"
# A failed migration must leave the site closed and the marker unchanged, with a usable SQL backup.
cp /usr/bin/php8.4 /tmp/release-php-wrapper.original
cat > /usr/bin/php8.4 <<'PHPWRAPPER'
#!/usr/bin/env bash
if [ -f /tmp/release-fail-migration ] && [[ "$*" = *'artisan migrate --force'* ]]; then
    rm /tmp/release-fail-migration
    echo 'Intentional fixture migration failure.' >&2
    exit 31
fi
exec /usr/bin/php "$@"
PHPWRAPPER
chmod 0755 /usr/bin/php8.4
touch /tmp/release-fail-migration
chown cofrac2-app:cofrac2-app /tmp/release-fail-migration
if bash "$SCRIPT" --apply "$BUNDLE" "$SHA" > /tmp/release-failed-migration.log 2>&1; then exit 1; fi
cmp -s "$STATE/RUNTIME-PREPARED.json" /tmp/release-prepared.before.json
test ! -e "$STATE/INTERNAL-SITE-OPEN.json"
FAILED_WORK=$(find "$STATE" -name migrations.log -path '*/release-*/*' -printf '%h\n' | head -1)
test -n "$FAILED_WORK"
gzip -t "$FAILED_WORK/database-before.sql.gz"
sql 'CREATE DATABASE cofrac2_release_restore_fixture;'
gzip -dc "$FAILED_WORK/database-before.sql.gz" | mysql --defaults-file=/etc/mysql/fixture.cnf cofrac2_release_restore_fixture
test "$(sql 'SELECT COUNT(*) FROM cofrac2_release_restore_fixture.dossiers WHERE id=80001;')" = 1
test ! -e "$FAILED_WORK/mysql.cnf"
bash "$SCRIPT" --apply "$BUNDLE" "$SHA" > /tmp/release-update.log 2>&1
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.dossiers WHERE id=80001 AND deleted_at IS NOT NULL AND deletion_source="legacy_status";')" = 1
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.capcee_automatic_submissions;')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.external_delegataires WHERE capcee_enabled=1;')" = 0
test "$(sql 'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA="cofrac2production" AND TABLE_NAME="appointments" AND INDEX_NAME="appointments_planning_window_index";')" = 1
test "$(sql 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA="cofrac2production" AND TABLE_NAME="users" AND COLUMN_NAME="portal_notification_preferences" AND DATA_TYPE="json" AND IS_NULLABLE="YES";')" = 1
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.users WHERE portal_notification_preferences IS NOT NULL;')" = 0
test "$(sql "SELECT COUNT(*) FROM cofrac2production.mail_templates WHERE slug IN ('runtime-active-content','runtime-inactive-content') AND is_active=1;")" = 2
sql 'SELECT id,email,password,role,admin,deleted_at,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/release-users.after.tsv
cmp -s /tmp/release-users.before.tsv /tmp/release-users.after.tsv
sha256sum -c /tmp/release-inputs.sha256
sha256sum -c /tmp/runtime-original.sha256
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if($r["revision"]!==$argv[2]||$r["asset_probe"]!=="/build/assets/app-release.css")exit(1);' "$STATE/RUNTIME-PREPARED.json" "$REVISION"
bash "$APP/tools/deploy/configure-production-runtime.sh" --open --reload-shared-apache > /tmp/release-open.log 2>&1
test -f "$STATE/INTERNAL-SITE-OPEN.json"
test "$(curl --noproxy '*' -sS --resolve cofrac2.geniuscontrole.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://cofrac2.geniuscontrole.com/build/assets/app-release.css)" = 200
curl --noproxy '*' -fsS --resolve cofrac2.geniuscontrole.com:443:127.0.0.1 -b /tmp/runtime-cookie.jar \
    'https://cofrac2.geniuscontrole.com/v2/dossiers?status=unknown' > /tmp/release-deleted-dossiers.html
grep -q 'Dossiers supprimés' /tmp/release-deleted-dossiers.html
sha256sum -c /tmp/runtime-original.sha256
printf 'OK : release MySQL sauvegardee, migrations reelles, assets, refus et echec intermediaire, reprise, HTTPS/worker/Reverb et Coffrac original inchanges.\n'
