#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
[ "$EUID" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || exit 1
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"; sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || exit 1
exec 7>"$STATE/mymar-actions.lock"; flock -n 7 || exit 1
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || exit 1
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || exit 1
APP=/var/www/cofrac2
REV=$(runuser -u cofrac2-deploy -- env HOME=/home/cofrac2-deploy git -C "$APP" rev-parse HEAD)
[ "$REV" = "$(cat SOURCE_REVISION)" ] || { echo 'STOP : revision inattendue.' >&2; exit 1; }
RUN=$(mktemp -d "$STATE/mymar-actions-diagnostic-XXXXXXXX")
trap 'result=$?; if [ "$result" -ne 0 ]; then printf "Diagnostic incomplet. Dossier prive : %s\n" "$RUN" >&2; fi' EXIT
systemctl show cofrac2-php-fpm.service cofrac-mymar-fpm.service cofrac-mymar-http.service \
    cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service \
    -p Id -p ActiveState -p SubState -p Result > "$RUN/services.txt"
# Root opens stdin; runtime gets no access to the private diagnostic directory.
cp "$BASE/ReadinessDiagnostic.php" "$RUN/runtime.php"
cat >> "$RUN/runtime.php" <<'PHP'

chdir('/var/www/cofrac2');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode((new ReadinessDiagnostic(app(MymarActionSettings::class), app(MymarBookingReaderSettings::class),
    app(MymarPilotSettings::class), app(MymarSlotGuard::class), app(Factory::class), app(MymarPendingBookings::class)))->inspect(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
PHP
printf 'Diagnostic lecture/GET uniquement ; aucune activation, placement, notification ou maintenance.\n'
timeout --kill-after=3 90 runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app \
    php8.4 -d memory_limit=256M -d display_errors=0 -d zend.exception_ignore_args=1 < "$RUN/runtime.php" > "$RUN/report.json" 2> "$RUN/runtime.stderr"
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(($r["format"]??null)!=="cofrac.mymar-actions-readiness-diagnostic"||($r["activation_performed"]??null)!==false)exit(1);' "$RUN/report.json"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-actions-diagnostic-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
for file in report.json services.txt; do install -o ubuntu -g ubuntu -m 0600 "$RUN/$file" "$REPORT/$file"; done
(cd "$REPORT" && sha256sum report.json services.txt > SHA256SUMS && chown ubuntu:ubuntu SHA256SUMS && chmod 0600 SHA256SUMS)
printf 'REPORT_DIR=%s\nAucun code, service, compte ou rendez-vous modifie ; seuls les caches techniques peuvent etre touches par le bootstrap Laravel.\n' "$REPORT"
