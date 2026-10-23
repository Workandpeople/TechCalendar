#!/usr/bin/env bash

# Run only in the disposable httpd fixture; systemctl below controls that fixture.
set -Eeuo pipefail
umask 077
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ ! -f /.dockerenv ] \
    || [ "${COFRAC2_WEB_TEST:-}" != 1 ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Refuse : conteneur httpd jetable root et COFRAC2_WEB_TEST=1 requis.\n' >&2
    exit 1
fi
test ! -e /var/lib/cofrac2-production
test ! -e /etc/apache2/sites-enabled
test -d /test-deploy
cp -a /test-deploy /fixture-deploy
SCRIPT=/fixture-deploy/configure-production-web.sh
STATE=/var/lib/cofrac2-production
SITE=/etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf
ENABLED=/etc/apache2/sites-enabled/cofrac2.geniuscontrole.com.conf
install -d -m 0700 "$STATE"
printf '{}\n' > "$STATE/SCHEMA-READY.json"
install -d -m 0755 /etc/apache2/{sites-available,sites-enabled,conf-enabled,mods-enabled} /var/log/apache2 /old-site
printf 'ORIGINAL SITE INTACT\n' > /old-site/index.html
printf 'PRIVATE ORIGINAL CONFIG\n' > /old-site/.env
chmod 0644 /old-site/index.html /old-site/.env
cat > /etc/apache2/sites-available/original.conf <<'ORIGINAL'
<VirtualHost *:80>
    ServerName cofrac.geniuscontrole.com
    DocumentRoot /old-site
    <Directory /old-site>
        Require all granted
    </Directory>
</VirtualHost>
ORIGINAL
ln -s ../sites-available/original.conf /etc/apache2/sites-enabled/000-original.conf
sha256sum /etc/apache2/sites-available/original.conf /old-site/index.html /old-site/.env > /tmp/original.sha256
cat > /usr/local/apache2/conf/httpd.conf <<'APACHE'
ServerRoot /usr/local/apache2
ServerName fixture.invalid
Listen 80
Listen 443
LoadModule mpm_event_module modules/mod_mpm_event.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule unixd_module modules/mod_unixd.so
LoadModule dir_module modules/mod_dir.so
LoadModule alias_module modules/mod_alias.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule ssl_module modules/mod_ssl.so
LoadModule log_config_module modules/mod_log_config.so
Define APACHE_LOG_DIR /var/log/apache2
User daemon
Group daemon
ErrorLog /var/log/apache2/main-error.log
PidFile /tmp/httpd-fixture.pid
DirectoryIndex index.html
Alias /phpmyadmin /old-site
IncludeOptional /etc/apache2/sites-enabled/*.conf
APACHE
cat > /usr/sbin/apache2ctl <<'CONTROL'
#!/usr/bin/env bash
case "$1" in
    configtest) exec /usr/local/apache2/bin/httpd -t ;;
    -M) exec /usr/local/apache2/bin/httpd -M ;;
    *) exit 90 ;;
esac
CONTROL
cat > /usr/bin/systemctl <<'SYSTEMCTL'
#!/usr/bin/env bash
set -eu
case "$*" in
    'is-active --quiet apache2.service') kill -0 "$(cat /tmp/httpd-fixture.pid)" ;;
    'is-active --quiet cofrac2-certificate-renewal.timer') test -f /tmp/fixture-timer-active ;;
    'show apache2.service --property=MainPID --value') cat /tmp/httpd-fixture.pid ;;
    'reload apache2.service')
        printf 'graceful\n' >> /tmp/apache-fixture-reloads
        /usr/local/apache2/bin/httpd -k graceful ;;
    'daemon-reload') : ;;
    'enable --now cofrac2-certificate-renewal.timer') touch /tmp/fixture-timer-active ;;
    'disable --now cofrac2-certificate-renewal.timer') rm -f /tmp/fixture-timer-active ;;
    *) exit 91 ;;
esac
SYSTEMCTL
chmod 0755 /usr/sbin/apache2ctl /usr/bin/systemctl
/usr/local/apache2/bin/httpd -k start
trap '/usr/local/apache2/bin/httpd -k stop' EXIT
sleep 1
original() {
    test "$(curl --noproxy '*' -fsS --resolve cofrac.geniuscontrole.com:80:127.0.0.1 http://cofrac.geniuscontrole.com/)" = 'ORIGINAL SITE INTACT'
    sha256sum -c /tmp/original.sha256
}
original

if bash "$SCRIPT" --enable-http-challenge > /tmp/web-no-consent.log 2>&1; then exit 1; fi
test ! -e "$SITE"
cp /fixture-deploy/apache-production-challenge.conf.example /tmp/http-model
printf '\nInvalidDirectiveForFixture On\n' >> /fixture-deploy/apache-production-challenge.conf.example
if bash "$SCRIPT" --enable-http-challenge --reload-shared-apache > /tmp/web-invalid.log 2>&1; then exit 1; fi
test ! -e "$SITE"
test ! -L "$ENABLED"
test ! -e /tmp/apache-fixture-reloads
cp /tmp/http-model /fixture-deploy/apache-production-challenge.conf.example
original

bash "$SCRIPT" --enable-http-challenge --reload-shared-apache
for directory in /var/lib/cofrac2-letsencrypt /var/lib/cofrac2-certbot-work /var/log/cofrac2-certbot; do
    test "$(stat -c %u:%a "$directory")" = 0:700
done
sha256sum "$SITE" > /tmp/pre-https.sha256
for path in / /index.php /.env /phpmyadmin /some/dossier; do
    test "$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' --resolve cofrac2.geniuscontrole.com:80:127.0.0.1 "http://cofrac2.geniuscontrole.com$path")" = 503
done
if bash "$SCRIPT" --enable-https-maintenance --reload-shared-apache > /tmp/web-no-cert.log 2>&1; then exit 1; fi
sha256sum -c /tmp/pre-https.sha256

CERT=/var/lib/cofrac2-letsencrypt/live/cofrac2.geniuscontrole.com
install -d -m 0700 "$CERT"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=FixtureCA \
    -addext basicConstraints=critical,CA:TRUE -keyout /tmp/ca.key -out /tmp/ca.crt >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj /CN=cofrac2.geniuscontrole.com \
    -keyout "$CERT/privkey.pem" -out /tmp/server.csr >/dev/null 2>&1
printf 'subjectAltName=DNS:cofrac2.geniuscontrole.com\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' > /tmp/server.ext
openssl x509 -req -in /tmp/server.csr -CA /tmp/ca.crt -CAkey /tmp/ca.key -CAcreateserial \
    -days 2 -extfile /tmp/server.ext -out "$CERT/fullchain.pem" >/dev/null 2>&1
export CURL_CA_BUNDLE=/tmp/ca.crt

# A failed HTTPS health check must restore the previous HTTP-only configuration.
if CURL_CA_BUNDLE=/nonexistent-ca bash "$SCRIPT" --enable-https-maintenance --reload-shared-apache > /tmp/web-bad-health.log 2>&1; then exit 1; fi
sha256sum -c /tmp/pre-https.sha256
original
bash "$SCRIPT" --enable-https-maintenance --reload-shared-apache
test "$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' --resolve cofrac2.geniuscontrole.com:80:127.0.0.1 http://cofrac2.geniuscontrole.com/)" = 302
for path in / /index.php /.env /phpmyadmin /some/dossier; do
    test "$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' --resolve cofrac2.geniuscontrole.com:443:127.0.0.1 "https://cofrac2.geniuscontrole.com$path")" = 503
done
sha256sum -c "$STATE/apache-config.sha256"
original

# Check installer rollback and renewal isolation without contacting any ACME server.
install -d -m 0700 /var/lib/cofrac2-letsencrypt/renewal
printf 'fixture only\n' > /var/lib/cofrac2-letsencrypt/renewal/cofrac2.geniuscontrole.com.conf
cat > /usr/bin/certbot <<'CERTBOT'
#!/usr/bin/env bash
printf '%s\n' "$@" > /tmp/fixture-certbot-arguments
CERTBOT
cat > /usr/bin/systemd-analyze <<'VERIFY'
#!/usr/bin/env bash
if [ "${COFRAC2_FORCE_UNIT_FAILURE:-}" = 1 ]; then exit 23; fi
test "$1" = verify
test -x /usr/local/sbin/cofrac2-renew-certificate
VERIFY
chmod 0755 /usr/bin/certbot /usr/bin/systemd-analyze
if COFRAC2_FORCE_UNIT_FAILURE=1 bash /fixture-deploy/enable-production-certificate-renewal.sh --enable-dedicated-renewal > /tmp/fixture-unit-failure.log 2>&1; then exit 1; fi
test ! -e /usr/local/sbin/cofrac2-renew-certificate
test ! -e /etc/systemd/system/cofrac2-certificate-renewal.timer
reloads=$(wc -l < /tmp/apache-fixture-reloads)
bash /fixture-deploy/enable-production-certificate-renewal.sh --enable-dedicated-renewal
test -f /tmp/fixture-timer-active
test "$(wc -l < /tmp/apache-fixture-reloads)" = "$reloads"
sha256sum /usr/local/sbin/cofrac2-renew-certificate /etc/systemd/system/cofrac2-certificate-renewal.{service,timer} > /tmp/fixture-renewal.sha256
if bash /fixture-deploy/enable-production-certificate-renewal.sh --enable-dedicated-renewal > /tmp/fixture-unit-repeat.log 2>&1; then exit 1; fi
sha256sum -c /tmp/fixture-renewal.sha256
/usr/local/sbin/cofrac2-renew-certificate
grep -qx -- '--no-directory-hooks' /tmp/fixture-certbot-arguments
grep -qx /var/lib/cofrac2-letsencrypt /tmp/fixture-certbot-arguments
! grep -q -- '--apache\|--standalone\|/etc/letsencrypt' /tmp/fixture-certbot-arguments
original
printf 'OK : vrais vhosts HTTP/HTTPS, challenge ACME, certificat verifie, acces ferme, retours arriere testes, renouvellement isole, site original inchange.\n'
