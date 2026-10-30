#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || exit 1
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"; sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || exit 1
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || exit 1
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);$q=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    if(($q["format"]??null)!=="cofrac.mymar-conflict-preview-request"||($q["version"]??null)!==1||($r["revision"]??null)!==($q["runtime_revision"]??null))exit(1);' "$STATE/RUNTIME-PREPARED.json" "$BASE/REQUEST.json"
[ -f "$STATE/INTERNAL-SITE-OPEN.json" ] || exit 1
RUN=$(mktemp -d "$STATE/mymar-conflict-preview-XXXXXXXX")
READABLE=$(mktemp -d /var/lib/cofrac2-app/mymar-conflict-preview-XXXXXXXX)
chown root:cofrac2-app "$READABLE"; chmod 0750 "$READABLE"
for file in inspect.php MymarOccupancySnapshot.php REQUEST.json; do
    [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || exit 1
    install -o root -g cofrac2-app -m 0640 "$BASE/$file" "$READABLE/$file"
done
shared_state() { systemctl show apache2.service mysql.service cofrac2-php-fpm.service cofrac2-queue.service cofrac2-reverb.service cofrac-mymar-fpm.service cofrac-mymar-http.service cofrac2-mymar-observation.timer -p Id -p MainPID -p ActiveState -p ExecMainStartTimestampMonotonic; }
shared_state > "$RUN/services.before"
sha256sum /var/www/cofrac2/.env /var/www/cofrac2/bootstrap/cache/config.php /etc/cofrac2-mymar/pilot.json /etc/cofrac2-mymar/api-token > "$RUN/unchanged.sha256"
printf 'Verification a blanc : cache et planning Cofrac uniquement, SQL READ ONLY. Aucun appel MYMAR, envoi ou maintenance.\n'
timeout --signal=TERM --kill-after=3 30 runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app \
    USER=cofrac2-app LOGNAME=cofrac2-app php8.4 "$READABLE/inspect.php" > "$RUN/report.json" 2> "$RUN/inspect.stderr" || { printf 'STOP : diagnostic prive %s\n' "$RUN" >&2; exit 1; }
sha256sum -c "$RUN/unchanged.sha256" > "$RUN/unchanged-check.log"
shared_state > "$RUN/services.after"; cmp "$RUN/services.before" "$RUN/services.after"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-conflicts-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/report.json" "$REPORT/report.json"
(cd "$REPORT" && sha256sum report.json > SHA256SUMS && chown ubuntu:ubuntu SHA256SUMS && chmod 0600 SHA256SUMS)
cat "$RUN/report.json"
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Verification terminee. Aucun changement des rendez-vous, droits, disponibilites ou services.\n'
