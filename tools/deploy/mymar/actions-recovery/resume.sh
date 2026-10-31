#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "$EUID" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] && [ "$1" = --resume-pilot-actions ] || stop 'invocation refusee.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"; sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
exec 7>"$STATE/mymar-actions.lock"; flock -n 7 || stop 'operation MAR en cours.'
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'audit en cours.'
RUN=$(php8.4 -r '$r=json_decode(file_get_contents("REQUEST.json"),true,32,JSON_THROW_ON_ERROR);echo $r["original_run"];')
REV=$(php8.4 -r '$r=json_decode(file_get_contents("REQUEST.json"),true,32,JSON_THROW_ON_ERROR);echo $r["runtime_revision"];')
[[ "$RUN" =~ ^/var/lib/cofrac2-production/mymar-actions-[A-Za-z0-9]+$ ]] && [[ "$REV" =~ ^[a-f0-9]{40}$ ]] || stop 'cible invalide.'
APP=/var/www/cofrac2
git_app() { runuser -u cofrac2-deploy -- env HOME=/home/cofrac2-deploy git -C "$APP" "$@"; }
[ "$(git_app rev-parse HEAD)" = "$REV" ] && [ -z "$(git_app status --porcelain --untracked-files=no)" ] || stop 'revision ou code modifie.'
for unit in cofrac2-php-fpm.service cofrac-mymar-fpm.service cofrac-mymar-http.service cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service; do
    systemctl is-active --quiet "$unit" || stop "service existant inactif : $unit";
done
for unit in cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service; do
    [ "$(systemctl show "$unit" -p ActiveState --value)" = inactive ] || stop 'actions deja actives ou etat inattendu.'
    cmp -s "$RUN/$unit" "/etc/systemd/system/$unit" || stop 'unite modifiee.'
done
[ -z "$(ss -H -ltn 'sport = :19848')" ] || stop 'port deja occupe.'
RECOVERY=$(mktemp -d "$STATE/mymar-actions-recovery-XXXXXXXX")
install -d -m 0700 "$RECOVERY/bundle"
for file in repair.php ReadinessDiagnostic.php MymarActionsConfiguration.php REQUEST.json resume.sh fetch-recovery.sh README.txt SHA256SUMS; do
    [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'fichier indirect.'
    install -o root -g root -m 0600 "$BASE/$file" "$RECOVERY/bundle/$file"
done
(cd "$RECOVERY/bundle" && sha256sum -c SHA256SUMS)
SUCCESS=0
finish() {
    result=$?; trap - EXIT
    if [ "$SUCCESS" != 1 ]; then
        if [ -f "$RECOVERY/ownership.json" ]; then
            php8.4 "$RUN/bundle/control.php" --disable "$RUN" > "$RECOVERY/disable.log" 2>&1 || result=1
            systemctl disable --now cofrac-mymar-actions-http.service cofrac-mymar-actions-fpm.service > "$RECOVERY/stop.log" 2>&1 || result=1
            php8.4 "$RUN/bundle/control.php" --lock "$RUN" > "$RECOVERY/lock.log" 2>&1 || result=1
        fi
        printf 'Reprise non confirmee. Aucun arret de Cofrac. Diagnostic prive : %s\n' "$RECOVERY" >&2
        if [ -s "$RECOVERY/readiness.json" ]; then cat "$RECOVERY/readiness.json"; fi
    fi
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
printf 'Reparation ciblee puis controles GET ; aucun pull, migration, maintenance ou placement de test.\n'
timeout --kill-after=3 120 php8.4 "$RECOVERY/bundle/repair.php" "$RECOVERY"
cp "$RECOVERY/bundle/ReadinessDiagnostic.php" "$RECOVERY/runtime.php"
cat >> "$RECOVERY/runtime.php" <<'PHP'

chdir('/var/www/cofrac2'); require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode((new ReadinessDiagnostic(app(MymarActionSettings::class), app(MymarBookingReaderSettings::class),
    app(MymarPilotSettings::class), app(MymarSlotGuard::class), app(Factory::class), app(MymarPendingBookings::class)))->inspect(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
PHP
timeout --kill-after=3 90 runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app \
    php8.4 -d display_errors=0 -d zend.exception_ignore_args=1 -d memory_limit=256M < "$RECOVERY/runtime.php" > "$RECOVERY/readiness.json" 2> "$RECOVERY/readiness.stderr"
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(($r["readiness_passed"]??null)!==true)exit(1);' "$RECOVERY/readiness.json"
timeout --kill-after=3 90 runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app \
    php8.4 -d memory_limit=256M "$APP/artisan" mymar:actions-check --no-interaction --no-ansi > "$RUN/application-probe.json" 2> "$RECOVERY/application-probe.stderr"
cat > "$RECOVERY/stop.sh" <<STOP
#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
RUN='$RUN'
exec 7>/var/lib/cofrac2-production/mymar-actions.lock; flock -n 7 || exit 1
result=0
php8.4 "\$RUN/bundle/control.php" --disable "\$RUN" || result=1
systemctl disable --now cofrac-mymar-actions-http.service cofrac-mymar-actions-fpm.service || result=1
php8.4 "\$RUN/bundle/control.php" --lock "\$RUN" || result=1
[ "\$result" = 0 ] || exit 1
printf 'Actions MAR fermees ; lecteurs et Cofrac inchanges.\n'
STOP
chmod 0600 "$RECOVERY/stop.sh"
php8.4 "$RUN/bundle/control.php" --unlock "$RUN"
systemctl start cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service
for _ in $(seq 1 30); do
    if [ -S /run/cofrac-mymar-actions-fpm/php.sock ] && [ -n "$(ss -H -ltn 'src 127.0.0.1 and sport = :19848')" ]; then break; fi
    sleep 1
done
if [ -e "$RUN/http-checks.json" ]; then
    [ -f "$RUN/http-checks.json" ] && [ ! -L "$RUN/http-checks.json" ] || stop 'preuve HTTP indirecte.'
    mv "$RUN/http-checks.json" "$RECOVERY/initial-http-checks.json"
fi
timeout --kill-after=3 30 php8.4 "$RUN/bundle/control.php" --probe "$RUN"
php8.4 "$RUN/bundle/control.php" --enable "$RUN"
systemctl enable cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service > "$RECOVERY/enable.log" 2>&1
for domain in cofrac.geniuscontrole.com cofrac2.geniuscontrole.com; do
    curl --noproxy '*' --fail --silent --show-error --max-time 8 --resolve "$domain:443:127.0.0.1" -o /dev/null "https://$domain/up"
done
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-actions-recovery-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/summary.json" "$REPORT/summary.json"
install -o ubuntu -g ubuntu -m 0600 "$RECOVERY/repair.json" "$REPORT/repair.json"
install -o ubuntu -g ubuntu -m 0600 "$RECOVERY/readiness.json" "$REPORT/readiness.json"
(cd "$REPORT" && sha256sum summary.json repair.json readiness.json > SHA256SUMS && chown ubuntu:ubuntu SHA256SUMS && chmod 0600 SHA256SUMS)
SUCCESS=1
printf 'REPORT_DIR=%s\nPilote MAR active ; aucun placement de test ni message. Aucun deploiement du site.\nArret des seules actions : sudo bash %s/stop.sh\n' "$REPORT" "$RECOVERY"
