#!/usr/bin/env bash

# Real Apache/TLS in a disposable container. Never run this fixture on the host.
set -Eeuo pipefail
umask 077
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ ! -f /.dockerenv ] \
    || [ "${COFRAC_LEGACY_WEB_TEST:-}" != 1 ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Refuse : fixture jetable ns365601 et COFRAC_LEGACY_WEB_TEST=1 requis.\n' >&2
    exit 1
fi
test ! -e /var/lib/cofrac2-production
cp -a /test-deploy /fixture-deploy
# Simulate the already published wording, then update it without reopening V1.
sed -i 's/Nouvel espace Cofrac/Nouvelle plateforme Cofrac 2/' /fixture-deploy/legacy-domain-maintenance.html
SCRIPT=/fixture-deploy/configure-legacy-domain-maintenance.sh
STATE=/var/lib/cofrac2-production
HISTORY="$STATE/legacy-domain-maintenance"
SOURCE=/home/ubuntu/www/coffrac_preprod/public
SITE=/etc/apache2/sites-available/cofrac.geniuscontrole.com.conf
SSL=/etc/apache2/sites-available/cofrac.geniuscontrole.com-le-ssl.conf
install -d -m 0700 "$STATE"
printf '{}\n' > "$STATE/INTERNAL-SITE-OPEN.json"
install -d -m 0755 /etc/apache2/{sites-available,sites-enabled,conf-enabled,mods-enabled} \
    "$SOURCE/.well-known/acme-challenge" /var/log/apache2 /fixture-v2 /etc/fixture-tls
find /etc/apache2/sites-enabled -type l -delete
printf 'ORIGINAL COFFRAC INTACT\n' > "$SOURCE/index.html"
printf 'PRIVATE ORIGINAL\n' > "$SOURCE/.env"
printf 'DO NOT EXECUTE PHP\n' > "$SOURCE/index.php"
printf 'existing-acme-token\n' > "$SOURCE/.well-known/acme-challenge/TOKEN_123"
printf '# Original .htaccess, never changed.\n' > "$SOURCE/.htaccess"
printf 'V2 LOGIN INTACT\n' > /fixture-v2/login
chmod -R a+rX /home/ubuntu /fixture-v2
find "$SOURCE" -type f -print0 | sort -z | xargs -0 sha256sum > /tmp/source.sha256
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=FixtureCA \
    -addext basicConstraints=critical,CA:TRUE -keyout /tmp/ca.key -out /tmp/ca.crt >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj /CN=cofrac.geniuscontrole.com \
    -keyout /etc/fixture-tls/privkey.pem -out /tmp/server.csr >/dev/null 2>&1
printf 'subjectAltName=DNS:cofrac.geniuscontrole.com,DNS:www.cofrac.geniuscontrole.com,DNS:cofrac2.geniuscontrole.com\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' > /tmp/server.ext
openssl x509 -req -in /tmp/server.csr -CA /tmp/ca.crt -CAkey /tmp/ca.key -CAcreateserial \
    -days 2 -extfile /tmp/server.ext -out /etc/fixture-tls/fullchain.pem >/dev/null 2>&1
export CURL_CA_BUNDLE=/tmp/ca.crt
cat > "$SITE" <<'HTTP'
<VirtualHost *:80>
    ServerName cofrac.geniuscontrole.com
    ServerAlias www.cofrac.geniuscontrole.com
    DocumentRoot /home/ubuntu/www/coffrac_preprod/public
    <Directory /home/ubuntu/www/coffrac_preprod/public>
        AllowOverride All
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteRule ^ https://cofrac.geniuscontrole.com%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
HTTP
cat > "$SSL" <<'HTTPS'
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName cofrac.geniuscontrole.com
    ServerAlias www.cofrac.geniuscontrole.com
    DocumentRoot /home/ubuntu/www/coffrac_preprod/public
    <Directory /home/ubuntu/www/coffrac_preprod/public>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/nonexistent-original-php.sock|fcgi://localhost"
    </FilesMatch>
    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCertificateFile /etc/fixture-tls/fullchain.pem
    SSLCertificateKeyFile /etc/fixture-tls/privkey.pem
</VirtualHost>
</IfModule>
HTTPS
cat > /etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf <<'V2'
<VirtualHost *:443>
    ServerName cofrac2.geniuscontrole.com
    DocumentRoot /fixture-v2
    SSLEngine on
    SSLCertificateFile /etc/fixture-tls/fullchain.pem
    SSLCertificateKeyFile /etc/fixture-tls/privkey.pem
    <Directory /fixture-v2>
        Require all granted
    </Directory>
</VirtualHost>
V2
for name in cofrac.geniuscontrole.com.conf cofrac.geniuscontrole.com-le-ssl.conf cofrac2.geniuscontrole.com.conf; do
    ln -s "../sites-available/$name" "/etc/apache2/sites-enabled/$name"
done
sha256sum "$SITE" "$SSL" > /tmp/original-vhosts.sha256
sha256sum /etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf /etc/fixture-tls/*.pem > /tmp/untouched.sha256
cat > /etc/apache2/conf-enabled/fixture.conf <<'CONFIG'
ServerName fixture.invalid
Alias /phpmyadmin /home/ubuntu/www/coffrac_preprod/public
CONFIG
a2enmod rewrite ssl headers proxy_fcgi >/dev/null 2>&1
ln -s "$(command -v php)" /usr/bin/php8.4
cat > /usr/bin/systemctl <<'SYSTEMCTL'
#!/usr/bin/env bash
set -eu
case "$*" in
    'is-active --quiet apache2.service') kill -0 "$(cat /var/run/apache2/apache2.pid)" ;;
    'show apache2.service --property=MainPID --value') cat /var/run/apache2/apache2.pid ;;
    'show apache2.service mysql.service '*'-p ExecMainStartTimestampMonotonic')
        printf 'Id=apache2.service\nMainPID=%s\nActiveState=active\n' "$(cat /var/run/apache2/apache2.pid)" ;;
    'reload apache2.service')
        printf 'graceful\n' >> /tmp/fixture-reloads
        if [ -f /tmp/fixture-fail-reload ]; then rm /tmp/fixture-fail-reload; exit 29; fi
        apache2ctl -k graceful ;;
    *) printf 'Refused systemctl in fixture: %s\n' "$*" >&2; exit 91 ;;
esac
SYSTEMCTL
chmod 0755 /usr/bin/systemctl
apache2ctl configtest
apache2ctl -k start
trap 'apache2ctl -k stop' EXIT
sleep 1
original() {
    sha256sum --status -c /tmp/original-vhosts.sha256
    sha256sum --status -c /tmp/source.sha256
    sha256sum --status -c /tmp/untouched.sha256
    test "$(curl --noproxy '*' -fsS --resolve cofrac.geniuscontrole.com:443:127.0.0.1 https://cofrac.geniuscontrole.com/)" = 'ORIGINAL COFFRAC INTACT'
}
original
if bash "$SCRIPT" --activate > /tmp/legacy-no-consent.log 2>&1; then exit 1; fi
original
if bash "$SCRIPT" --update-page --no-reload > /tmp/legacy-no-active-page.log 2>&1; then exit 1; fi
original
cp /fixture-deploy/legacy-domain-maintenance.php /tmp/helper.original
sed -i 's/TraceEnable Off/InvalidFixtureDirective On/' /fixture-deploy/legacy-domain-maintenance.php
if bash "$SCRIPT" --activate --reload-shared-apache > /tmp/legacy-invalid.log 2>&1; then exit 1; fi
test ! -e /tmp/fixture-reloads
original
cp /tmp/helper.original /fixture-deploy/legacy-domain-maintenance.php
touch /tmp/fixture-fail-reload
if bash "$SCRIPT" --activate --reload-shared-apache > /tmp/legacy-reload-failure.log 2>&1; then exit 1; fi
original
bash "$SCRIPT" --activate --reload-shared-apache
test -f "$HISTORY/active-run"
for host in cofrac.geniuscontrole.com www.cofrac.geniuscontrole.com; do
    for port in 80 443; do
        protocol=http; [ "$port" != 443 ] || protocol=https
        for path in / /index.html /dossier/voir/17573 /documents/secret.pdf /.env /phpmyadmin/index.php /index.php \
            '/index.php/extra' '/index.html?next=https://evil.invalid' '/.well-known/acme-challenge/evil.php'; do
            test "$(curl --noproxy '*' -sS --resolve "$host:$port:127.0.0.1" \
                -o /tmp/maintenance.body -w '%{http_code}' "$protocol://$host$path")" = 503
            grep -q 'data-cofrac-maintenance="true"' /tmp/maintenance.body
            ! grep -q 'ORIGINAL COFFRAC INTACT\|PRIVATE ORIGINAL\|DO NOT EXECUTE PHP' /tmp/maintenance.body
        done
        test "$(curl --noproxy '*' -fsS --resolve "$host:$port:127.0.0.1" \
            "$protocol://$host/.well-known/acme-challenge/TOKEN_123")" = existing-acme-token
    done
done
grep -q 'href="https://cofrac2.geniuscontrole.com/forgot-password"' /tmp/maintenance.body
sha256sum --status -c /tmp/source.sha256
sha256sum --status -c /tmp/untouched.sha256
reloads=$(wc -l < /tmp/fixture-reloads)
bash "$SCRIPT" --activate --reload-shared-apache
test "$(wc -l < /tmp/fixture-reloads)" = "$reloads"
# Updating the page must preserve the vhosts, original files, and rollback hashes.
sha256sum "$SITE" "$SSL" > /tmp/active-vhosts.sha256
cp /fixture-deploy/legacy-domain-maintenance.html /tmp/old-page-source
printf '<html>INVALID PAGE</html>\n' > /fixture-deploy/legacy-domain-maintenance.html
if bash "$SCRIPT" --update-page --no-reload > /tmp/legacy-invalid-page.log 2>&1; then exit 1; fi
cp /test-deploy/legacy-domain-maintenance.html /fixture-deploy/legacy-domain-maintenance.html
bash "$SCRIPT" --update-page --no-reload
test "$(wc -l < /tmp/fixture-reloads)" = "$reloads"
sha256sum --status -c /tmp/active-vhosts.sha256
active_run=$(cat "$HISTORY/active-run")
sha256sum --status -c "$active_run/active.sha256"
curl --noproxy '*' -sS --resolve cofrac.geniuscontrole.com:443:127.0.0.1 \
    https://cofrac.geniuscontrole.com/ > /tmp/updated-page.body
cmp -s /test-deploy/legacy-domain-maintenance.html /tmp/updated-page.body
! grep -q 'Cofrac 2' /tmp/updated-page.body
bash "$SCRIPT" --update-page --no-reload
test "$(wc -l < /tmp/fixture-reloads)" = "$reloads"
sha256sum --status -c /tmp/source.sha256
sha256sum --status -c /tmp/untouched.sha256
cp "$SSL" /tmp/active-ssl
printf '\n# Concurrent modification\n' >> "$SSL"
if bash "$SCRIPT" --update-page --no-reload > /tmp/legacy-refused-page.log 2>&1; then exit 1; fi
if bash "$HISTORY/rollback.sh" > /tmp/legacy-refused-rollback.log 2>&1; then exit 1; fi
test -f "$HISTORY/active-run"
cp /tmp/active-ssl "$SSL"
bash "$HISTORY/rollback.sh"
original
test ! -e "$HISTORY/active-run"
# An intentional activation after rollback reuses only the exact, root-owned static page.
bash "$SCRIPT" --activate --reload-shared-apache
bash "$HISTORY/rollback.sh"
original
printf 'OK : maintenance HTTP/HTTPS, mise a jour du texte sans reload, retour arriere conserve, chemins profonds, ACME, certificat et V2 inchanges, refus et echecs controles.\n'
