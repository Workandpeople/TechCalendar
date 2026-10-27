#!/usr/bin/env bash

# Disposable extension of the complete native-import/runtime/mail fixture only.
set -Eeuo pipefail
umask 077
[ -f /.dockerenv ] && [ "${COFRAC2_RUNTIME_TEST:-}" = 1 ] && [ "$(hostname -s)" = ns365601 ] \
    && [ -f /tmp/runtime-ca.key ] || { echo 'Fixture Docker runtime uniquement.' >&2; exit 1; }
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
SCRIPT="$APP/tools/deploy/configure-production-domains.sh"
trap 'printf "DOMAIN_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2' ERR
DOMAIN=cofrac.geniuscontrole.com
CERT=/etc/fixture-domain-tls
install -d -m 0700 "$CERT"
openssl req -newkey rsa:2048 -nodes -subj /CN=$DOMAIN -keyout "$CERT/privkey.pem" -out /tmp/domain-server.csr >/dev/null 2>&1
printf 'subjectAltName=DNS:%s,DNS:www.%s\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' "$DOMAIN" "$DOMAIN" > /tmp/domain-server.ext
openssl x509 -req -in /tmp/domain-server.csr -CA /usr/local/share/ca-certificates/cofrac2-fixture.crt -CAkey /tmp/runtime-ca.key \
    -CAcreateserial -days 2 -extfile /tmp/domain-server.ext -out "$CERT/fullchain.pem" >/dev/null 2>&1
rm /etc/apache2/sites-enabled/000-original-fixture.conf
for port in 80 443; do
    name="$DOMAIN.conf"; [ "$port" = 80 ] || name="$DOMAIN-le-ssl.conf"
    cat > "/tmp/original-$port.conf" <<APACHE
<VirtualHost *:$port>
    ServerName $DOMAIN
    ServerAlias www.$DOMAIN
    DocumentRoot /home/ubuntu/www/coffrac_preprod/public
    SSLEngine on
    SSLCertificateFile $CERT/fullchain.pem
    SSLCertificateKeyFile $CERT/privkey.pem
</VirtualHost>
APACHE
    php8.4 -r 'require $argv[1];echo legacyMaintenanceSite(file_get_contents($argv[2]),(int)$argv[3]);' \
        "$APP/tools/deploy/legacy-domain-maintenance.php" "/tmp/original-$port.conf" "$port" \
        > "/etc/apache2/sites-available/$name"
    chmod 0644 "/etc/apache2/sites-available/$name"
    ln -s "../sites-available/$name" "/etc/apache2/sites-enabled/$name"
done
install -d -m 0755 /var/www/cofrac-legacy-maintenance /home/ubuntu/www/coffrac_preprod/public/.well-known/acme-challenge
# The import fixture deliberately made V1 directories root-only; emulate its public ACME webroot here.
chmod 0711 /home/ubuntu/www/coffrac_preprod /home/ubuntu/www/coffrac_preprod/public
chmod 0755 /home/ubuntu/www/coffrac_preprod/public/.well-known
install -m 0644 "$APP/tools/deploy/legacy-domain-maintenance.html" /var/www/cofrac-legacy-maintenance/index.html
printf 'challenge-fixture\n' > /home/ubuntu/www/coffrac_preprod/public/.well-known/acme-challenge/DOMAIN_TEST
chmod 0644 /home/ubuntu/www/coffrac_preprod/public/.well-known/acme-challenge/DOMAIN_TEST
install -d -m 0700 "$STATE/legacy-domain-maintenance/run-domain-fixture"
HISTORY="$STATE/legacy-domain-maintenance"
printf '%s\n' "$HISTORY/run-domain-fixture" > "$HISTORY/active-run"
sha256sum /etc/apache2/sites-available/cofrac.geniuscontrole.com{,-le-ssl}.conf \
    /var/www/cofrac-legacy-maintenance/index.html > "$HISTORY/run-domain-fixture/active.sha256"
apache2ctl configtest
systemctl reload apache2.service
sha256sum "$CERT/privkey.pem" "$CERT/fullchain.pem" > /tmp/domain-certificates.sha256
cp -p "$APP/.env" /tmp/domain-env.before
cp -p "$STATE/RUNTIME-PREPARED.json" /tmp/domain-prepared.before
# A failure after the env change must restore the previous configuration and reopen V2.
cp -p /usr/bin/php8.4 /tmp/domain-php.before
cat > /usr/bin/php8.4 <<'WRAPPER'
#!/usr/bin/env bash
if [[ "$*" = *'/var/www/cofrac2/artisan config:cache'* ]] && [ -f /tmp/domain-fail-cache ]; then
    rm /tmp/domain-fail-cache
    exit 48
fi
exec /usr/bin/php "$@"
WRAPPER
chmod 0755 /usr/bin/php8.4
touch /tmp/domain-fail-cache
chown cofrac2-app:cofrac2-app /tmp/domain-fail-cache
if bash "$SCRIPT" --activate --reload-shared-apache > /tmp/domain-rollback.log 2>&1; then exit 1; fi
cp -p /tmp/domain-php.before /usr/bin/php8.4
cmp -s "$APP/.env" /tmp/domain-env.before
cmp -s "$STATE/RUNTIME-PREPARED.json" /tmp/domain-prepared.before
test ! -e "$STATE/PRODUCTION-DOMAINS.json"
test -f "$STATE/INTERNAL-SITE-OPEN.json"
bash "$SCRIPT" --activate --reload-shared-apache > /tmp/domain-open.log 2>&1
php8.4 -r '
    require "/var/www/cofrac2/vendor/autoload.php";
    $a=Dotenv\Dotenv::parse(file_get_contents($argv[1]));$b=Dotenv\Dotenv::parse(file_get_contents($argv[2]));
    foreach(["APP_URL","VITE_REVERB_HOST","REVERB_ALLOWED_ORIGINS"] as $k)unset($a[$k],$b[$k]);
    if($a!==$b)exit(1);
' /tmp/domain-env.before "$APP/.env"
for host in cofrac.geniuscontrole.com cofrac2.geniuscontrole.com; do
    for path in /login /up; do
        curl --noproxy '*' -fsS --resolve "$host:443:127.0.0.1" "https://$host$path" >/dev/null
    done
done
curl --noproxy '*' -fsS --resolve "$DOMAIN:80:127.0.0.1" "http://$DOMAIN/.well-known/acme-challenge/DOMAIN_TEST" | grep -q challenge-fixture
runuser -u cofrac2-app -- php8.4 -r '
    chdir("/var/www/cofrac2");require "vendor/autoload.php";$app=require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config("app.url")!=="https://cofrac.geniuscontrole.com"||config("reverb.client.host")!=="cofrac.geniuscontrole.com"
        ||config("services.smsfactor.enabled")||config("services.smsfactor.mode")!=="simulate"
        ||config("mail.delivery_enabled")!==true||config("session.domain")!==null)exit(1);
    if(!str_starts_with(route("password.reset",["token"=>"fixture"]),"https://cofrac.geniuscontrole.com/"))exit(1);
'
sha256sum --status -c /tmp/domain-certificates.sha256
bash "$SCRIPT" --activate --reload-shared-apache > /tmp/domain-idempotent.log 2>&1
# Normal maintenance/reopening must cover both domains and never expose V1 again.
bash "$APP/tools/deploy/configure-production-runtime.sh" --maintenance --reload-shared-apache > /tmp/domain-maintenance.log 2>&1
for host in cofrac.geniuscontrole.com cofrac2.geniuscontrole.com; do
    code=$(curl --noproxy '*' -sS --resolve "$host:443:127.0.0.1" -o /dev/null -w '%{http_code}' "https://$host/login")
    test "$code" = 503
done
bash "$APP/tools/deploy/configure-production-runtime.sh" --open --reload-shared-apache > /tmp/domain-reopen.log 2>&1
# Explicit rollback returns legacy to static maintenance, never to its original application.
bash "$SCRIPT" --rollback --reload-shared-apache > /tmp/domain-explicit-rollback.log 2>&1
cmp -s "$APP/.env" /tmp/domain-env.before
test ! -e "$STATE/PRODUCTION-DOMAINS.json"
bash "$SCRIPT" --activate --reload-shared-apache > /tmp/domain-open-again.log 2>&1
sha256sum --status -c /tmp/domain-certificates.sha256
sha256sum --status -c /tmp/runtime-original.sha256
# A subsequent release must preserve both domains and make updated translations readable.
git_app() { runuser -u cofrac2-deploy -- git -C "$APP" "$@"; }
BASE=$(git_app rev-parse HEAD)
printf '\n// Domain release fixture.\n' >> "$APP/lang/fr/passwords.php"
git_app add lang/fr/passwords.php
git_app -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture-domain-language-release >/dev/null
REV=$(git_app rev-parse HEAD)
BUNDLE=$(mktemp -d /tmp/domain-language-release-XXXXXXXX)
cp -a "$APP/public/build" "$BUNDLE/build"
find "$BUNDLE" -type d -exec chmod 0700 {} +
find "$BUNDLE" -type f -exec chmod 0600 {} +
SHA=$(php8.4 -r '
    require "/var/www/cofrac2/tools/deploy/lib/ProductionAssetUpdate.php";
    echo Cofrac2\Deploy\ProductionAssetUpdate::seal($argv[1],$argv[2],$argv[3]);
' "$BUNDLE" "$REV" "$BASE")
bash "$APP/tools/deploy/configure-production-runtime.sh" --maintenance --reload-shared-apache > /tmp/domain-release-close.log 2>&1
bash "$APP/tools/deploy/update-production-release.sh" --apply "$BUNDLE" "$SHA" > /tmp/domain-release-update.log 2>&1
bash "$APP/tools/deploy/configure-production-runtime.sh" --open --reload-shared-apache > /tmp/domain-release-open.log 2>&1
runuser -u cofrac2-app -- test -r "$APP/lang/fr/passwords.php"
for host in cofrac.geniuscontrole.com cofrac2.geniuscontrole.com; do
    curl --noproxy '*' -fsS --resolve "$host:443:127.0.0.1" "https://$host/login" >/dev/null
done
sha256sum --status -c /tmp/runtime-original.sha256
printf 'OK : deux domaines, meme V2/DB, TLS, ACME, WebSocket, modes mail/SMS, reouverture et retours arriere verifies.\n'
