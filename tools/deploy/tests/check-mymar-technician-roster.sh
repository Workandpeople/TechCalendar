#!/usr/bin/env bash
# Synthetic data only, no external network or production connection.
set -Eeuo pipefail
[ "${MYMAR_ROSTER_TEST:-}" = 1 ] || exit 1
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-roster-test-XXXXXXXX)
DB= PHP=
cleanup() {
    code=$?
    if [ -n "$PHP" ]; then docker cp "$PHP:/fixtures" "$WORK/runtime-reports" >/dev/null 2>&1 || true; docker rm -fv "$PHP" >/dev/null; fi
    [ -z "$DB" ] || docker rm -fv "$DB" >/dev/null
    printf 'Tests roster : diagnostic local %s\n' "$WORK"
    exit "$code"
}
trap cleanup EXIT
php -r 'require $argv[1];echo Cofrac2\Deploy\Mymar\MymarTechnicianRoster::sql(str_repeat("a",64));' \
    "$ROOT/tools/deploy/mymar/roster/MymarTechnicianRoster.php" > "$WORK/roster.sql"
DB=$(docker run -d --network none -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46)
for _ in $(seq 1 90); do
    if [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/tests/mymar-audit-fixture.sql"
docker exec -i "$DB" mysql <<'SQL'
UPDATE MYMAR.roles SET name='Technicien Terrain' WHERE id=4;
ALTER TABLE cofrac2production.users ADD admin int NOT NULL DEFAULT 0, ADD latitude double DEFAULT 48.8, ADD longitude double DEFAULT 2.3;
CREATE TABLE cofrac2production.services (id bigint PRIMARY KEY,type varchar(30),name varchar(200),retired_at datetime NULL,average_duration_minutes int) ENGINE=InnoDB;
CREATE TABLE cofrac2production.service_user (user_id bigint,service_id bigint) ENGINE=InnoDB;
CREATE TABLE cofrac2production.department_user (user_id bigint,department_code varchar(3)) ENGINE=InnoDB;
INSERT INTO cofrac2production.services VALUES (10,'MAR','VISITE APRES TRAVAUX',NULL,60);
INSERT INTO cofrac2production.service_user VALUES (101,10);
INSERT INTO cofrac2production.department_user VALUES (101,'59');
SQL
checksums() { docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.roles,MYMAR.model_has_roles,cofrac2production.users,cofrac2production.services,cofrac2production.service_user,cofrac2production.department_user;'; }
checksums > "$WORK/before"
docker exec -i "$DB" mysql --user=audit_reader --default-character-set=utf8mb4 --batch --raw --skip-column-names < "$WORK/roster.sql" > "$WORK/roster.jsonl"
checksums > "$WORK/after"
cmp "$WORK/before" "$WORK/after"
php -r '
require $argv[1];$rows=array_map(fn($l)=>json_decode($l,true,32,JSON_THROW_ON_ERROR),file($argv[2],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$snapshot=Cofrac2\Deploy\Mymar\MymarTechnicianRoster::snapshot($rows,new DateTimeImmutable);
if(count($snapshot["sources"])!==4||count($snapshot["targets"])!==3)exit(1);
if(str_contains(file_get_contents($argv[2]),"@example.test")||str_contains(file_get_contents($argv[2]),"CANARY"))exit(1);
' "$ROOT/tools/deploy/mymar/roster/MymarTechnicianRoster.php" "$WORK/roster.jsonl"
PHP=$(docker run -d --hostname ns365601 --network none --mount "type=bind,source=$ROOT,target=/workspace,readonly" cofrac2-runtime-check:local sleep 300)
docker cp "$WORK/roster.jsonl" "$PHP:/tmp/roster.jsonl"
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
if ! id ubuntu >/dev/null 2>&1; then useradd -M ubuntu; fi
if [ ! -x /usr/bin/php8.4 ]; then ln -s "$(command -v php)" /usr/bin/php8.4; fi
mkdir -m 700 -p /package /fixtures /var/lib/cofrac2-production /etc/cofrac2-mymar-actions
mv /tmp/roster.jsonl /fixtures/roster.jsonl
cp /workspace/tools/deploy/mymar/roster/{MymarTechnicianRoster.php,collect.php,collect.sh,fetch-roster.sh} /package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /workspace/tools/deploy/mymar/runtime/MymarRuntimeState.php /package/
printf '{"bindings":[{"source_technician_id":3,"source_name":"Technicien Trois","cofrac_user_id":102,"cofrac_name":"Tech Deux"}]}' > /package/confirmed-identities.json
printf '{"format":"cofrac.mymar-actions-client","version":1,"bindings":[{"source_technician_id":1,"cofrac_user_id":101}]}' > /etc/cofrac2-mymar-actions/actions.json
chmod 640 /etc/cofrac2-mymar-actions/actions.json
php <<'PHPFIXTURE'
<?php
foreach (['/home/ubuntu/www/MYMAR'=>'MYMAR','/var/www/cofrac2'=>'cofrac2production'] as $root=>$database) {
    mkdir($root.'/bootstrap/cache',0700,true);
    file_put_contents($root.'/.env',"DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=$database\nDB_PASSWORD=CANARY_SECRET\n");
    file_put_contents($root.'/artisan','<?php file_put_contents("/fixtures/FORBIDDEN", "executed");');
}
PHPFIXTURE
cat > /usr/bin/mysql <<'MYSQL'
#!/usr/bin/env bash
set -eu
cat > /fixtures/query.sql
grep -q 'START TRANSACTION READ ONLY;' /fixtures/query.sql
cat /fixtures/roster.jsonl
MYSQL
chmod 700 /usr/bin/mysql
for tool in systemctl service curl wget mysqldump composer; do
    printf '#!/bin/sh\ntouch /fixtures/FORBIDDEN\nexit 99\n' > "/usr/bin/$tool"
    chmod 700 "/usr/bin/$tool"
done
find /home/ubuntu/www/MYMAR /var/www/cofrac2 /etc/cofrac2-mymar-actions -type f -exec sha256sum {} + | sort > /fixtures/before
cd /package
sha256sum * > SHA256SUMS
bash ./collect.sh > /fixtures/success.log 2>&1
REPORT=$(sed -n 's/^REPORT_DIR=//p' /fixtures/success.log)
(cd "$REPORT" && sha256sum -c SHA256SUMS)
find /home/ubuntu/www/MYMAR /var/www/cofrac2 /etc/cofrac2-mymar-actions -type f -exec sha256sum {} + | sort > /fixtures/after
cmp /fixtures/before /fixtures/after
test ! -e /fixtures/FORBIDDEN
! grep -Eq 'CANARY|@example.test|email_key' "$REPORT/report.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if($r["summary"]["proposed_links"]!==2||$r["configuration_writes"]||$r["account_writes"]||$r["can_affect_planning"])exit(1);' "$REPORT/report.json"
# An incomplete snapshot must not publish a success report.
sed -i '/"section": "complete"/d' /fixtures/roster.jsonl
if bash ./collect.sh > /fixtures/failure.log 2>&1; then exit 1; fi
! grep -q '^REPORT_DIR=' /fixtures/failure.log
SH
echo 'Roster global : MySQL SELECT-only et collecteur isole OK, comptes/configurations/applications inchanges.'
