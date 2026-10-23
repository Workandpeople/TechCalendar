#!/usr/bin/env bash

# Only inside a disposable PHP/MySQL fixture, with no real application mounts.
set -Eeuo pipefail
umask 077
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ ! -f /.dockerenv ] \
    || [ "${COFRAC2_COLLECTION_TEST:-}" != 1 ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Refuse : conteneur Docker jetable root et COFRAC2_COLLECTION_TEST=1 requis.\n' >&2
    exit 1
fi
test ! -e /var/lib/cofrac2-production
test ! -e /home/ubuntu/www/coffrac_preprod
SCRIPT=$(cd -- "$(dirname -- "$0")/.." && pwd)/collect-production-coffrac.sh
STATE=/var/lib/cofrac2-production
install -d -m 0700 /etc/mysql "$STATE" /home/ubuntu/www/coffrac_preprod/public /etc/apache2/sites-available
cat > /etc/mysql/debian.cnf <<'ROOT'
[client]
user=root
password="FixtureRootA1!"
socket=/var/run/mysqld/mysqld.sock
ROOT
for attempt in {1..60}; do
    if mysql --defaults-file=/etc/mysql/debian.cnf --protocol=socket -NBe 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 1
done
mysql --defaults-file=/etc/mysql/debian.cnf --protocol=socket <<'SQL'
CREATE DATABASE fiche_crm;
CREATE DATABASE cofrac2production;
CREATE TABLE cofrac2production.sentinel (id int primary key, label varchar(64));
INSERT INTO cofrac2production.sentinel VALUES (42, 'V2 MUST STAY INTACT');
CREATE USER 'debian-sys-maint'@'localhost' IDENTIFIED BY 'FixtureMaintenanceA1!';
GRANT ALL PRIVILEGES ON *.* TO 'debian-sys-maint'@'localhost' WITH GRANT OPTION;
SQL
cat > /etc/mysql/debian.cnf <<'CLIENT'
[client]
user=debian-sys-maint
password="FixtureMaintenanceA1!"
socket=/var/run/mysqld/mysqld.sock
CLIENT
chmod 0600 /etc/mysql/debian.cnf
sql() { mysql --defaults-file=/etc/mysql/debian.cnf --protocol=socket --batch --skip-column-names --execute="$1"; }
for table in users clients dossiers dossier_fiches dossier_document dossier_fiche_rdv status fiche_type; do
    sql "CREATE TABLE fiche_crm.$table (id int primary key, label varchar(64)) ENGINE=InnoDB; INSERT INTO fiche_crm.$table VALUES (1, 'PRIVATE SOURCE FIXTURE');"
done
sql "CREATE VIEW fiche_crm.source_view AS SELECT id FROM fiche_crm.dossiers;
CREATE TRIGGER fiche_crm.source_trigger BEFORE UPDATE ON fiche_crm.users FOR EACH ROW SET NEW.label='TRIGGER MUST NOT RUN';
CREATE PROCEDURE fiche_crm.source_procedure() SELECT 1;
CREATE EVENT fiche_crm.source_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DO SELECT 1;"
printf '{"business_data_empty":true}\n' > "$STATE/SCHEMA-READY.json"
printf 'ORIGINAL APP\n' > /home/ubuntu/www/coffrac_preprod/public/index.php
printf 'ORIGINAL PRIVATE CONFIG\n' > /home/ubuntu/www/coffrac_preprod/.env
printf 'ORIGINAL VHOST\n' > /etc/apache2/sites-available/cofrac.conf
sha256sum /home/ubuntu/www/coffrac_preprod/public/index.php /home/ubuntu/www/coffrac_preprod/.env /etc/apache2/sites-available/cofrac.conf /etc/mysql/debian.cnf > /tmp/cofrac2-original.sha256

sql 'CREATE TABLE fiche_crm.nontransactional (id int) ENGINE=MEMORY;'
if bash "$SCRIPT" --export-current-coffrac > /tmp/cofrac2-nontransactional.log 2>&1; then exit 1; fi
grep -q 'tables non InnoDB' /tmp/cofrac2-nontransactional.log
if bash "$SCRIPT" --export-current-coffrac --allow-live-myisam-clients > /tmp/cofrac2-unexpected-engine.log 2>&1; then exit 1; fi
grep -q 'tables non InnoDB inattendues' /tmp/cofrac2-unexpected-engine.log
test -z "$(find "$STATE" -maxdepth 1 -type d -name 'coffrac-source-*' -print -quit)"
sql 'DROP TABLE fiche_crm.nontransactional;'

exec 8>"$STATE/source-export.lock"
flock -n 8
if bash "$SCRIPT" --export-current-coffrac > /tmp/cofrac2-concurrent-export.log 2>&1; then exit 1; fi
grep -q 'un export Coffrac est deja en cours' /tmp/cofrac2-concurrent-export.log
exec 8>&-

sql "SET GLOBAL log_output='TABLE'; SET GLOBAL general_log=ON;"
bash "$SCRIPT" --export-current-coffrac > /tmp/cofrac2-collection-success.log
sql 'SET GLOBAL general_log=OFF;'
test "$(sql "SELECT COUNT(*) FROM mysql.general_log WHERE command_type='Query' AND argument REGEXP '^[[:space:]]*(LOCK[[:space:]]+TABLES|FLUSH|INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|REPLACE|TRUNCATE)';")" = 0
DIRECTORY=$(find "$STATE" -maxdepth 1 -type d -name 'coffrac-source-*')
test -n "$DIRECTORY"
test "$(stat -c %a "$DIRECTORY")" = 700
test "$(stat -c %a "$DIRECTORY/coffrac.sql.gz")" = 600
test ! -e "$DIRECTORY/coffrac.sql.gz.partial"
(cd "$DIRECTORY" && sha256sum -c SHA256SUMS)
gzip -t "$DIRECTORY/coffrac.sql.gz"
grep -qx 'data_consistency=innodb_transaction_snapshot' "$DIRECTORY/collection.txt"
grep -qx 'non_transactional_tables=none' "$DIRECTORY/collection.txt"
grep -qx 'final_cutover_ready=no' "$DIRECTORY/collection.txt"
gzip -dc "$DIRECTORY/coffrac.sql.gz" > /tmp/cofrac2-fixture.sql
grep -q 'CREATE TABLE `dossiers`' /tmp/cofrac2-fixture.sql
grep -q 'PRIVATE SOURCE FIXTURE' /tmp/cofrac2-fixture.sql
grep -q 'source_procedure' /tmp/cofrac2-fixture.sql
grep -q 'source_event' /tmp/cofrac2-fixture.sql
grep -q 'source_trigger' /tmp/cofrac2-fixture.sql
! grep -q 'V2 MUST STAY INTACT' /tmp/cofrac2-fixture.sql
! grep -q 'LOCK TABLES' /tmp/cofrac2-fixture.sql
! grep -q 'PRIVATE SOURCE FIXTURE' /tmp/cofrac2-collection-success.log
test "$(sql 'SELECT label FROM fiche_crm.users WHERE id=1')" = 'PRIVATE SOURCE FIXTURE'
test "$(sql 'SELECT label FROM cofrac2production.sentinel WHERE id=42')" = 'V2 MUST STAY INTACT'
sha256sum -c /tmp/cofrac2-original.sha256

# Accept only the audited MyISAM table, with an explicit non-atomic preparation flag.
sql 'ALTER TABLE fiche_crm.clients ENGINE=MEMORY;'
if bash "$SCRIPT" --export-current-coffrac --allow-live-myisam-clients > /tmp/cofrac2-wrong-clients-engine.log 2>&1; then exit 1; fi
grep -q 'tables non InnoDB inattendues' /tmp/cofrac2-wrong-clients-engine.log
sql 'ALTER TABLE fiche_crm.clients ENGINE=MyISAM;'
if bash "$SCRIPT" --export-current-coffrac > /tmp/cofrac2-myisam-not-acknowledged.log 2>&1; then exit 1; fi
grep -q -- '--allow-live-myisam-clients requis' /tmp/cofrac2-myisam-not-acknowledged.log
test "$(find "$STATE" -name SHA256SUMS | wc -l)" -eq 1
sql 'TRUNCATE TABLE mysql.general_log; SET GLOBAL general_log=ON;'
bash "$SCRIPT" --export-current-coffrac --allow-live-myisam-clients > /tmp/cofrac2-mixed-collection-success.log
sql 'SET GLOBAL general_log=OFF;'
test "$(sql "SELECT COUNT(*) FROM mysql.general_log WHERE command_type='Query' AND argument REGEXP '^[[:space:]]*(LOCK[[:space:]]+TABLES|FLUSH|INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|REPLACE|TRUNCATE)';")" = 0
grep -q 'coherence globale non garantie' /tmp/cofrac2-mixed-collection-success.log
MIXED_DIRECTORY=$(find "$STATE" -name collection.txt -exec grep -l 'data_consistency=mixed_engines_not_atomic' {} +)
test -n "$MIXED_DIRECTORY"
MIXED_DIRECTORY=$(dirname -- "$MIXED_DIRECTORY")
test "$(stat -c %a "$MIXED_DIRECTORY")" = 700
test "$(stat -c %a "$MIXED_DIRECTORY/coffrac.sql.gz")" = 600
(cd "$MIXED_DIRECTORY" && sha256sum -c SHA256SUMS)
gzip -dc "$MIXED_DIRECTORY/coffrac.sql.gz" > /tmp/cofrac2-mixed-fixture.sql
grep -q 'ENGINE=MyISAM' /tmp/cofrac2-mixed-fixture.sql
grep -q 'CREATE TABLE `clients`' /tmp/cofrac2-mixed-fixture.sql
! grep -q 'LOCK TABLES' /tmp/cofrac2-mixed-fixture.sql
grep -qx 'non_transactional_tables=clients' "$MIXED_DIRECTORY/collection.txt"
grep -qx 'collection_type=online_preparation_snapshot' "$MIXED_DIRECTORY/collection.txt"
grep -qx 'final_cutover_ready=no' "$MIXED_DIRECTORY/collection.txt"
test "$(sql 'SELECT label FROM fiche_crm.clients WHERE id=1')" = 'PRIVATE SOURCE FIXTURE'
test "$(sql "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='fiche_crm' AND TABLE_NAME='clients';")" = MyISAM
sha256sum -c /tmp/cofrac2-original.sha256

# Force a client failure after checks, only within the disposable PHP container.
mv /usr/bin/mysqldump /usr/bin/mysqldump.fixture-real
printf '#!/bin/sh\nexit 23\n' > /usr/bin/mysqldump
chmod 0755 /usr/bin/mysqldump
if bash "$SCRIPT" --export-current-coffrac --allow-live-myisam-clients > /tmp/cofrac2-failed-export.log 2>&1; then exit 1; fi
mv /usr/bin/mysqldump.fixture-real /usr/bin/mysqldump
grep -q 'Export incomplet' /tmp/cofrac2-failed-export.log
test "$(find "$STATE" -name coffrac.sql.gz | wc -l)" -eq 2
test "$(find "$STATE" -name coffrac.sql.gz.partial | wc -l)" -eq 1
test "$(find "$STATE" -name SHA256SUMS | wc -l)" -eq 2

# Changes to the table inventory invalidate the dump instead of publishing a checksum.
mv /usr/bin/mysqldump /usr/bin/mysqldump.fixture-real
cat > /usr/bin/mysqldump <<'CHANGED_ENGINE'
#!/usr/bin/env bash
set -eu
mysql --defaults-file=/etc/mysql/debian.cnf --protocol=socket \
    -e 'ALTER TABLE fiche_crm.clients ENGINE=InnoDB;'
exec /usr/bin/mysqldump.fixture-real "$@"
CHANGED_ENGINE
chmod 0755 /usr/bin/mysqldump
if bash "$SCRIPT" --export-current-coffrac --allow-live-myisam-clients > /tmp/cofrac2-changed-engine.log 2>&1; then exit 1; fi
mv /usr/bin/mysqldump.fixture-real /usr/bin/mysqldump
grep -q 'liste des tables ou moteurs modifies' /tmp/cofrac2-changed-engine.log
test "$(find "$STATE" -name SHA256SUMS | wc -l)" -eq 2
test "$(find "$STATE" -name coffrac.sql.gz.partial | wc -l)" -eq 2
sha256sum -c /tmp/cofrac2-original.sha256
test "$(sql 'SELECT label FROM cofrac2production.sentinel WHERE id=42')" = 'V2 MUST STAY INTACT'
printf 'OK : dumps InnoDB et mixte verifies, MyISAM clients accepte explicitement sans ecriture/verrouillage explicite, moteurs inattendus/concurrence/changement de moteur/echec refuses, fichiers originaux et V2 inchanges.\n'
