#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "$EUID" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 2 ] && [ "$1" = --apply ] \
    && [[ "$2" =~ ^[a-f0-9]{64}$ ]] || stop 'sudo sur ns365601 avec --apply SHA256_DU_MANIFEST.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
[ "$(df -B1 --output=avail "$STATE" | tail -n 1 | tr -d ' ')" -gt 67108864 ] || stop 'moins de 64 Mio libres.'
exec 7>"$STATE/mymar-actions.lock"; flock -n 7 || stop 'operation MAR en cours.'
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'audit en cours.'
APP=/var/www/cofrac2
git_app() { runuser -u cofrac2-deploy -- env HOME=/home/cofrac2-deploy git -C "$APP" "$@"; }
[ "$(git_app branch --show-current)" = v2 ] && [ -z "$(git_app status --porcelain --untracked-files=no)" ] || stop 'code applicatif modifie.'
REV=$(git_app rev-parse HEAD)
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(($r["revision"]??null)!==$argv[2])exit(1);' \
    "$STATE/RUNTIME-PREPARED.json" "$REV" || stop 'revision runtime differente.'
for unit in cofrac2-php-fpm.service cofrac-mymar-fpm.service cofrac-mymar-http.service \
    cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service; do
    systemctl is-active --quiet "$unit" || stop "service existant inactif : $unit";
done
RUN=$(mktemp -d "$STATE/mymar-roster-activation-XXXXXXXX")
printf 'Preparation privee : %s\n' "$RUN"
install -d -m 0700 "$RUN/bundle"
cp -a --no-preserve=ownership "$BASE/." "$RUN/bundle/"
[ -z "$(find "$RUN/bundle" ! -type d ! -type f -print -quit)" ] || stop 'paquet indirect refuse.'
find "$RUN/bundle" -type d -exec chmod 0700 {} +
find "$RUN/bundle" -type f -exec chmod 0600 {} +
cd "$RUN/bundle"
[ "$(sha256sum SHA256SUMS | cut -d' ' -f1)" = "$2" ] || stop 'empreinte du manifeste differente.'
sha256sum -c SHA256SUMS
SUCCESS=0
finish() {
    code=$?; trap - EXIT
    if [ "$SUCCESS" != 1 ]; then
        if [ -s "$RUN/before.private.json" ] && [ -s "$RUN/planned.private.json" ]; then
            php8.4 ./activate.php --recover "$RUN" > "$RUN/recovery.log" 2>&1 || true
        fi
        printf 'Verification requise. Cofrac reste ouvert. Diagnostic prive : %s\n' "$RUN" >&2
        [ ! -s "$RUN/activation.stderr" ] || tail -n 1 "$RUN/activation.stderr"
    fi
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for domain in cofrac.geniuscontrole.com cofrac2.geniuscontrole.com; do
    curl --noproxy '*' --fail --silent --show-error --max-time 8 --resolve "$domain:443:127.0.0.1" -o /dev/null "https://$domain/up"
done
printf 'Lecture actualisee des comptes puis raccordement des identites certaines, sans import ni maintenance.\n'
timeout --signal=TERM --kill-after=3 180 php8.4 -d memory_limit=256M -d zend.exception_ignore_args=1 \
    ./activate.php --apply "$RUN" 2> "$RUN/activation.stderr"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-roster-activation-report-XXXXXXXX)
for file in plan.json summary.json cache-probe.json; do install -m 0600 "$RUN/$file" "$REPORT/$file"; done
printf 'PREPARATION_DIR=%s\n' "$RUN" > "$REPORT/preparation.txt"
(cd "$REPORT" && sha256sum plan.json summary.json cache-probe.json preparation.txt > SHA256SUMS && chmod 0600 preparation.txt SHA256SUMS)
chown ubuntu:ubuntu "$REPORT" "$REPORT/"*
SUCCESS=1
printf 'REPORT_DIR=%s\nCorrespondances raccordees. Actualiser Prendre un RDV > RDV MAR.\nAucun compte, mot de passe, habilitation, dossier ou service modifie. Aucun mail/SMS ou placement de test.\n' "$REPORT"
