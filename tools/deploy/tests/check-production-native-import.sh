#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[ "${EUID:-$(id -u)}" = 0 ] && [ -f /.dockerenv ] && [ "${COFRAC2_IMPORT_TEST:-}" = 1 ] \
    && [ "$(hostname -s)" = ns365601 ] || { echo 'Fixture Docker root jetable uniquement.' >&2; exit 1; }
trap 'result=$?; if [ "$result" -ne 0 ]; then echo "FIXTURE FAILED AT LINE $LINENO" >&2; for log in /tmp/fixture-migrate.log /tmp/staging-check.log /tmp/staging-import-terminal.log; do if [ -f "$log" ]; then tail -25 "$log" >&2; fi; done; find /var/lib/cofrac2-production -name import.stderr -o -name migration.log | while read -r log; do tail -20 "$log" >&2; done; fi' EXIT
test ! -e /var/www/cofrac2
test ! -e /var/lib/cofrac2-production
test ! -e /home/ubuntu/www/coffrac_preprod
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
useradd --create-home --user-group cofrac2-deploy
useradd --system --create-home --user-group --home-dir /var/lib/cofrac2-app --shell /usr/sbin/nologin cofrac2-app
install -d -o cofrac2-deploy -g cofrac2-deploy -m 0751 "$APP"
for path in .gitignore app bootstrap config database lang public resources routes tools tests artisan composer.json composer.lock package.json package-lock.json vite.config.js; do
    cp -a "/test-source/$path" "$APP/"
done
while IFS= read -r file; do
    relative=${file#/test-source/}
    install -d "$APP/$(dirname "$relative")"
    cp -a "$file" "$APP/$relative"
done < <(find /test-source/storage -type f -name .gitignore)
cp -a /fixture-vendor "$APP/vendor"
find "$APP/bootstrap/cache" -type f ! -name .gitignore -delete
if [ "${COFRAC2_RUNTIME_TEST:-}" = 1 ]; then
    # Reproduce the first deployed template, then exercise the guarded repair on its prepared state.
    sed -i -e 's|^error_log = syslog$|error_log = /proc/self/fd/2|' -e '/^syslog.ident = cofrac2-php-fpm$/d' \
        -e 's|^php_admin_value\[error_log\] = syslog$|php_admin_value[error_log] = /proc/self/fd/2|' \
        "$APP/tools/deploy/cofrac2-php-fpm.conf.example"
fi
chown -R --no-dereference cofrac2-deploy:cofrac2-deploy "$APP"
runuser -u cofrac2-deploy -- git -C "$APP" init --initial-branch=v2 >/dev/null
runuser -u cofrac2-deploy -- git -C "$APP" add .
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture >/dev/null
REVISION=$(runuser -u cofrac2-deploy -- git -C "$APP" rev-parse HEAD)
printf '#!/bin/sh\nexec /usr/bin/php "$@"\n' > /usr/bin/php8.4
chmod 0755 /usr/bin/php8.4
install -d -m 0700 "$STATE" /etc/mysql /etc/apache2/sites-available /etc/apache2/sites-enabled
cat > /etc/mysql/fixture.cnf <<'INI'
[client]
host=127.0.0.1
protocol=tcp
user=root
INI
sql() { mysql --defaults-file=/etc/mysql/fixture.cnf --batch --skip-column-names -e "$1"; }
sql "CREATE DATABASE cofrac2production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cofrac2_app'@'localhost' IDENTIFIED BY 'FixtureOnlyDb123';
GRANT ALL PRIVILEGES ON cofrac2production.* TO 'cofrac2_app'@'localhost';
CREATE USER 'cofrac2_app'@'127.0.0.1' IDENTIFIED BY 'FixtureOnlyDb123';
GRANT ALL PRIVILEGES ON cofrac2production.* TO 'cofrac2_app'@'127.0.0.1';
CREATE DATABASE fiche_crm; CREATE TABLE fiche_crm.original_data (id int primary key, label varchar(64));
INSERT INTO fiche_crm.original_data VALUES (1,'ORIGINAL COFFRAC FIXTURE');"
sed 's/__APP_KEY__/base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=/; s/__DB_PASSWORD__/FixtureOnlyDb123/; s/__REVERB_APP_KEY__/fixture-key/; s/__REVERB_APP_SECRET__/fixture-secret/' \
    "$APP/tools/deploy/production.env.example" > "$APP/.env"
chown root:cofrac2-app "$APP/.env"
chmod 0640 "$APP/.env"
for directory in storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    install -d -m 2770 -o cofrac2-app -g cofrac2-deploy "$APP/$directory"
done
find "$APP" -path "$APP/.git" -prune -o -type d -exec chmod go+rx {} + -o -type f -exec chmod go+r {} +
chmod 0640 "$APP/.env"
chmod 0700 "$APP/.git"
runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app \
    php8.4 "$APP/artisan" migrate --force --no-interaction > /tmp/fixture-migrate.log 2>&1
printf '{"database":"cofrac2production","username":"cofrac2_app"}\n' > "$STATE/DATABASE-READY.json"
printf '{"database":"cofrac2production","runtime_user":"cofrac2-app","business_data_empty":true}\n' > "$STATE/SCHEMA-READY.json"
printf 'cofrac2-app:%s:%s\n' "$(id -u cofrac2-app)" "$(id -g cofrac2-app)" > "$STATE/runtime-identity"
cp "$APP/tools/deploy/apache-production-https.conf.example" /etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf
ln -s /etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf /etc/apache2/sites-enabled/cofrac2.geniuscontrole.com.conf
install -d -m 0700 /home/ubuntu/www/coffrac_preprod
for path in public/documents public/pdf public/media public/signature public/lot_dossier storage/app; do
    install -d -m 0700 "/home/ubuntu/www/coffrac_preprod/$path"
done
printf 'ORIGINAL PRIVATE ENV\n' > /home/ubuntu/www/coffrac_preprod/.env
printf 'ORIGINAL ENTRY POINT\n' > /home/ubuntu/www/coffrac_preprod/public/index.php
printf 'FAKE PDF CONTENT\n' > /home/ubuntu/www/coffrac_preprod/public/documents/test.pdf
find /home/ubuntu/www/coffrac_preprod -type f -exec sha256sum {} + > /tmp/original-fixture.sha256
sha256sum "$APP/.env" /etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf >> /tmp/original-fixture.sha256
if [ "${COFRAC2_REAL_PACKAGE:-}" = /real-package ]; then
    # Metadata/SQL rehearsal on the actual sealed source; physical server proof stays a separate check.
    install -d -o root -g cofrac2-app -m 0710 /srv/cofrac2-staging-imports
    REAL_RUN=/srv/cofrac2-staging-imports/real-package-proof
    install -d -o root -g cofrac2-app -m 0750 "$REAL_RUN" "$REAL_RUN/inputs"
    cp -a /real-package/. "$REAL_RUN/inputs/"
    REAL_DIGEST=$(sha256sum "$REAL_RUN/inputs/manifest.json" | cut -d' ' -f1)
    php8.4 -r '
        $m=json_decode(file_get_contents(dirname($argv[1])."/inputs/manifest.json"),true,512,JSON_THROW_ON_ERROR);
        $email="local-proof-".bin2hex(random_bytes(8))."@example.invalid";
        if (isset($m["seeded_admin"]["source_user_id"])) {
            foreach (file(dirname($argv[1])."/inputs/dataset/users.jsonl") as $line) {
                $row=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                if ($row["id"]===$m["seeded_admin"]["source_user_id"]) $email=strtolower(trim($row["email"]));
            }
        }
        file_put_contents($argv[1],json_encode(["package_sha256"=>$argv[2],"email"=>$email,"password"=>bin2hex(random_bytes(24))],JSON_THROW_ON_ERROR));
    ' "$REAL_RUN/admin.json" "$REAL_DIGEST"
    find "$REAL_RUN" -type d -exec chown root:cofrac2-app {} + -exec chmod 0750 {} +
    find "$REAL_RUN" -type f -exec chown root:cofrac2-app {} + -exec chmod 0440 {} +
    runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app \
        php8.4 -d memory_limit=1536M "$APP/tools/deploy/stage-production-import.php" --run "$REAL_RUN/inputs" "$REAL_DIGEST" > /report/report.json 2> /report/import.stderr
    php8.4 -r '$r=json_decode(file_get_contents("/report/report.json"),true,512,JSON_THROW_ON_ERROR);echo json_encode(["counts"=>$r["counts"],"staging_import"=>$r["staging_import"]],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";' > /report/summary.json
    rm "$REAL_RUN/admin.json"
    sha256sum -c /tmp/original-fixture.sha256
    test "$(sql 'SELECT label FROM fiche_crm.original_data WHERE id=1')" = 'ORIGINAL COFFRAC FIXTURE'
    echo 'OK : source reelle importee par le chemin de preparation production dans Docker isole. Preflight physique serveur encore requis.'
    exit 0
fi
PACKAGE=$(env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
    DB_URL= COFRAC2_IMPORT_TEST=1 COFFRAC_OFFLINE_REHEARSAL=true BCRYPT_ROUNDS=4 CACHE_STORE=array MAIL_MAILER=array \
    SESSION_DRIVER=array BROADCAST_CONNECTION=log SMSFACTOR_ENABLED=false CAPCEE_ENABLED=false LOG_CHANNEL=stderr \
    php8.4 "$APP/tools/deploy/tests/make-staging-import-fixture.php" "$REVISION")
DIGEST=$(sha256sum "$PACKAGE/manifest.json" | cut -d' ' -f1)
SCRIPT="$APP/tools/deploy/stage-production-import.sh"
for command in systemctl service apachectl a2ensite a2enmod; do
    printf '#!/bin/sh\necho "$0" >> /tmp/UNEXPECTED-SERVICE; exit 99\n' > "/usr/sbin/$command"
    chmod 0755 "/usr/sbin/$command"
done
test -f "$PACKAGE/manifest.json"
if bash "$SCRIPT" --check "$PACKAGE" "$(printf '%064d' 0)" > /tmp/bad-seal.log 2>&1; then exit 1; fi
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.users')" = 0
cp -p "$APP/.env" /tmp/original-fixture.env
sed -i 's/DB_DATABASE=cofrac2production/DB_DATABASE=fiche_crm/' "$APP/.env"
if bash "$SCRIPT" --check "$PACKAGE" "$DIGEST" > /tmp/bad-target.log 2>&1; then exit 1; fi
cp -p /tmp/original-fixture.env "$APP/.env"
sql "INSERT INTO cofrac2production.users (first_name,last_name,email,password) VALUES ('Existing','Account','existing@example.invalid','fixture');"
if bash "$SCRIPT" --check "$PACKAGE" "$DIGEST" > /tmp/nonempty-target.log 2>&1; then exit 1; fi
sql 'DELETE FROM cofrac2production.users;'
bash "$SCRIPT" --check "$PACKAGE" "$DIGEST" > /tmp/staging-check.log 2>&1
# A late verification failure must roll back the whole native import, including its tracking rows.
install -d -o root -g cofrac2-app -m 0710 /srv/cofrac2-staging-imports
FAIL_RUN=/srv/cofrac2-staging-imports/fixture-rollback
install -d -o root -g cofrac2-app -m 0750 "$FAIL_RUN" "$FAIL_RUN/inputs"
cp -a "$PACKAGE/." "$FAIL_RUN/inputs/"
FAIL_DIGEST=$(php8.4 -r '
    $d=$argv[1];$r=json_decode(file_get_contents($d."/rehearsal.json"),true);$r["counts"]["dossiers"]=999;
    file_put_contents($d."/rehearsal.json",json_encode($r,JSON_THROW_ON_ERROR));
    $m=json_decode(file_get_contents($d."/manifest.json"),true);$m["files"]["rehearsal.json"]=["bytes"=>filesize($d."/rehearsal.json"),"sha256"=>hash_file("sha256",$d."/rehearsal.json")];
    file_put_contents($d."/manifest.json",json_encode($m,JSON_THROW_ON_ERROR));echo hash_file("sha256",$d."/manifest.json");
' "$FAIL_RUN/inputs")
php8.4 -r 'file_put_contents($argv[1],json_encode(["email"=>"rollback-seed@example.invalid","password"=>"FixtureRollback123","package_sha256"=>$argv[2]],JSON_THROW_ON_ERROR));' "$FAIL_RUN/admin.json" "$FAIL_DIGEST"
find "$FAIL_RUN" -type d -exec chown root:cofrac2-app {} + -exec chmod 0750 {} +
find "$FAIL_RUN" -type f -exec chown root:cofrac2-app {} + -exec chmod 0440 {} +
if runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app \
    php8.4 "$APP/tools/deploy/stage-production-import.php" --run "$FAIL_RUN/inputs" "$FAIL_DIGEST" > /tmp/late-failure.json 2> /tmp/late-failure.stderr; then exit 1; fi
grep -q 'Effectif natif different' /tmp/late-failure.stderr
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.users')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.legacy_records')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.coffrac_import_runs')" = 0
rm "$FAIL_RUN/admin.json"
chmod 0700 "$FAIL_RUN"
printf 'real-seed@example.invalid\nFixtureSeed123\nFixtureSeed123\n' |
    timeout 240 script -q -e -c "bash '$SCRIPT' --import-staging '$PACKAGE' '$DIGEST'" /dev/null > /tmp/staging-import-terminal.log 2>&1
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.users')" = 7
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.users WHERE admin=1')" = 1
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.users WHERE portal_enabled=1')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.dossiers')" = 3
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.dossier_operations')" = 2
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.dossier_billings')" = 1
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.jobs')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.coffrac_user_links')" = 6
test -f "$STATE/STAGING-IMPORTED.json"
if bash "$SCRIPT" --import-staging "$PACKAGE" "$DIGEST" > /tmp/repeated-staging.log 2>&1; then exit 1; fi
sha256sum -c /tmp/original-fixture.sha256
test "$(sql 'SELECT label FROM fiche_crm.original_data WHERE id=1')" = 'ORIGINAL COFFRAC FIXTURE'
test ! -e /tmp/UNEXPECTED-SERVICE
test -z "$(find /srv/cofrac2-staging-imports -name admin.json -print -quit)"
printf 'OK : import reel garde-fous production dans Docker, source intacte, compte SQL limite, acces et envois bloques, refus de doublon.\n'
if [ "${COFRAC2_RUNTIME_TEST:-}" = 1 ]; then
    bash "$APP/tools/deploy/tests/check-production-runtime.sh" "$PACKAGE"
fi
