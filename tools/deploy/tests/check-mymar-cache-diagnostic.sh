#!/usr/bin/env bash
set -Eeuo pipefail
[ "${MYMAR_AUDIT_TEST:-}" = 1 ] || { echo 'MYMAR_AUDIT_TEST=1 requis (conteneur jetable uniquement).' >&2; exit 1; }
BASE=$(cd "$(dirname "$0")/.." && pwd -P)
CID=
cleanup() { if [ -n "$CID" ]; then docker rm -f -v "$CID" >/dev/null; fi; }
trap cleanup EXIT
CID=$(docker run -d --hostname ns365601 --network none --mount "type=bind,source=$BASE/mymar,target=/audit,readonly" php:8.5-fpm-bookworm sleep 300)
docker exec -i "$CID" sh <<'SH'
set -eu
useradd -M ubuntu
ln -s /usr/local/bin/php /usr/bin/php8.4
mkdir -m 700 -p /package /fixtures /var/lib/cofrac2-production /home/ubuntu/www/MYMAR/bootstrap/cache
cp /audit/MymarReadOnlyAudit.php /package/
cp /audit/cache-diagnostic/* /package/
chmod 600 /package/*
cd /package
sha256sum MymarReadOnlyAudit.php diagnose.php collect.sh fetch-audit.sh README.txt > SHA256SUMS
# Any accidental SQL/HTTP call fails the test. The runner also has no network.
for command in mysql curl wget; do
    printf '#!/bin/sh\necho forbidden > /fixtures/forbidden-call\nexit 99\n' > "/usr/bin/$command"
    chmod 700 "/usr/bin/$command"
done
cat > /home/ubuntu/www/MYMAR/bootstrap/cache/config.php <<'PHP'
<?php return [
  'password' => 'DO_NOT_EXPORT_PASSWORD',
  'object' => file_put_contents('/fixtures/APPLICATION_EXECUTED', 'DO_NOT_EXPORT_TOKEN'),
];
PHP
printf 'DO_NOT_READ_ENV' > /home/ubuntu/www/MYMAR/.env
find /home/ubuntu/www/MYMAR -type f -exec sha256sum {} + | sort > /fixtures/before
bash ./collect.sh > /fixtures/runner.log
find /home/ubuntu/www/MYMAR -type f -exec sha256sum {} + | sort > /fixtures/after
cmp /fixtures/before /fixtures/after
test ! -e /fixtures/forbidden-call
test ! -e /fixtures/APPLICATION_EXECUTED
SH
docker exec -i "$CID" php <<'PHP'
<?php
$log = file_get_contents('/fixtures/runner.log');
if (!preg_match('~^REPORT_DIR=(/home/ubuntu/cofrac2-mymar-cache-report-[A-Za-z0-9]+)$~m', $log, $match)) exit(1);
$report = $match[1];
if ((fileperms($report)&0777)!==0700) exit(1);
foreach (new DirectoryIterator($report) as $entry) {
    if ($entry->isDot()) continue;
    if (!in_array($entry->getFilename(), ['SHA256SUMS', 'cache-diagnostic.json'], true)
        || $entry->isLink() || ($entry->getPerms()&0777)!==0600
        || $entry->getOwner()!==posix_getpwnam('ubuntu')['uid']) exit(1);
}
$json = file_get_contents($report.'/cache-diagnostic.json');
if (preg_match('/DO_NOT_|file_put_contents|APPLICATION_EXECUTED|password/', $json)) exit(1);
$data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
if (!$data['source_stable'] || $data['diagnostic']['literal_decode'] || $data['sql_executed'] || $data['application_executed']
    || $data['diagnostic']['syntax']['line']!==3 || $data['diagnostic']['syntax']['token_type']!=='T_STRING') exit(1);
echo "Diagnostic de cache teste : fichier factice inchange, aucune execution PHP applicative/SQL/HTTP, aucun secret exporte, publication privee verifiee.\n";
PHP
