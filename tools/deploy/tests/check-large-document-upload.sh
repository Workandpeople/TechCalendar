#!/usr/bin/env bash

# Standalone disposable container, no SQL and no production credentials or data.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ "${COFRAC2_UPLOAD_TEST:-}" = 1 ] && [ -d /templates ] \
    || { echo 'Fixture Docker isolee uniquement.' >&2; exit 1; }
id cofrac2-app >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin cofrac2-app
install -d -m 0755 /run/cofrac2 /etc/cofrac2 /var/www/cofrac2/public /var/lib/cofrac2-acme
install -d -o cofrac2-app -g cofrac2-app -m 0700 /var/www/cofrac2/storage
cp /templates/cofrac2-php-fpm.conf.example /etc/cofrac2/php-fpm.conf
CERT=/var/lib/cofrac2-letsencrypt/live/cofrac2.geniuscontrole.com
install -d -m 0700 "$CERT"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=cofrac2.geniuscontrole.com \
    -addext subjectAltName=DNS:cofrac2.geniuscontrole.com \
    -keyout "$CERT/privkey.pem" -out "$CERT/fullchain.pem" >/dev/null 2>&1
cat > /var/www/cofrac2/public/index.php <<'PHP'
<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['php'=>PHP_VERSION, 'upload'=>ini_get('upload_max_filesize'), 'post'=>ini_get('post_max_size'), 'memory'=>ini_get('memory_limit')]);
    exit;
}
$file = $_FILES['document'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['upload_error'=>$file['error'] ?? null]);
    exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$source = fopen($file['tmp_name'], 'rb');
$dest = fopen('/var/www/cofrac2/storage/fixture.pdf', 'wb');
$copied = stream_copy_to_stream($source, $dest);
fclose($source); fclose($dest);
unlink('/var/www/cofrac2/storage/fixture.pdf');
echo json_encode(['bytes'=>$file['size'], 'copied'=>$copied, 'mime'=>$mime, 'peak_memory'=>memory_get_peak_usage(true)]);
PHP
chmod 0644 /var/www/cofrac2/public/index.php
for module in proxy proxy_fcgi proxy_http proxy_wstunnel rewrite headers ssl; do a2enmod "$module" >/dev/null; done
find /etc/apache2/sites-enabled -maxdepth 1 -type l -delete
cp /templates/apache-production-app.conf.example /etc/apache2/sites-available/cofrac2.conf
ln -s ../sites-available/cofrac2.conf /etc/apache2/sites-enabled/cofrac2.conf
printf 'ServerName upload-fixture\n' > /etc/apache2/conf-enabled/upload-fixture.conf
php-fpm8.4 --test --fpm-config /etc/cofrac2/php-fpm.conf
apache2ctl configtest
php-fpm8.4 --nodaemonize --fpm-config /etc/cofrac2/php-fpm.conf > /tmp/fpm.log 2>&1 &
FPM=$!
trap 'kill "$FPM" 2>/dev/null || true; apache2ctl stop || true' EXIT
apache2ctl start
for attempt in {1..30}; do [ -S /run/cofrac2/php-fpm.sock ] && break; sleep 1; done
URL=https://cofrac2.geniuscontrole.com/index.php
request() {
    curl --noproxy '*' --silent --show-error --max-time 180 --resolve cofrac2.geniuscontrole.com:443:127.0.0.1 \
        --cacert "$CERT/fullchain.pem" "$@" "$URL"
}
request; echo
truncate -s 1073741824 /tmp/upload-fixture.pdf
printf '%%PDF-1.4\n' | dd of=/tmp/upload-fixture.pdf conv=notrunc status=none
code=$(request -F 'document=@/tmp/upload-fixture.pdf;type=application/pdf' -o /tmp/upload-result.json -w '%{http_code}')
cat /tmp/upload-result.json; echo
[ "$code" = 200 ]
grep -q '"bytes":1073741824,"copied":1073741824,"mime":"application' /tmp/upload-result.json
truncate -s 1073741825 /tmp/upload-fixture.pdf
code=$(request -F 'document=@/tmp/upload-fixture.pdf;type=application/pdf' -o /tmp/upload-overflow.json -w '%{http_code}')
cat /tmp/upload-overflow.json; echo
[ "$code" = 422 ]
grep -q '"upload_error":1' /tmp/upload-overflow.json
printf 'REAL_1GIB_HTTP_UPLOAD_OK_AND_OVERSIZE_REJECTED\n'
