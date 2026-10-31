#!/usr/bin/env bash
# Disposable MySQL/PHP containers, no network access or writable host mount.
set -Eeuo pipefail
[ "${MYMAR_WORKFLOW_AUDIT_TEST:-}" = 1 ] || { echo 'MYMAR_WORKFLOW_AUDIT_TEST=1 requis.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-workflow-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="mymar-workflow-db-$SUFFIX" PHP="mymar-workflow-php-$SUFFIX" SOCKET="mymar-workflow-socket-$SUFFIX"
cleanup() {
    result=$?
    docker cp "$PHP:/fixtures" "$WORK/reports" >/dev/null 2>&1 || true
    if [ "$result" != 0 ]; then tail -n 15 "$WORK/reports/"*.log 2>/dev/null || true; fi
    docker rm -fv "$PHP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$SOCKET" >/dev/null 2>&1 || true
    printf 'Diagnostic local : %s\n' "$WORK"
    exit "$result"
}
trap cleanup EXIT
docker image inspect cofrac2-runtime-check:local >/dev/null
docker volume create "$SOCKET" >/dev/null
docker run -d --name "$DB" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46 >/dev/null
for _ in $(seq 1 90); do
    if [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
[ "$(docker exec "$DB" mysql -NBe 'SELECT @@port')" = 3306 ]
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/tests/mymar-audit-fixture.sql"
docker exec -i "$DB" mysql <<'SQL'
CREATE TABLE MYMAR.dossier_historiques (id bigint PRIMARY KEY, dossier_id bigint NOT NULL, author_id bigint NOT NULL, status_id bigint NULL, text varchar(255) NOT NULL) ENGINE=InnoDB;
CREATE TABLE MYMAR.clients (id bigint PRIMARY KEY, name varchar(255)) ENGINE=InnoDB;
INSERT INTO MYMAR.clients VALUES (1,'PRIVATE_BENEFICIARY_CANARY');
INSERT INTO MYMAR.dossier_historiques VALUES (1,1,1,3,'PRIVATE_HISTORY_CANARY');
SQL
docker run -d --name "$PHP" --hostname ns365601 --network none \
    --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" \
    --entrypoint sleep cofrac2-runtime-check:local 300 >/dev/null
snapshot() {
    docker exec "$DB" mysql -NBe "CHECKSUM TABLE MYMAR.users,MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.clients,MYMAR.dossier_historiques,MYMAR.status,cofrac2production.users; SHOW GRANTS FOR 'audit_reader'@'localhost';"
}
snapshot > "$WORK/database.before"
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
useradd -M ubuntu
test -e /usr/bin/php8.4 || ln -s /usr/bin/php /usr/bin/php8.4
mkdir -m 700 /package /fixtures
mkdir -p /etc/mysql /var/lib/cofrac2-production
chmod 700 /var/lib/cofrac2-production
printf '[client]\nuser=audit_reader\n' > /etc/mysql/debian.cnf
chmod 600 /etc/mysql/debian.cnf
cp /workspace/tools/deploy/mymar/workflow/* /package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /package/
php <<'PHP'
<?php
$root='/home/ubuntu/www/MYMAR';
$files=['routes/api.php','routes/web.php','app/Http/Controllers/ApiController.php',
 'app/Http/Controllers/DossierController.php','app/Models/Dossier.php',
 'app/Models/DossierRDV.php','app/Models/DossierHistorique.php'];
foreach($files as $file){
 @mkdir(dirname($root.'/'.$file),0700,true);
 file_put_contents($root.'/'.$file,<<<'SRC'
<?php
file_put_contents('/fixtures/FORBIDDEN', 'executed');
class Example {
 public function ajouterRDV($request) {
  $password='PRIVATE_SECRET_CANARY';
  $dossier->status_id=3;
  RDV::where('dossier_id',$request->id)->update(['is_old'=>1]);
  $dossier->save();
 }
}
SRC);
}
file_put_contents($root.'/.env','PRIVATE_ENV_CANARY');
PHP
find /home/ubuntu/www/MYMAR -type f -exec sha256sum {} + | sort > /fixtures/source.before
cd /package
chmod 600 *
sha256sum * > SHA256SUMS
bash collect.sh > /fixtures/success.log 2>&1
php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$reports=glob('/home/ubuntu/cofrac2-mymar-workflow-report-*');
assert(count($reports)===1);
$summary=json_decode(file_get_contents($reports[0].'/summary.json'),true,32,JSON_THROW_ON_ERROR);
assert($summary['complete']===true && $summary['source_unchanged']===true && $summary['metadata_complete']===true);
foreach(['business_rows_read','database_writes','application_bootstrapped','http_requests','services_changed','booking_enabled'] as $key) assert($summary[$key]===false);
foreach(glob($reports[0].'/*') as $file){
 $data=str_ends_with($file,'.gz')?gzdecode(file_get_contents($file)):file_get_contents($file);
 assert(!preg_match('/PRIVATE_|@example|password.*CANARY/',$data));
}
$source=json_decode(gzdecode(file_get_contents($reports[0].'/source-facts.json.gz')),true,64,JSON_THROW_ON_ERROR);
assert($source['files']['app/Http/Controllers/ApiController.php']['business_facts'][0]['literal']===3);
assert(!file_exists('/fixtures/FORBIDDEN'));
echo "Collecte complete : code masque, constantes metier conservees, aucune donnee personnelle, aucun PHP source execute.\n";
PHP
find /home/ubuntu/www/MYMAR -type f -exec sha256sum {} + | sort > /fixtures/source.after
cmp /fixtures/source.before /fixtures/source.after
SH
snapshot > "$WORK/database.after"
cmp "$WORK/database.before" "$WORK/database.after"
# Metadata must reject a non-transactional table instead of approving a partial audit.
docker exec "$DB" mysql -e 'ALTER TABLE MYMAR.dossier_historiques ENGINE=MyISAM'
docker exec "$PHP" bash -c 'cd /package; if bash collect.sh > /fixtures/refused.log 2>&1; then exit 1; fi; test "$(find /home/ubuntu -maxdepth 1 -type d -name "cofrac2-mymar-workflow-report-*" | wc -l)" = 1'
echo 'MySQL 8.0.46 : collecte sans ecriture, comptes/grants/sources inchanges ; schema non transactionnel refuse.'
