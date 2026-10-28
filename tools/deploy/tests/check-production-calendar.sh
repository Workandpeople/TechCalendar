#!/usr/bin/env bash
set -Eeuo pipefail
[ -f /.dockerenv ] && [ "${COFRAC2_RUNTIME_TEST:-}" = 1 ] && [ "$(hostname -s)" = ns365601 ] \
    && [ -f /etc/mysql/fixture.cnf ] || { echo 'Fixture Docker uniquement.' >&2; exit 1; }
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
trap 'printf "CALENDAR_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2; for log in /tmp/calendar-*.log; do echo "=== $log ===" >&2; tail -20 "$log" >&2; done' ERR
# Release fixtures deliberately use tiny synthetic assets, never real production files.
php8.4 -r '
    $path=$argv[1]."/public/build/manifest.json";
    $manifest=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    $entry="resources/js/native-calendar-entry.js";
    $manifest[$entry]=["src"=>$entry,"file"=>"assets/native-fixture.js"];
    file_put_contents($path,json_encode($manifest));
    file_put_contents($argv[1]."/public/build/assets/native-fixture.js","/* fixture only */");
' "$APP"
chmod 0644 "$APP/public/build/manifest.json" "$APP/public/build/assets/native-fixture.js"
sql() { mysql --defaults-file=/etc/mysql/fixture.cnf --batch --skip-column-names -e "$1"; }
sql 'SELECT id,email,password,role,admin,deleted_at,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/calendar-users.before
sql 'SELECT id,starts_at,ends_at,technician_id FROM cofrac2production.appointments ORDER BY id' > /tmp/calendar-rdv.before
bash "$APP/tools/deploy/set-production-calendar.sh" native > /tmp/calendar-native.log 2>&1
curl --noproxy '*' -fsS --resolve cofrac2.geniuscontrole.com:443:127.0.0.1 -b /tmp/runtime-cookie.jar \
    https://cofrac2.geniuscontrole.com/planner/tracking > /tmp/calendar-native.html
grep -q 'native-fixture.js' /tmp/calendar-native.html
! grep -q 'fullcalendar@6.1.15' /tmp/calendar-native.html
bash "$APP/tools/deploy/set-production-calendar.sh" fullcalendar > /tmp/calendar-fullcalendar.log 2>&1
curl --noproxy '*' -fsS --resolve cofrac2.geniuscontrole.com:443:127.0.0.1 -b /tmp/runtime-cookie.jar \
    https://cofrac2.geniuscontrole.com/planner/tracking > /tmp/calendar-fullcalendar.html
grep -q 'fullcalendar@6.1.15' /tmp/calendar-fullcalendar.html
! grep -q 'native-fixture.js' /tmp/calendar-fullcalendar.html
cp -p "$APP/.env" /tmp/calendar-env.before
cp -p "$STATE/RUNTIME-PREPARED.json" /tmp/calendar-marker.before
cp -p /usr/bin/php8.4 /tmp/calendar-php.before
cat > /usr/bin/php8.4 <<'WRAPPER'
#!/usr/bin/env bash
if [ -f /tmp/calendar-fail-cache ] && [[ "$*" = *'artisan config:cache'* ]]; then
    rm /tmp/calendar-fail-cache
    exit 38
fi
exec /tmp/calendar-php.before "$@"
WRAPPER
chmod 0755 /usr/bin/php8.4
touch /tmp/calendar-fail-cache
chown cofrac2-app:cofrac2-app /tmp/calendar-fail-cache
if bash "$APP/tools/deploy/set-production-calendar.sh" native > /tmp/calendar-refused.log 2>&1; then exit 1; fi
cmp -s "$APP/.env" /tmp/calendar-env.before
cmp -s "$STATE/RUNTIME-PREPARED.json" /tmp/calendar-marker.before
cp -p /tmp/calendar-php.before /usr/bin/php8.4
sql 'SELECT id,email,password,role,admin,deleted_at,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/calendar-users.after
sql 'SELECT id,starts_at,ends_at,technician_id FROM cofrac2production.appointments ORDER BY id' > /tmp/calendar-rdv.after
cmp -s /tmp/calendar-users.before /tmp/calendar-users.after
cmp -s /tmp/calendar-rdv.before /tmp/calendar-rdv.after
sha256sum --status -c /tmp/runtime-original.sha256
printf 'OK : activation calendrier, retour FullCalendar, echec cache restaure, comptes/MDP/RDV et ancien site inchanges.\n'
