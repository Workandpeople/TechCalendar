#!/usr/bin/env bash
# Local disposable containers only. Real SQL, Laravel cache and loopback GET gateways.
set -Eeuo pipefail
[ "${MYMAR_ROSTER_ACTIVATION_TEST:-}" = 1 ] || exit 1
ROOT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-roster-activation-test-XXXXXXXX)
SUFFIX=$(php -r 'echo bin2hex(random_bytes(5));')
DB="roster-db-$SUFFIX" PHP="roster-php-$SUFFIX" SOCKET="roster-socket-$SUFFIX"
cleanup() {
    code=$?
    docker cp "$PHP:/fixtures" "$WORK/reports" >/dev/null 2>&1 || true
    if [ "$code" != 0 ]; then docker cp "$PHP:/var/lib/cofrac2-production" "$WORK/private-fixture" >/dev/null 2>&1 || true; fi
    if [ "$code" != 0 ]; then tail -n 15 "$WORK/reports/"*.log 2>/dev/null || true; fi
    docker rm -fv "$PHP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$SOCKET" >/dev/null 2>&1 || true
    printf 'Tests raccordement : %s\n' "$WORK"
    exit "$code"
}
trap cleanup EXIT
docker volume create "$SOCKET" >/dev/null
docker run -d --name "$DB" --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46 >/dev/null
for _ in $(seq 1 90); do
    if [ "$(docker exec "$DB" mysql -NBe 'SELECT @@port' 2>/dev/null || true)" = 3306 ]; then break; fi
    sleep 1
done
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/tests/mymar-booking-fixture.sql"
docker exec -i "$DB" mysql < "$ROOT/tools/deploy/mymar/booking/journal.sql"
docker run -d --name "$PHP" --hostname ns365601 --network none --mount "type=volume,source=$SOCKET,target=/var/run/mysqld" \
    --mount "type=bind,source=$ROOT,target=/workspace,readonly" --entrypoint sleep cofrac2-runtime-check:local 600 >/dev/null
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
mkdir -m 700 /fixtures /package /var/lib/cofrac2-production
test -x /usr/bin/php8.4 || ln -s /usr/bin/php /usr/bin/php8.4
for user in ubuntu cofrac2-app cofrac2-deploy cofrac-mymar cofrac-mymar-booking cofrac-mymar-actions; do
    useradd -M -s /usr/sbin/nologin "$user"
done
mkdir -p /etc/mysql /var/www/cofrac2/bootstrap/cache /var/www/cofrac2/storage/framework/cache/data /var/www/cofrac2/storage/logs
cp -R /workspace/{app,config} /var/www/cofrac2/
cp /workspace/composer.json /var/www/cofrac2/
ln -s /workspace/vendor /var/www/cofrac2/vendor
printf '[client]\nuser=root\nsocket=/var/run/mysqld/mysqld.sock\n' > /etc/mysql/debian.cnf
chmod 600 /etc/mysql/debian.cnf
cp /workspace/tools/deploy/mymar/roster/{activate.php,activate.sh,apply-roster.sh,runtime-probe.php,MymarRosterActivation.php,MymarTechnicianRoster.php,ACTIVATION.txt} /package/
cp /workspace/tools/deploy/mymar/MymarReadOnlyAudit.php /workspace/tools/deploy/mymar/runtime/MymarRuntimeState.php /package/
sed -i '/} catch (Throwable $error) {/a\    fwrite(STDERR, $error->getMessage().PHP_EOL);' /package/runtime-probe.php
php8.4 /workspace/tools/deploy/tests/mymar-roster-activation-fixture.php setup
chown -R cofrac2-app:cofrac2-app /var/www/cofrac2/storage
chmod -R u+rwX,go-rwx /var/www/cofrac2/storage
chmod 775 /var/www/cofrac2/bootstrap/cache
chgrp cofrac2-app /var/www/cofrac2/bootstrap/cache
cat > /usr/bin/systemctl <<'STUB'
#!/bin/sh
if [ "$1" = is-active ]; then exit 0; fi
touch /fixtures/FORBIDDEN
exit 99
STUB
cat > /usr/bin/curl <<'STUB'
#!/bin/sh
printf '%s\n' "$*" >> /fixtures/health-checks.log
exit 0
STUB
cat > /usr/bin/git <<'STUB'
#!/bin/sh
case "$*" in
  *'branch --show-current') printf 'v2\n';;
  *'status --porcelain --untracked-files=no') exit 0;;
  *'rev-parse HEAD') printf '%040d\n' 1;;
  *) touch /fixtures/FORBIDDEN; exit 99;;
esac
STUB
chmod 755 /usr/bin/{systemctl,curl,git}
printf '{"revision":"0000000000000000000000000000000000000001"}' > /var/lib/cofrac2-production/RUNTIME-PREPARED.json
O=/var/lib/cofrac-mymar-gateway/code
mkdir -p "$O/app/Services/Mymar" "$O/tools/deploy/mymar/runtime" "$O/tools/deploy/mymar/gateway"
cp /workspace/app/Services/Mymar/{MymarObservation.php,MymarObservationPolicy.php,MymarReadWindow.php} "$O/app/Services/Mymar/"
cp /workspace/tools/deploy/mymar/runtime/entry.php "$O/tools/deploy/mymar/runtime/"
cp /workspace/tools/deploy/mymar/gateway/{serve.php,MymarGateway.php,MymarGatewayConfig.php,MymarReadOnlySource.php} "$O/tools/deploy/mymar/gateway/"
for kind in booking actions; do
    D="/var/lib/cofrac-mymar-$kind-gateway/code"
    mkdir -p "$D"
    cp /workspace/tools/deploy/mymar/booking/{MymarBookingFailure.php,MymarBookingSqlAccess.php,MymarBookingSource.php,MymarBookingGateway.php} "$D/"
    cp /workspace/app/Services/Mymar/{MymarBookingCommand.php,MymarObservation.php,MymarObservationPolicy.php,MymarReadWindow.php} "$D/"
    if [ "$kind" = booking ]; then cp /workspace/tools/deploy/mymar/booking-http/entry.php "$D/entry.php";
    else cp /workspace/tools/deploy/mymar/actions/entry.php "$D/entry.php"; fi
done
runuser -u cofrac-mymar -- env MYMAR_GATEWAY_CONFIG=/var/lib/cofrac-mymar-gateway/private/config.json \
    MYMAR_GATEWAY_SCOPE=/var/lib/cofrac-mymar-gateway/private/scope.json \
    php8.4 -S 127.0.0.1:19846 "$O/tools/deploy/mymar/runtime/entry.php" > /fixtures/occupations.log 2>&1 &
runuser -u cofrac-mymar-booking -- env MYMAR_BOOKING_CONFIG=/var/lib/cofrac-mymar-booking-gateway/private/config.json \
    php8.4 -S 127.0.0.1:19847 /var/lib/cofrac-mymar-booking-gateway/code/entry.php > /fixtures/reader.log 2>&1 &
runuser -u cofrac-mymar-actions -- env MYMAR_ACTIONS_CONFIG=/var/lib/cofrac-mymar-actions-gateway/private/config.json \
    php8.4 -S 127.0.0.1:19848 /var/lib/cofrac-mymar-actions-gateway/code/entry.php > /fixtures/writer.log 2>&1 &
sleep 1
find /home/ubuntu/www/MYMAR /var/www/cofrac2/app /var/www/cofrac2/config -type f -exec sha256sum {} + | sort > /fixtures/code.before
cd /package
sha256sum * > SHA256SUMS
SH
checksums() {
    docker exec "$DB" mysql -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.clients,MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_historiques,MYMAR.roles,MYMAR.model_has_roles,cofrac2production.users,cofrac2production.services,cofrac2production.service_user,cofrac2production.department_user,cofrac_mymar_integration.booking_commands;'
}
checksums > "$WORK/database.before"
docker exec -i "$PHP" bash <<'SH'
set -Eeuo pipefail
cd /package
SHA=$(sha256sum SHA256SUMS | cut -d' ' -f1)
bash activate.sh --apply "$SHA" > /fixtures/success.log 2>&1
php8.4 -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-roster-activation-fixture.php verify-expanded
REPORT=$(sed -n 's/^REPORT_DIR=//p' /fixtures/success.log)
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if($r["mapped_technicians"]!==3||$r["new_links"]!==2||!$r["cache_ready"])exit(1);' "$REPORT/summary.json"
! grep -Eiq 'CANARY|database_password|email_key|@example.test' "$REPORT/"*.json
RUN=$(sed -n 's/^Preparation privee : //p' /fixtures/success.log)
if bash "$RUN/recover-preparation.sh" > /fixtures/refused-recovery.log 2>&1; then exit 1; fi
php8.4 -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-roster-activation-fixture.php verify-expanded
bash activate.sh --apply "$SHA" > /fixtures/idempotent.log 2>&1
php8.4 -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-roster-activation-fixture.php verify-expanded
php8.4 /workspace/tools/deploy/tests/mymar-roster-activation-fixture.php reset
cp /var/lib/cofrac-mymar-gateway/private/config.json /fixtures/occupation-config.saved
php8.4 -r '$p="/var/lib/cofrac-mymar-gateway/private/config.json";$c=json_decode(file_get_contents($p),true,16,JSON_THROW_ON_ERROR);$c["enabled"]=false;file_put_contents($p,json_encode($c));'
if bash activate.sh --apply "$SHA" > /fixtures/failed-probe.log 2>&1; then exit 1; fi
cat /fixtures/occupation-config.saved > /var/lib/cofrac-mymar-gateway/private/config.json
php8.4 -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-roster-activation-fixture.php verify-original
mv /etc/cofrac2-mymar-actions/actions.json /fixtures/actions.saved
ln -s /fixtures/actions.saved /etc/cofrac2-mymar-actions/actions.json
if bash activate.sh --apply "$SHA" > /fixtures/symlink.log 2>&1; then exit 1; fi
rm /etc/cofrac2-mymar-actions/actions.json
mv /fixtures/actions.saved /etc/cofrac2-mymar-actions/actions.json
php8.4 -d zend.assertions=1 -d assert.exception=1 /workspace/tools/deploy/tests/mymar-roster-activation-fixture.php verify-original
find /home/ubuntu/www/MYMAR /var/www/cofrac2/app /var/www/cofrac2/config -type f -exec sha256sum {} + | sort > /fixtures/code.after
cmp /fixtures/code.before /fixtures/code.after
test ! -e /fixtures/FORBIDDEN
! grep -Eh 'POST |PUT |PATCH |DELETE ' /fixtures/{occupations,reader,writer}.log
SH
checksums > "$WORK/database.after"
cmp "$WORK/database.before" "$WORK/database.after"
echo 'Raccordement : MySQL READ ONLY, vrais GET prives, cache Laravel, idempotence, restauration et refus apres publication OK. Aucun compte/RDV/service modifie.'
