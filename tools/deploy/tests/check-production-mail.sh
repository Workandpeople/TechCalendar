#!/usr/bin/env bash

# Synthetic runtime only, no SMTP server or message delivery.
set -Eeuo pipefail
umask 077
[ -f /.dockerenv ] && [ "${COFRAC2_RUNTIME_TEST:-}" = 1 ] && [ "$(hostname -s)" = ns365601 ] \
    && [ -f /etc/mysql/fixture.cnf ] || { echo 'Fixture Docker runtime uniquement.' >&2; exit 1; }
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
SCRIPT="$APP/tools/deploy/enable-production-mail.sh"
trap 'printf "MAIL_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2; tail -35 /tmp/mail-fixture-enable.log 2>/dev/null || true; find "$STATE" -path "*/mail-enablement-*/*.stderr" -type f -exec tail -8 {} \; 2>/dev/null || true' ERR
sql() { mysql --defaults-file=/etc/mysql/fixture.cnf --batch --skip-column-names -e "$1"; }
git_app() { runuser -u cofrac2-deploy -- git -C "$APP" "$@"; }
app_cli() { runuser -u cofrac2-app -- php8.4 "$@"; }
app_cli -r '
    chdir("/var/www/cofrac2"); require "vendor/autoload.php";
    $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $id=App\Models\User::where("admin",true)->sole()->id;
    app(App\Services\ApplicationSettings::class)->update(["mail.mailer"=>"smtp","mail.host"=>"smtp.example.invalid",
        "mail.port"=>587,"mail.username"=>"fixture@example.invalid","mail.password"=>"FixtureOnlyNoRealSmtp123"],$id);
'
cp -p "$APP/.env" /tmp/mail-fixture.env.before
cp -p "$STATE/RUNTIME-PREPARED.json" /tmp/mail-fixture.prepared.before.json
cat "$APP/tools/deploy/production-mail.php" | runuser -u cofrac2-app -- php8.4 -- --inspect-disabled > /tmp/mail-fixture.stdin.json
if cat "$APP/tools/deploy/production-mail.php" | php8.4 -- --enable > /tmp/mail-fixture.stdin-denied.log 2>&1; then exit 1; fi
grep -q 'Activation reservee au serveur V2' /tmp/mail-fixture.stdin-denied.log
sql "INSERT INTO cofrac2production.jobs(queue,payload,attempts,reserved_at,available_at,created_at)
    VALUES('held-fixture','{}',0,NULL,UNIX_TIMESTAMP()+3600,UNIX_TIMESTAMP());"
if bash "$SCRIPT" --enable-real-mail > /tmp/mail-fixture-pending.log 2>&1; then exit 1; fi
grep -q 'jobs sont encore en attente' /tmp/mail-fixture-pending.log
test -f "$STATE/INTERNAL-SITE-OPEN.json"
cmp -s "$APP/.env" /tmp/mail-fixture.env.before
test "$(sql "SELECT COUNT(*) FROM cofrac2production.jobs WHERE queue='held-fixture';")" = 1
sql "DELETE FROM cofrac2production.jobs WHERE queue='held-fixture';"

# Simulate a failed cache build after env mutation: reopen in the old mode.
cp -p /usr/bin/php8.4 /tmp/mail-fixture.php8.4.before
cat > /usr/bin/php8.4 <<'WRAPPER'
#!/usr/bin/env bash
if [ -f /tmp/mail-fixture-fail-cache ] && [[ "$*" = *'artisan config:cache'* ]]; then
    rm /tmp/mail-fixture-fail-cache
    echo 'Intentional cache fixture failure.' >&2
    exit 31
fi
exec /usr/bin/php "$@"
WRAPPER
chmod 0755 /usr/bin/php8.4
touch /tmp/mail-fixture-fail-cache
chown cofrac2-app:cofrac2-app /tmp/mail-fixture-fail-cache
if bash "$SCRIPT" --enable-real-mail > /tmp/mail-fixture-rollback.log 2>&1; then exit 1; fi
grep -q 'V2 rouverte avec les emails toujours bloques' /tmp/mail-fixture-rollback.log
test -f "$STATE/INTERNAL-SITE-OPEN.json"
cmp -s "$APP/.env" /tmp/mail-fixture.env.before
cmp -s "$STATE/RUNTIME-PREPARED.json" /tmp/mail-fixture.prepared.before.json
cp -p /tmp/mail-fixture.php8.4.before /usr/bin/php8.4

sql 'SELECT COUNT(*) FROM cofrac2production.dossier_activities WHERE kind="mail_simulated";' > /tmp/mail-fixture-simulated.before
bash "$SCRIPT" --enable-real-mail > /tmp/mail-fixture-enable.log 2>&1
test -f "$STATE/INTERNAL-SITE-OPEN.json"
app_cli "$APP/tools/deploy/production-mail.php" --inspect-enabled > /tmp/mail-fixture.after.json
app_cli "$APP/tools/deploy/production-runtime.php" --check > /tmp/mail-fixture.runtime.json
cp -p "$APP/.env" /tmp/mail-fixture.idempotent.before
bash "$SCRIPT" --enable-real-mail > /tmp/mail-fixture.idempotent.log 2>&1
grep -q 'deja actives' /tmp/mail-fixture.idempotent.log
cmp -s "$APP/.env" /tmp/mail-fixture.idempotent.before
php8.4 -r '
    require "/var/www/cofrac2/vendor/autoload.php";
    $env=Dotenv\Dotenv::parse(file_get_contents("/var/www/cofrac2/.env"));
    $r=json_decode(file_get_contents("/var/lib/cofrac2-production/RUNTIME-PREPARED.json"),true,64,JSON_THROW_ON_ERROR);
    $check=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
    if($env["MAIL_DELIVERY_ENABLED"]!=="true" || $env["MAIL_MAILER"]!=="smtp" || $env["SMSFACTOR_ENABLED"]!=="false"
        || $env["SMSFACTOR_MODE"]!=="simulate" || $env["CAPCEE_ENABLED"]!=="false"
        || $r["env_sha256"]!==hash_file("sha256","/var/www/cofrac2/.env") || count($r["mail_delivery_changes"])!==1
        || $check["mail_delivery_enabled"]!==true || $check["pending_jobs"]!==0 || $check["messages_sent"]!==0)exit(1);
' /tmp/mail-fixture.after.json
sql 'SELECT COUNT(*) FROM cofrac2production.dossier_activities WHERE kind="mail_simulated";' > /tmp/mail-fixture-simulated.after
cmp -s /tmp/mail-fixture-simulated.before /tmp/mail-fixture-simulated.after
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.dossier_sms;')" = 0
sha256sum -c /tmp/runtime-original.sha256

# Exercise a subsequent real release, not only the activation itself.
BASE=$(git_app rev-parse HEAD)
printf '\n{{-- Fixture release after mail activation --}}\n' >> "$APP/resources/views/components/user-email-conflict.blade.php"
git_app add resources/views/components/user-email-conflict.blade.php
git_app -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture-after-real-mail >/dev/null
REV=$(git_app rev-parse HEAD)
BUNDLE=$(mktemp -d /tmp/cofrac2-mail-release-XXXXXXXX)
install -d -m 0700 "$BUNDLE/build" "$BUNDLE/build/assets"
SHA=$(php8.4 -r '
    $vite=[];
    foreach(["resources/css/app.css"=>"app-mail.css","resources/js/app.js"=>"app-mail.js","resources/js/chart.js"=>"chart-mail.js"] as $src=>$name){
        file_put_contents($argv[1]."/build/assets/".$name,"/* synthetic fixture only */\n");chmod($argv[1]."/build/assets/".$name,0600);
        $vite[$src]=["src"=>$src,"file"=>"assets/".$name];
    }
    file_put_contents($argv[1]."/build/manifest.json",json_encode($vite));chmod($argv[1]."/build/manifest.json",0600);
    require "/var/www/cofrac2/tools/deploy/lib/ProductionAssetUpdate.php";
    echo Cofrac2\Deploy\ProductionAssetUpdate::seal($argv[1],$argv[2],$argv[3]);
' "$BUNDLE" "$REV" "$BASE")
cp -p "$APP/.env" /tmp/mail-fixture.enabled.env
bash "$APP/tools/deploy/configure-production-runtime.sh" --maintenance --reload-shared-apache > /tmp/mail-fixture-next-maintenance.log 2>&1
bash "$APP/tools/deploy/update-production-release.sh" --apply "$BUNDLE" "$SHA" > /tmp/mail-fixture-next-release.log 2>&1
bash "$APP/tools/deploy/configure-production-runtime.sh" --open --reload-shared-apache > /tmp/mail-fixture-next-open.log 2>&1
cmp -s "$APP/.env" /tmp/mail-fixture.enabled.env
test -f "$STATE/INTERNAL-SITE-OPEN.json"
app_cli "$APP/tools/deploy/production-runtime.php" --check > /tmp/mail-fixture.next-runtime.json
grep -q '"mail_delivery_enabled": true' /tmp/mail-fixture.next-runtime.json
sha256sum -c /tmp/runtime-original.sha256
printf 'OK : activation mail explicite, file vide obligatoire, restauration et reouverture sur echec, release suivante compatible ; aucun SMTP, email ou SMS envoye.\n'
