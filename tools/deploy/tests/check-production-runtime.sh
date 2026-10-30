#!/usr/bin/env bash

# Real Apache, PHP-FPM, Laravel worker and Reverb inside the disposable SQL fixture only.
set -Eeuo pipefail
umask 077
[ -f /.dockerenv ] && [ "${COFRAC2_RUNTIME_TEST:-}" = 1 ] && [ "$(hostname -s)" = ns365601 ] \
    && [ "$#" = 1 ] && [ -f /tmp/real-apache2ctl ] || { echo 'Fixture runtime Docker uniquement.' >&2; exit 1; }
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
SITE=/etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf
DOMAIN=cofrac2.geniuscontrole.com
PACKAGE=$1
trap 'printf "RUNTIME_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2' ERR
# The native-import fixture copies a local checkout, including ignored development assets.
rm -rf -- "$APP/public/build"
rm -f -- "$APP/public/hot"
cp /tmp/real-apache2ctl /usr/sbin/apache2ctl
cp /tmp/real-a2enmod /usr/sbin/a2enmod
/usr/sbin/a2enmod proxy proxy_fcgi rewrite ssl >/dev/null
rm -f /etc/apache2/sites-enabled/000-default.conf
printf 'ServerName ns365601\n' > /etc/apache2/conf-enabled/server-name.conf
cat > /etc/apache2/sites-available/original-fixture.conf <<'APACHE'
<VirtualHost *:80>
    ServerName cofrac.geniuscontrole.com
    DocumentRoot /old-site
    <Directory /old-site>
        Require all granted
    </Directory>
</VirtualHost>
APACHE
ln -s ../sites-available/original-fixture.conf /etc/apache2/sites-enabled/000-original-fixture.conf
install -d -m 0755 /old-site
printf 'ORIGINAL SITE INTACT\n' > /old-site/index.html
chmod 0644 /old-site/index.html
sha256sum /etc/apache2/sites-available/original-fixture.conf /old-site/index.html > /tmp/runtime-original.sha256
find /home/ubuntu/www/coffrac_preprod -type f -exec sha256sum {} + >> /tmp/runtime-original.sha256
CERT=/var/lib/cofrac2-letsencrypt/live/$DOMAIN
install -d -m 0700 "$CERT"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=Cofrac2FixtureCA \
    -addext basicConstraints=critical,CA:TRUE -keyout /tmp/runtime-ca.key -out /usr/local/share/ca-certificates/cofrac2-fixture.crt >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj /CN=$DOMAIN -keyout "$CERT/privkey.pem" -out /tmp/runtime-server.csr >/dev/null 2>&1
printf 'subjectAltName=DNS:%s\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' "$DOMAIN" > /tmp/runtime-server.ext
openssl x509 -req -in /tmp/runtime-server.csr -CA /usr/local/share/ca-certificates/cofrac2-fixture.crt -CAkey /tmp/runtime-ca.key -CAcreateserial \
    -days 2 -extfile /tmp/runtime-server.ext -out "$CERT/fullchain.pem" >/dev/null 2>&1
update-ca-certificates >/dev/null 2>&1
# systemd supplies a Unix stream socket as stderr, unlike Docker's usual pipe/file descriptors.
cat > /tmp/runtime-socket-stderr.php <<'PHP'
<?php
$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$process = proc_open(['/usr/sbin/php-fpm8.4', '--nodaemonize', '--fpm-config', '/etc/cofrac2/php-fpm.conf'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/tmp/runtime-fpm-stdout.log', 'a'], 2 => $sockets[1]], $pipes);
fclose($sockets[1]);
file_put_contents('/tmp/runtime-fpm.log', stream_get_contents($sockets[0]), FILE_APPEND);
fclose($sockets[0]);
exit(proc_close($process));
PHP
cat > /usr/sbin/systemctl <<'CONTROL'
#!/usr/bin/env bash
set -eu
pidfile() { printf '/tmp/runtime-unit-%s.pid' "$1"; }
if [ "$1" = show ]; then
    shift
    for unit in "$@"; do
        [[ "$unit" != --* ]] || continue
        printf 'Id=%s\nActiveState=active\nMainPID=%s\nExecMainStartTimestampMonotonic=123456\n\n' "$unit" "$(cat /run/apache2/apache2.pid)"
    done
    exit 0
fi
case "$1" in
    is-active)
        unit=${@: -1}
        case "$unit" in apache2.service|mysql.service) exit 0 ;; esac
        [ -f "$(pidfile "$unit")" ] && kill -0 "$(cat "$(pidfile "$unit")")" 2>/dev/null ;;
    daemon-reload) : ;;
    reload)
        case "$2" in
            apache2.service) /tmp/real-apache2ctl graceful ;;
            cofrac2-php-fpm.service) pkill -USR2 -P "$(cat "$(pidfile "$2")")" ;;
            *) exit 91 ;;
        esac ;;
    start)
        shift
        for unit in "$@"; do
            case "$unit" in
                cofrac2-php-fpm.service)
                    install -d -m 0755 /run/cofrac2
                    php8.4 /tmp/runtime-socket-stderr.php & ;;
                cofrac2-reverb.service)
                    cd /var/www/cofrac2
                    runuser -u cofrac2-app -- php8.4 artisan reverb:start --host=127.0.0.1 --port=8091 > /tmp/runtime-reverb.log 2>&1 & ;;
                cofrac2-queue.service)
                    cd /var/www/cofrac2
                    runuser -u cofrac2-app -- php8.4 artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=1800 > /tmp/runtime-queue.log 2>&1 & ;;
                *) exit 95 ;;
            esac
            echo "$!" > "$(pidfile "$unit")"
        done ;;
    stop|disable)
        shift
        [ "${1:-}" != --now ] || shift
        for unit in "$@"; do
            [[ "$unit" = cofrac2-* ]] || exit 94
            if [ -f "$(pidfile "$unit")" ]; then
                pkill -TERM -P "$(cat "$(pidfile "$unit")")" 2>/dev/null || true
                kill -TERM "$(cat "$(pidfile "$unit")")" 2>/dev/null || true
                rm -f "$(pidfile "$unit")"
            fi
        done
        sleep 2 ;;
    enable) shift; for unit in "$@"; do [[ "$unit" = cofrac2-* ]] || exit 93; done ;;
    *) exit 92 ;;
esac
CONTROL
chmod 0755 /usr/sbin/systemctl
# Keep a real config test, with one intentional failure to exercise rollback.
cat > /usr/sbin/apache2ctl <<'APACHECTL'
#!/usr/bin/env bash
if [ "${COFRAC2_FORCE_APACHE_FAILURE:-}" = 1 ] && [ "${1:-}" = configtest ] \
    && grep -q 'DocumentRoot /var/www/cofrac2/public' /etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf; then
    echo 'Intentional fixture-only config failure.' >&2
    exit 23
fi
exec /tmp/real-apache2ctl "$@"
APACHECTL
chmod 0755 /usr/sbin/apache2ctl
cat > /usr/sbin/curl <<'CURL'
#!/usr/bin/env bash
if [ "${COFRAC2_FORCE_HTTP_FAILURE:-}" = 1 ] && [ "${@: -1}" = https://cofrac2.geniuscontrole.com/login ]; then
    printf 500
    exit 0
fi
exec /usr/bin/curl "$@"
CURL
chmod 0755 /usr/sbin/curl
/tmp/real-apache2ctl start
trap 'for file in /tmp/runtime-unit-*.pid; do if [ -f "$file" ]; then kill -TERM "$(cat "$file")" 2>/dev/null || true; fi; done; /tmp/real-apache2ctl stop || true' EXIT
sleep 1
test "$(curl --noproxy '*' -fsS --resolve cofrac.geniuscontrole.com:80:127.0.0.1 http://cofrac.geniuscontrole.com/)" = 'ORIGINAL SITE INTACT'
BUNDLE=$(php8.4 -r '
    require "/var/www/cofrac2/tests/Fixtures/ProductionRuntimeBundleFixture.php";
    $package=json_decode(file_get_contents($argv[1]."/manifest.json"),true,64,JSON_THROW_ON_ERROR);
    $plan=json_decode(file_get_contents($argv[1]."/documents/report.json"),true,64,JSON_THROW_ON_ERROR);
    echo productionRuntimeBundleFixture(["revision"=>$package["revision"],"import_package_sha256"=>hash_file("sha256",$argv[1]."/manifest.json"),"documents"=>[
       "inventory"=>$package["server_documents"]["inventory"],"verification"=>$package["server_documents"]["verification"],
       "plan_sha256"=>$package["document_plan_sha256"],"inventory_sha256"=>$plan["inventory_sha256"],"verification_sha256"=>$plan["verification_sha256"]]]);
' "$PACKAGE")
SHA=$(sha256sum "$BUNDLE/manifest.json" | cut -d' ' -f1)
for directory in app config routes resources tools; do
    find "$APP/$directory" -type d -exec chgrp cofrac2-app {} + -exec chmod 2750 {} +
    find "$APP/$directory" -type f -exec chgrp cofrac2-app {} + -exec chmod u+rw,g=r,o= {} +
done
SCRIPT="$APP/tools/deploy/configure-production-runtime.sh"
if bash "$SCRIPT" --open > /tmp/runtime-no-consent.log 2>&1; then exit 1; fi
bash "$SCRIPT" --prepare "$BUNDLE" "$SHA" > /tmp/runtime-prepare.log 2>&1
test -f "$STATE/RUNTIME-PREPARED.json"
test ! -e /etc/apache2/conf-enabled/cofrac2-runtime-modules.conf
cmp -s "$SITE" "$APP/tools/deploy/apache-production-https.conf.example"
sha256sum -c /tmp/runtime-original.sha256
if bash "$SCRIPT" --open --reload-shared-apache > /tmp/runtime-fpm-failure.log 2>&1; then exit 1; fi
grep -q 'failed to open error_log (/proc/self/fd/2)' /tmp/runtime-fpm.log
grep -q 'socket PHP V2 absent' /tmp/runtime-fpm-failure.log
cmp -s "$SITE" "$APP/tools/deploy/apache-production-https.conf.example"
test ! -e "$STATE/INTERNAL-SITE-OPEN.json"
sha256sum "$APP/.env" /srv/cofrac2-runtime/documents.list0 > /tmp/runtime-before-repair.sha256
cp -p /etc/cofrac2/php-fpm.conf /tmp/runtime-legacy-fpm.conf
cp -p "$STATE/RUNTIME-PREPARED.json" /tmp/runtime-prepared-before-repair.json
cp /test-source/tools/deploy/cofrac2-php-fpm.conf.example "$APP/tools/deploy/cofrac2-php-fpm.conf.example"
runuser -u cofrac2-deploy -- git -C "$APP" add tools/deploy/cofrac2-php-fpm.conf.example
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture-fpm-syslog >/dev/null
cp -p "$APP/.env" /tmp/runtime-before-repair.env
printf '\n# unexpected configuration change\n' >> "$APP/.env"
if bash "$SCRIPT" --repair-fpm-logging > /tmp/runtime-repair-bad-env.log 2>&1; then exit 1; fi
cp -p /tmp/runtime-before-repair.env "$APP/.env"
cmp -s "$STATE/RUNTIME-PREPARED.json" /tmp/runtime-prepared-before-repair.json
printf '\n# unexpected FPM change\n' >> /etc/cofrac2/php-fpm.conf
if bash "$SCRIPT" --repair-fpm-logging > /tmp/runtime-repair-bad-fpm.log 2>&1; then exit 1; fi
cp -p /tmp/runtime-legacy-fpm.conf /etc/cofrac2/php-fpm.conf
cmp -s "$STATE/RUNTIME-PREPARED.json" /tmp/runtime-prepared-before-repair.json
printf '<?php // fixture-only unrelated change\n' > "$APP/app/UnrelatedFixture.php"
chown cofrac2-deploy:cofrac2-deploy "$APP/app/UnrelatedFixture.php"
runuser -u cofrac2-deploy -- git -C "$APP" add app/UnrelatedFixture.php
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture-unrelated >/dev/null
if bash "$SCRIPT" --repair-fpm-logging > /tmp/runtime-repair-bad-code.log 2>&1; then exit 1; fi
grep -q 'autres changements de code' /tmp/runtime-repair-bad-code.log
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid revert --no-edit HEAD >/dev/null
bash "$SCRIPT" --repair-fpm-logging > /tmp/runtime-repair.log 2>&1
sha256sum -c /tmp/runtime-before-repair.sha256
cmp -s /etc/cofrac2/php-fpm.conf "$APP/tools/deploy/cofrac2-php-fpm.conf.example"
test ! -e "$STATE/INTERNAL-SITE-OPEN.json"
cmp -s "$SITE" "$APP/tools/deploy/apache-production-https.conf.example"
php8.4 -r '
    $before=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
    $after=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    $repairs=$after["runtime_repairs"];unset($after["runtime_repairs"]);
    if(count($repairs)!==1||$repairs[0]["kind"]!=="fpm_syslog"||$repairs[0]["from_revision"]!==$before["revision"]||$repairs[0]["to_revision"]!==$after["revision"])exit(1);
    $after["revision"]=$before["revision"];if($before!==$after)exit(1);
' /tmp/runtime-prepared-before-repair.json "$STATE/RUNTIME-PREPARED.json"
if COFRAC2_FORCE_APACHE_FAILURE=1 bash "$SCRIPT" --open --reload-shared-apache > /tmp/runtime-rollback.log 2>&1; then exit 1; fi
cmp -s "$SITE" "$APP/tools/deploy/apache-production-https.conf.example"
test ! -e /etc/apache2/conf-enabled/cofrac2-runtime-modules.conf
test ! -e "$STATE/INTERNAL-SITE-OPEN.json"
sha256sum -c /tmp/runtime-original.sha256
if COFRAC2_FORCE_HTTP_FAILURE=1 bash "$SCRIPT" --open --reload-shared-apache > /tmp/runtime-http-rollback.log 2>&1; then exit 1; fi
cmp -s "$SITE" "$APP/tools/deploy/apache-production-https.conf.example"
test ! -e /etc/apache2/conf-enabled/cofrac2-runtime-modules.conf
test ! -e "$STATE/INTERNAL-SITE-OPEN.json"
sha256sum -c /tmp/runtime-original.sha256
bash "$SCRIPT" --open --reload-shared-apache > /tmp/runtime-open.log 2>&1
test -f "$STATE/INTERNAL-SITE-OPEN.json"
test "$(curl --noproxy '*' -fsS --resolve cofrac.geniuscontrole.com:80:127.0.0.1 http://cofrac.geniuscontrole.com/)" = 'ORIGINAL SITE INTACT'
sha256sum -c /tmp/runtime-original.sha256
curl --noproxy '*' -fsS --resolve "$DOMAIN:443:127.0.0.1" -c /tmp/runtime-cookie.jar "https://$DOMAIN/login" > /tmp/runtime-login.html
TOKEN=$(php8.4 -r 'preg_match("/name=\"_token\" value=\"([^\"]+)/",file_get_contents($argv[1]),$m);echo $m[1]??"";' /tmp/runtime-login.html)
test -n "$TOKEN"
code=$(curl --noproxy '*' -sS --resolve "$DOMAIN:443:127.0.0.1" -b /tmp/runtime-cookie.jar -c /tmp/runtime-cookie.jar \
    --data-urlencode "_token=$TOKEN" --data-urlencode 'email=real-seed@example.invalid' --data-urlencode 'password=FixtureSeed123' \
    -o /tmp/runtime-auth.body -w '%{http_code}' "https://$DOMAIN/login")
printf 'Auth HTTP %s\n' "$code"
test "$code" = 302
code=$(curl --noproxy '*' -sS --resolve "$DOMAIN:443:127.0.0.1" -b /tmp/runtime-cookie.jar \
    -o /tmp/runtime-dossiers.html -w '%{http_code}' "https://$DOMAIN/v2/dossiers")
printf 'Dossiers authentifies HTTP %s\n' "$code"
test "$code" = 200
code=$(curl --noproxy '*' -sS --resolve "$DOMAIN:443:127.0.0.1" -b /tmp/runtime-cookie.jar \
    -o /tmp/runtime-document.pdf -w '%{http_code}' "https://$DOMAIN/v2/dossiers/101/documents-collectes/601")
test "$code" = 200
cmp -s /tmp/runtime-document.pdf /srv/cofrac2-document-archive/files/public/documents/test.pdf
if bash "$SCRIPT" --update-user-management > /tmp/runtime-update-active.log 2>&1; then exit 1; fi
bash "$SCRIPT" --maintenance --reload-shared-apache > /tmp/runtime-maintenance.log 2>&1
test ! -e "$STATE/INTERNAL-SITE-OPEN.json"
test ! -e /etc/apache2/conf-enabled/cofrac2-runtime-modules.conf
cmp -s "$SITE" "$APP/tools/deploy/apache-production-https.conf.example"
mysql --defaults-file=/etc/mysql/fixture.cnf --execute "INSERT INTO cofrac2production.users
    (first_name,last_name,email,password,role,admin,deleted_at,created_at,updated_at)
    VALUES ('Disabled','Fixture','disabled-runtime@example.invalid','fixture-no-login-hash',2,0,NOW(),NOW(),NOW());"
mysql --defaults-file=/etc/mysql/fixture.cnf --batch --execute 'SELECT id,email,password,role,admin,deleted_at,external_client_id,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/runtime-users-before-update.tsv
sha256sum "$APP/.env" /srv/cofrac2-runtime/documents.list0 > /tmp/runtime-before-update.sha256
cp -p "$STATE/RUNTIME-PREPARED.json" /tmp/runtime-prepared-before-update.json
printf '\n{{-- Fixture PHP-only update --}}\n' >> "$APP/resources/views/components/user-email-conflict.blade.php"
runuser -u cofrac2-deploy -- git -C "$APP" add resources/views/components/user-email-conflict.blade.php
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture-user-management >/dev/null
printf '<?php // unrelated configuration change\n' > "$APP/config/unrelated-fixture.php"
chown cofrac2-deploy:cofrac2-deploy "$APP/config/unrelated-fixture.php"
runuser -u cofrac2-deploy -- git -C "$APP" add config/unrelated-fixture.php
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture-unrelated-config >/dev/null
if bash "$SCRIPT" --update-user-management > /tmp/runtime-update-bad-code.log 2>&1; then exit 1; fi
grep -q 'autres changements' /tmp/runtime-update-bad-code.log
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid revert --no-edit HEAD >/dev/null
cp -p "$APP/.env" /tmp/runtime-before-update.env
printf '\n# unexpected environment change\n' >> "$APP/.env"
if bash "$SCRIPT" --update-user-management > /tmp/runtime-update-bad-env.log 2>&1; then exit 1; fi
cp -p /tmp/runtime-before-update.env "$APP/.env"
cmp -s "$STATE/RUNTIME-PREPARED.json" /tmp/runtime-prepared-before-update.json
chmod 0600 "$APP/app/Rules/AvailableUserEmail.php"
bash "$SCRIPT" --update-user-management > /tmp/runtime-update.log 2>&1
sha256sum -c /tmp/runtime-before-update.sha256
runuser -u cofrac2-app -- test -r "$APP/app/Rules/AvailableUserEmail.php"
runuser -u cofrac2-app -- test ! -w "$APP/app/Rules/AvailableUserEmail.php"
mysql --defaults-file=/etc/mysql/fixture.cnf --batch --execute 'SELECT id,email,password,role,admin,deleted_at,external_client_id,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/runtime-users-after-update.tsv
cmp -s /tmp/runtime-users-before-update.tsv /tmp/runtime-users-after-update.tsv
php8.4 -r '
    $before=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
    $after=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    $updates=$after["runtime_updates"];unset($after["runtime_updates"]);
    if(count($updates)!==1||$updates[0]["kind"]!=="user_management_php_only"||$updates[0]["from_revision"]!==$before["revision"]||$updates[0]["to_revision"]!==$after["revision"])exit(1);
    $after["revision"]=$before["revision"];if($before!==$after)exit(1);
' /tmp/runtime-prepared-before-update.json "$STATE/RUNTIME-PREPARED.json"
bash "$SCRIPT" --open --reload-shared-apache > /tmp/runtime-reopen.log 2>&1
test -f "$STATE/INTERNAL-SITE-OPEN.json"
curl --noproxy '*' -fsS --resolve "$DOMAIN:443:127.0.0.1" -b /tmp/runtime-cookie.jar -c /tmp/runtime-cookie.jar \
    "https://$DOMAIN/admin/users" > /tmp/runtime-users.html
TOKEN=$(php8.4 -r 'preg_match("/name=\"_token\" value=\"([^\"]+)/",file_get_contents($argv[1]),$m);echo $m[1]??"";' /tmp/runtime-users.html)
test -n "$TOKEN"
code=$(curl --noproxy '*' -sS --resolve "$DOMAIN:443:127.0.0.1" -b /tmp/runtime-cookie.jar -c /tmp/runtime-cookie.jar \
    -H "Referer: https://$DOMAIN/admin/users" --data-urlencode "_token=$TOKEN" --data-urlencode 'first_name=Existing' \
    --data-urlencode 'last_name=Fixture' --data-urlencode 'email=disabled-runtime@example.invalid' \
    --data-urlencode 'role=1' --data-urlencode 'admin=0' -o /tmp/runtime-user-conflict.body -w '%{http_code}' "https://$DOMAIN/admin/users")
test "$code" = 302
curl --noproxy '*' -fsS --resolve "$DOMAIN:443:127.0.0.1" -b /tmp/runtime-cookie.jar -c /tmp/runtime-cookie.jar \
    "https://$DOMAIN/admin/users" > /tmp/runtime-user-conflict.html
grep -q 'Retrouver le compte existant' /tmp/runtime-user-conflict.html
grep -q 'compte interne désactivé' /tmp/runtime-user-conflict.html
curl --noproxy '*' -fsS --resolve "$DOMAIN:443:127.0.0.1" -b /tmp/runtime-cookie.jar \
    "https://$DOMAIN/admin/users?q=disabled-runtime%40example.invalid&status=all" > /tmp/runtime-user-existing.html
grep -q 'Restaurer' /tmp/runtime-user-existing.html
mysql --defaults-file=/etc/mysql/fixture.cnf --batch --execute 'SELECT id,email,password,role,admin,deleted_at,external_client_id,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/runtime-users-after-request.tsv
cmp -s /tmp/runtime-users-before-update.tsv /tmp/runtime-users-after-request.tsv
sha256sum -c /tmp/runtime-original.sha256
printf 'OK : runtime, mise a jour PHP, compte desactive sans doublon, TLS, authentification, worker et Reverb verifies ; site original inchange.\n'

# SMTP selected in editable settings must not prevent a release while delivery stays blocked.
runuser -u cofrac2-app -- php8.4 -r '
    chdir("/var/www/cofrac2"); require "vendor/autoload.php";
    $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $id=App\Models\User::where("admin",true)->sole()->id;
    app(App\Services\ApplicationSettings::class)->update(["mail.mailer"=>"smtp"],$id);
'
runtime_check() { runuser -u cofrac2-app -- php8.4 "$APP/tools/deploy/production-runtime.php" --check; }
runtime_check > /tmp/runtime-smtp-check.json
cat "$APP/tools/deploy/production-runtime.php" | runuser -u cofrac2-app -- php8.4 -- --check > /tmp/runtime-stdin-check.json
cmp -s /tmp/runtime-smtp-check.json /tmp/runtime-stdin-check.json
for action in --prepare --queue-probe; do
    if cat "$APP/tools/deploy/production-runtime.php" | runuser -u cofrac2-app -- php8.4 -- "$action" > /tmp/runtime-stdin-denied.log 2>&1; then exit 1; fi
    grep -q 'Preparation runtime reservee au serveur V2' /tmp/runtime-stdin-denied.log
done
if cat "$APP/tools/deploy/production-runtime.php" | php8.4 -- --check > /tmp/runtime-root-denied.log 2>&1; then exit 1; fi
grep -q 'Controle applicatif reserve au runtime dedie' /tmp/runtime-root-denied.log
cp -p "$APP/bootstrap/cache/config.php" /tmp/runtime-delivery-cache.before.php
for value in true null; do
    php8.4 -r '
        $config=require $argv[1]; $config["mail"]["delivery_enabled"]=json_decode($argv[2],true,32,JSON_THROW_ON_ERROR);
        file_put_contents($argv[1],"<?php return ".var_export($config,true).";\n");
    ' "$APP/bootstrap/cache/config.php" "$value"
    if runtime_check > /tmp/runtime-delivery-denied.log 2>&1; then exit 1; fi
    grep -q 'Livraison mail effective differente de la configuration approuvee' /tmp/runtime-delivery-denied.log
    if cat "$APP/tools/deploy/production-runtime.php" | runuser -u cofrac2-app -- php8.4 -- --check > /tmp/runtime-delivery-stdin-denied.log 2>&1; then exit 1; fi
    grep -q 'Livraison mail effective differente de la configuration approuvee' /tmp/runtime-delivery-stdin-denied.log
done
cp -p /tmp/runtime-delivery-cache.before.php "$APP/bootstrap/cache/config.php"
runtime_check > /tmp/runtime-smtp-restored.json
sha256sum -c /tmp/runtime-before-update.sha256
printf 'OK : SMTP conserve sans livraison, precontrole epingle en lecture seule, livraison active/absente et modes mutateurs refuses.\n'
bash "$APP/tools/deploy/tests/check-production-release.sh"
bash "$APP/tools/deploy/tests/check-production-mail.sh"
bash "$APP/tools/deploy/tests/check-production-domains.sh"
if [ -f "$APP/tools/deploy/tests/check-production-calendar.sh" ]; then
    bash "$APP/tools/deploy/tests/check-production-calendar.sh"
fi
if [ -f "$APP/tools/deploy/tests/check-correctifs-launcher.sh" ]; then
    bash "$APP/tools/deploy/tests/check-correctifs-launcher.sh"
fi
