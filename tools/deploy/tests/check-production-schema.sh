#!/usr/bin/env bash

# Run only against disposable source/vendor mounts and a disposable MySQL server.
set -Eeuo pipefail
umask 077
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ ! -f /.dockerenv ] \
    || [ "${COFRAC2_SCHEMA_TEST:-}" != 1 ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Refuse : conteneur Docker jetable root et COFRAC2_SCHEMA_TEST=1 requis.\n' >&2
    exit 1
fi
test ! -e /var/www/cofrac2
test ! -e /home/ubuntu/www/coffrac_preprod
test ! -e /var/lib/cofrac2-production
test -d /fixture-vendor
test -d /test-source
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
SCRIPT="$APP/tools/deploy/initialize-production-schema.sh"

install -d -m 0700 /etc/mysql
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
install -d -o cofrac2-deploy -g cofrac2-deploy -m 0700 "$APP"
cp -a /test-source/. "$APP/"
cp -a /fixture-vendor "$APP/vendor"
chown -R --no-dereference cofrac2-deploy:cofrac2-deploy "$APP"
runuser -u cofrac2-deploy -- git -C "$APP" init --initial-branch=v2 >/dev/null
runuser -u cofrac2-deploy -- git -C "$APP" add .
runuser -u cofrac2-deploy -- git -C "$APP" -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture >/dev/null
printf '#!/bin/sh\nexec /usr/bin/php "$@"\n' > /usr/bin/php8.4
chmod 0755 /usr/bin/php8.4
install -d -m 0700 /home/ubuntu/www/coffrac_preprod/public /etc/apache2/sites-available
printf 'ORIGINAL APP\n' > /home/ubuntu/www/coffrac_preprod/public/index.php
printf 'ORIGINAL VHOST\n' > /etc/apache2/sites-available/cofrac.conf
sha256sum /home/ubuntu/www/coffrac_preprod/public/index.php /etc/apache2/sites-available/cofrac.conf /etc/mysql/debian.cnf > /tmp/cofrac2-original.sha256
php "$APP/tools/deploy/prepare-production-database.php" --create-new-v2-database > /tmp/cofrac2-fixture-database.log

cp -p "$APP/.env" /tmp/cofrac2-fixture.env
sed -i 's/DB_DATABASE=cofrac2production/DB_DATABASE=fiche_crm/' "$APP/.env"
if bash "$SCRIPT" --initialize-empty-v2-schema > /tmp/cofrac2-wrong-env.log 2>&1; then exit 1; fi
grep -q '.env different' /tmp/cofrac2-wrong-env.log
! id cofrac2-app >/dev/null 2>&1
cp -p /tmp/cofrac2-fixture.env "$APP/.env"

sql 'CREATE TABLE cofrac2production.guard_row (id int primary key); INSERT INTO cofrac2production.guard_row VALUES (42);'
if bash "$SCRIPT" --initialize-empty-v2-schema > /tmp/cofrac2-nonempty.log 2>&1; then exit 1; fi
grep -q 'cible SQL non vide' /tmp/cofrac2-nonempty.log
test "$(sql 'SELECT id FROM cofrac2production.guard_row')" = 42
! id cofrac2-app >/dev/null 2>&1
sql 'DROP TABLE cofrac2production.guard_row;'

bash "$SCRIPT" --initialize-empty-v2-schema > /tmp/cofrac2-schema-success.log 2>&1
test -f "$STATE/SCHEMA-READY.json"
test "$(stat -c %U "$APP/.env")" = root
test "$(stat -c %G "$APP/.env")" = cofrac2-app
test "$(stat -c %a "$APP/.env")" = 640
test "$(stat -c %U "$APP/artisan")" = cofrac2-deploy
runuser -u cofrac2-app -- test -r "$APP/artisan"
runuser -u cofrac2-app -- test ! -w "$APP/artisan"
runuser -u cofrac2-app -- test ! -w "$APP/.env"
runuser -u cofrac2-app -- test ! -r "$APP/.git/config"
runuser -u cofrac2-app -- test ! -r /etc/mysql/debian.cnf
runuser -u cofrac2-app -- test ! -r "$STATE/initial-production.env"
runuser -u cofrac2-app -- touch "$APP/storage/app/private/fixture.txt"
runuser -u cofrac2-app -- touch "$APP/storage/app/public/fixture.txt"
chmod 0644 "$APP/storage/app/public/fixture.txt"
runuser -u cofrac2-app -- touch "$APP/bootstrap/cache/fixture.txt"
runuser -u www-data -- test -r "$APP/public/index.php"
runuser -u www-data -- test -r "$APP/public/storage/fixture.txt"
runuser -u www-data -- test ! -w "$APP/public/storage/fixture.txt"
runuser -u www-data -- test ! -r "$APP/.env"
runuser -u www-data -- test ! -r "$APP/artisan"
runuser -u www-data -- test ! -r "$APP/storage/app/private/fixture.txt"
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.users')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.dossiers')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.appointments')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.jobs')" = 0
test "$(sql 'SELECT COUNT(*) FROM cofrac2production.departments')" = 101
sha256sum "$APP/.env" "$STATE/SCHEMA-READY.json" > /tmp/cofrac2-schema.sha256
if bash "$SCRIPT" --initialize-empty-v2-schema > /tmp/cofrac2-schema-repeat.log 2>&1; then exit 1; fi
grep -q 'schema deja initialise' /tmp/cofrac2-schema-repeat.log
sha256sum -c /tmp/cofrac2-schema.sha256
sha256sum -c /tmp/cofrac2-original.sha256
test "$(sql 'SELECT label FROM fiche_crm.original_data WHERE id=1')" = 'ORIGINAL COFFRAC FIXTURE'
cat "$STATE/SCHEMA-READY.json"
printf 'OK : migrations reelles MySQL, compte runtime limite, fichiers publics seuls lisibles par Apache, refus des cibles non vides, original inchange.\n'
