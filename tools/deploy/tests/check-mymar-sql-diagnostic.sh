#!/usr/bin/env bash
set -Eeuo pipefail
[ "${MYMAR_AUDIT_TEST:-}" = 1 ] && [ "$#" = 1 ] || { echo 'MYMAR_AUDIT_TEST=1 et fixture metadata.out requis.' >&2; exit 1; }
WORK=$(cd "$1" && pwd -P)
case "$WORK" in /tmp/mymar-audit-mysql-test-*|/private/tmp/mymar-audit-mysql-test-*) ;; *) exit 1 ;; esac
BASE=$(cd "$(dirname "$0")/.." && pwd -P)
CID=
cleanup() { if [ -n "$CID" ]; then docker rm -f -v "$CID" >/dev/null; fi; }
trap cleanup EXIT
CID=$(docker run -d --hostname ns365601 --network none --mount "type=bind,source=$BASE/mymar/sql-diagnostic,target=/audit,readonly" php:8.5-fpm-bookworm sleep 300)
docker exec -i "$CID" sh <<'SH'
set -eu
useradd -M ubuntu
install -d -o ubuntu -g ubuntu -m 0750 /home/ubuntu
ln -s /usr/local/bin/php /usr/bin/php8.4
mkdir -m 700 -p /package /fixtures /var/lib/cofrac2-production /var/lib/cofrac2-production/mymar-readonly-Fixture
cp /audit/* /package/
chmod 600 /package/*
for command in mysql curl wget systemctl; do
    printf '#!/bin/sh\necho forbidden > /fixtures/forbidden-call\nexit 99\n' > "/usr/bin/$command"
    chmod 700 "/usr/bin/$command"
done
SH
docker cp "$WORK/metadata.out" "$CID:/var/lib/cofrac2-production/mymar-readonly-Fixture/metadata.jsonl"
docker exec -i "$CID" php <<'PHP'
<?php
$run='/var/lib/cofrac2-production/mymar-readonly-Fixture';
file_put_contents($run.'/source-facts.json.gz',gzencode('REFERENCE AUDIT'));
file_put_contents($run.'/summary.json','{}');
file_put_contents($run.'/metadata.sql','THIS SQL MUST NEVER EXECUTE');
file_put_contents($run.'/metadata-validation.log','PRIVATE VALIDATION');
file_put_contents($run.'/database-private.stderr',"ERROR 1267 (HY000) at line 22: PRIVATE_VALUES private@example.test\n");
file_put_contents('/package/TARGET_SOURCE_SHA256',hash_file('sha256',$run.'/source-facts.json.gz')."\n");
PHP
docker exec -i "$CID" sh <<'SH'
set -eu
cd /package
sha256sum SqlAuditDiagnostic.php diagnose.php collect.sh fetch-audit.sh TARGET_SOURCE_SHA256 README.txt > SHA256SUMS
find /var/lib/cofrac2-production/mymar-readonly-Fixture -type f -exec sha256sum {} + | sort > /fixtures/before
bash ./collect.sh > /fixtures/runner.log
find /var/lib/cofrac2-production/mymar-readonly-Fixture -type f -exec sha256sum {} + | sort > /fixtures/after
cmp /fixtures/before /fixtures/after
test ! -e /fixtures/forbidden-call
SH
docker exec -i "$CID" php <<'PHP'
<?php
if(!preg_match('~^REPORT_DIR=(/home/ubuntu/cofrac2-mymar-sql-report-[A-Za-z0-9]+)$~m',file_get_contents('/fixtures/runner.log'),$match)) exit(1);
$dir=$match[1];
if((fileperms($dir)&0777)!==0700) exit(1);
foreach(new DirectoryIterator($dir) as $file) {
    if($file->isDot()) continue;
    if(!in_array($file->getFilename(),['SHA256SUMS','sql-diagnostic.json'],true) || $file->isLink()
        || ($file->getPerms()&0777)!==0600 || $file->getOwner()!==posix_getpwnam('ubuntu')['uid']) exit(1);
}
$json=file_get_contents($dir.'/sql-diagnostic.json');
$data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
if($data['sql_executed'] || $data['application_executed'] || preg_match('/PRIVATE|@example|THIS SQL/',$json)
    || $data['errors']['database']['codes'][0]['mysql_code']!==1267 || !$data['schema_checks']['MYMAR.users']['table_present']) exit(1);
echo "Inspection de l'audit existant testee : aucune requete SQL/API, sources inchangees, aucune erreur brute ou donnee personnelle exportee.\n";
PHP
