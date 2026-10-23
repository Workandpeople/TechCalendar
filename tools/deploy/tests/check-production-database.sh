#!/usr/bin/env bash

# Only in a disposable PHP container sharing a disposable MySQL 8.0 fixture.
set -Eeuo pipefail
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ ! -f /.dockerenv ] \
    || [ "${COFRAC2_DATABASE_TEST:-}" != 1 ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Refuse : conteneur Docker jetable root et COFRAC2_DATABASE_TEST=1 requis.\n' >&2
    exit 1
fi
test ! -e /var/www/cofrac2
test ! -e /home/ubuntu/www/coffrac_preprod
test ! -e /var/lib/cofrac2-production
test -d /fixture-vendor
TOOLS=$(cd -- "$(dirname -- "$0")/.." && pwd)
APP=/var/www/cofrac2
SCRIPT="$APP/tools/deploy/prepare-production-database.php"

install -d -m 0700 /root /etc/mysql
cat > /root/cofrac2-fixture-root.cnf <<'ROOT'
[client]
user=root
password="FixtureRootA1!"
socket=/var/run/mysqld/mysqld.sock
ROOT
chmod 0600 /root/cofrac2-fixture-root.cnf
for attempt in {1..60}; do
    if mysql --defaults-file=/root/cofrac2-fixture-root.cnf --protocol=socket -NBe 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 1
done
mysql --defaults-file=/root/cofrac2-fixture-root.cnf --protocol=socket <<'SQL'
CREATE DATABASE fiche_crm;
CREATE TABLE fiche_crm.original_data (id int primary key, label varchar(64));
INSERT INTO fiche_crm.original_data VALUES (1, 'ORIGINAL COFFRAC FIXTURE');
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

useradd --create-home --user-group cofrac2-deploy
install -d -o cofrac2-deploy -g cofrac2-deploy -m 0700 "$APP" "$APP/tools" "$APP/tools/deploy"
cp "$TOOLS/prepare-production-database.php" "$TOOLS/production.env.example" "$APP/tools/deploy/"
printf 'vendor\n.env\n' > "$APP/.gitignore"
ln -s /fixture-vendor "$APP/vendor"
chown -R --no-dereference cofrac2-deploy:cofrac2-deploy "$APP"
runuser -u cofrac2-deploy -- git -C "$APP" init --initial-branch=v2 >/dev/null
runuser -u cofrac2-deploy -- git -C "$APP" add .gitignore tools
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture >/dev/null
install -d -m 0700 /home/ubuntu/www/coffrac_preprod/public /etc/apache2/sites-available
printf 'ORIGINAL APP\n' > /home/ubuntu/www/coffrac_preprod/public/index.php
printf 'ORIGINAL VHOST\n' > /etc/apache2/sites-available/cofrac.conf
sha256sum /home/ubuntu/www/coffrac_preprod/public/index.php /etc/apache2/sites-available/cofrac.conf /etc/mysql/debian.cnf > /tmp/cofrac2-original.sha256

sql 'CREATE DATABASE cofrac2production; CREATE TABLE cofrac2production.sentinel (id int); INSERT INTO cofrac2production.sentinel VALUES (42);'
if php "$SCRIPT" --create-new-v2-database > /tmp/cofrac2-existing-db.log 2>&1; then exit 1; fi
grep -q 'Base ou compte SQL deja existant' /tmp/cofrac2-existing-db.log
test "$(sql 'SELECT id FROM cofrac2production.sentinel')" = 42
test ! -e "$APP/.env"
test ! -e /var/lib/cofrac2-production
sql 'DROP DATABASE cofrac2production;'

sql "CREATE USER 'cofrac2_app'@'192.0.2.25' IDENTIFIED BY 'ExistingFixtureA1!';"
if php "$SCRIPT" --create-new-v2-database > /tmp/cofrac2-existing-user.log 2>&1; then exit 1; fi
grep -q 'Base ou compte SQL deja existant' /tmp/cofrac2-existing-user.log
test "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cofrac2_app'")" = 1
test ! -e /var/lib/cofrac2-production
sql "DROP USER 'cofrac2_app'@'192.0.2.25';"

php "$SCRIPT" --create-new-v2-database > /tmp/cofrac2-database-success.log
test "$(stat -c %a "$APP/.env")" = 600
test "$(stat -c %U "$APP/.env")" = cofrac2-deploy
test "$(stat -c %a /var/lib/cofrac2-production)" = 700
test "$(stat -c %a /var/lib/cofrac2-production/initial-production.env)" = 600
cmp "$APP/.env" /var/lib/cofrac2-production/initial-production.env
php <<'PHP'
<?php
require '/fixture-vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents('/var/www/cofrac2/.env'));
foreach (['DB_PASSWORD', 'APP_KEY', 'REVERB_APP_SECRET'] as $key) {
    if (strlen($env[$key]) < 40 || str_contains(file_get_contents('/tmp/cofrac2-database-success.log'), $env[$key])) exit(1);
}
if ($env['APP_URL'] !== 'https://cofrac2.geniuscontrole.com' || $env['MAIL_MAILER'] !== 'log'
    || $env['SMSFACTOR_MODE'] !== 'simulate' || $env['SMSFACTOR_ENABLED'] !== 'false') exit(1);
$db = new PDO('mysql:host=127.0.0.1;dbname='.$env['DB_DATABASE'], $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() !== 0) exit(1);
try { $db->query('SELECT * FROM fiche_crm.original_data'); exit(1); } catch (PDOException $e) {
    if (! in_array($e->errorInfo[1], [1044, 1142], true)) throw $e;
}
try { $db->exec("CREATE USER 'unauthorized_fixture'@'localhost' IDENTIFIED BY 'NeverCreatedA1!'"); exit(1); } catch (PDOException $e) {
    if ($e->errorInfo[1] !== 1227) throw $e;
}
$db->exec('CREATE TABLE own_scope (id int primary key)');
$db->exec('INSERT INTO own_scope VALUES (42)');
if ((int) $db->query('SELECT id FROM own_scope')->fetchColumn() !== 42) exit(1);
$db->exec('DROP TABLE own_scope');
PHP
sha256sum "$APP/.env" /var/lib/cofrac2-production/initial-production.env > /tmp/cofrac2-secrets.sha256
if php "$SCRIPT" --create-new-v2-database > /tmp/cofrac2-repeat.log 2>&1; then exit 1; fi
grep -q 'Cible deja preparee' /tmp/cofrac2-repeat.log
sha256sum -c /tmp/cofrac2-secrets.sha256
sha256sum -c /tmp/cofrac2-original.sha256
test "$(sql 'SELECT label FROM fiche_crm.original_data WHERE id=1')" = 'ORIGINAL COFFRAC FIXTURE'
test "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='unauthorized_fixture'")" = 0

# Force a partial DDL failure in the disposable database, never on the real host.
sql "DROP DATABASE cofrac2production; DROP USER 'cofrac2_app'@'localhost', 'cofrac2_app'@'127.0.0.1'; REVOKE INSERT, CREATE USER ON *.* FROM 'debian-sys-maint'@'localhost';"
rm "$APP/.env"
rm -r /var/lib/cofrac2-production
if php "$SCRIPT" --create-new-v2-database > /tmp/cofrac2-partial-ddl.log 2>&1; then exit 1; fi
grep -q 'Commande MySQL refusee' /tmp/cofrac2-partial-ddl.log
test -f /var/lib/cofrac2-production/initial-production.env
test ! -e /var/lib/cofrac2-production/DATABASE-READY.json
test ! -e "$APP/.env"
test "$(sql "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='cofrac2production'")" = 1
test "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cofrac2_app'")" = 0
test "$(sql 'SELECT label FROM fiche_crm.original_data WHERE id=1')" = 'ORIGINAL COFFRAC FIXTURE'
sha256sum -c /tmp/cofrac2-original.sha256
printf 'OK : droits isoles, cibles existantes refusees, secrets prives conserves apres echec DDL, ancien Coffrac inchange.\n'
