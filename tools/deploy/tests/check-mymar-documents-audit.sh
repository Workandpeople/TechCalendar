#!/usr/bin/env bash
# Disposable containers only; no ports, network or writable host mount.
set -Eeuo pipefail
[ "${MYMAR_DOCUMENT_AUDIT_TEST:-}" = 1 ] || { echo 'MYMAR_DOCUMENT_AUDIT_TEST=1 requis.' >&2; exit 1; }
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-documents-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="mymar-documents-db-$SUFFIX" PHP="mymar-documents-php-$SUFFIX" SOCKET="mymar-documents-socket-$SUFFIX"
cleanup() {
    result=$?
    docker cp "$PHP:/fixtures" "$WORK/reports" >/dev/null 2>&1 || true
    if [ "$result" != 0 ]; then tail -n 20 "$WORK/reports/"*.log 2>/dev/null || true; fi
    docker rm -fv "$PHP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$SOCKET" >/dev/null 2>&1 || true
    printf 'Diagnostic local : %s\n' "$WORK"
    exit "$result"
}
trap cleanup EXIT
docker image inspect cofrac2-runtime-check:local mysql:8.0.46 >/dev/null
docker volume create "$SOCKET" >/dev/null
docker run -d --name "$DB" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46 >/dev/null
for _ in $(seq 1 90); do
    if [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
[ "$(docker exec "$DB" mysql -NBe 'SELECT @@port')" = 3306 ]
docker exec -i "$DB" mysql --default-character-set=utf8mb4 < "$ROOT/tools/deploy/tests/mymar-audit-fixture.sql"
docker exec -i "$DB" mysql --default-character-set=utf8mb4 <<'SQL'
CREATE TABLE MYMAR.dossier_historiques (id bigint PRIMARY KEY,text varchar(255)) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossier_documents (id bigint PRIMARY KEY,dossier_id bigint,path varchar(255),nom varchar(255)) ENGINE=InnoDB;
INSERT INTO MYMAR.dossier_historiques VALUES (1,'PRIVATE_HISTORY_CANARY');
INSERT INTO MYMAR.dossier_documents VALUES (1,1,'PRIVATE_PATH_CANARY','PRIVATE_DOCUMENT_CANARY');
INSERT INTO MYMAR.status VALUES (25,'Dépôt',NULL),(26,'Ancien dépôt','2026-01-01 00:00:00');
SQL
snapshot() {
    docker exec "$DB" mysql -NBe "CHECKSUM TABLE MYMAR.users,MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_documents,MYMAR.dossier_historiques,MYMAR.status,cofrac2production.users; SHOW GRANTS FOR 'audit_reader'@'localhost';"
}
snapshot > "$WORK/database.before"
docker run -d --name "$PHP" --hostname ns365601 --network none \
    --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" \
    --entrypoint sleep cofrac2-runtime-check:local 300 >/dev/null
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
useradd -M ubuntu
test -e /usr/bin/php8.4 || ln -s /usr/bin/php /usr/bin/php8.4
mkdir -m 700 /package /fixtures
mkdir -p /etc/mysql /var/lib/cofrac2-production
chmod 700 /var/lib/cofrac2-production
printf '[client]\nuser=audit_reader\n' > /etc/mysql/debian.cnf
chmod 600 /etc/mysql/debian.cnf
cp /workspace/tools/deploy/mymar/documents-audit/* /package/
cp /workspace/tools/deploy/mymar/workflow/MymarWorkflowAudit.php /package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /package/
php <<'PHP'
<?php
$root='/home/ubuntu/www/MYMAR';
$files=['app/Http/Controllers/DossierController.php','app/Http/Controllers/ApiController.php',
 'app/Models/Dossier.php','app/Models/DossierDocument.php','app/Models/DossierHistorique.php',
 'app/Models/Status.php','config/filesystems.php','routes/web.php','routes/api.php'];
foreach($files as $file){
 @mkdir(dirname($root.'/'.$file),0700,true);
 file_put_contents($root.'/'.$file, <<<'SRC'
<?php
file_put_contents('/fixtures/FORBIDDEN', 'executed');
class Example {
 public function uploadDocument($request) {
  $password='PRIVATE_SECRET_CANARY';
  $request->file('file')->storeAs('documents',$filename,'public');
 }
 public function addDocument($request) { $doc->dossier_id=$request->id; $doc->save(); }
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
require '/package/MymarReadOnlyAudit.php';
require '/package/MymarWorkflowAudit.php';
require '/package/MymarDocumentAudit.php';
$pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR;charset=utf8mb4','audit_reader','');
$legacy=$pdo->query("SELECT JSON_OBJECT('section','status','id',id,'name',name,'deleted',deleted_at IS NOT NULL) FROM status WHERE id IN (25,26) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$legacy=array_map(fn($line)=>json_decode($line,true,16,JSON_THROW_ON_ERROR),$legacy);
assert($legacy[0]['deleted']===false && $legacy[1]['deleted']===true);
assert(Cofrac2\Deploy\MymarDocumentAudit::statuses($legacy)[1]['deleted']===true);
$reports=glob('/home/ubuntu/cofrac2-mymar-documents-report-*');
assert(count($reports)===1);
$summary=json_decode(file_get_contents($reports[0].'/summary.json'),true,32,JSON_THROW_ON_ERROR);
assert($summary['complete']===true && $summary['source_unchanged']===true);
foreach(['dossier_or_account_rows_read','document_contents_read','database_writes','application_bootstrapped','http_requests','services_changed','document_access_enabled','vt2_upload_enabled'] as $key) assert($summary[$key]===false);
$statuses=json_decode(file_get_contents($reports[0].'/status-catalogue.json'),true,32,JSON_THROW_ON_ERROR);
$byId=array_column($statuses,null,'id');
assert($byId[25]['name']==='Dépôt' && $byId[25]['deleted']===false && $byId[26]['deleted']===true);
foreach(glob($reports[0].'/*') as $file){
 $data=str_ends_with($file,'.gz')?gzdecode(file_get_contents($file)):file_get_contents($file);
 assert(!preg_match('/PRIVATE_|@example/',$data));
}
assert(!file_exists('/fixtures/FORBIDDEN'));
echo "MySQL JSON booleans reproduced; complete masked audit passed, source PHP not executed.\n";
PHP
find /home/ubuntu/www/MYMAR -type f -exec sha256sum {} + | sort > /fixtures/source.after
cmp /fixtures/source.before /fixtures/source.after
SH
snapshot > "$WORK/database.after"
cmp "$WORK/database.before" "$WORK/database.after"
docker exec "$DB" mysql -e 'ALTER TABLE MYMAR.dossier_documents ENGINE=MyISAM'
docker exec "$PHP" bash -c 'cd /package; if bash collect.sh > /fixtures/refused.log 2>&1; then exit 1; fi; grep -q AUDIT_FAILED_STAGE=metadata_validation /fixtures/refused.log; grep -q AUDIT_DIAGNOSTIC= /fixtures/refused.log; ! grep -q PRIVATE_ /fixtures/refused.log; test "$(find /home/ubuntu -maxdepth 1 -type d -name "cofrac2-mymar-documents-report-*" | wc -l)" = 1'
echo 'Collecte complete verifiee sur MySQL 8.0.46 ; source, donnees et droits inchanges ; arret diagnostique si schema non transactionnel.'
