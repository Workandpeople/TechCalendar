#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "$EUID" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 2 ] \
    && { [ "$1" = --audit ] || [ "$1" = --apply ]; } && [[ "$2" =~ ^[a-f0-9]{64}$ ]] \
    || stop 'Usage : sudo bash retire-link.sh --audit|--apply SHA256_DU_MANIFEST'
MODE=$1
BASE=$(cd "$(dirname "$0")" && pwd -P)
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
[ "$(df -B1 --output=avail "$STATE" | tail -n 1 | tr -d ' ')" -gt 67108864 ] || stop 'moins de 64 Mio libres.'
exec 7>"$STATE/mymar-actions.lock"; flock -n 7 || stop 'operation MAR en cours.'
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'audit en cours.'
for unit in cofrac2-php-fpm.service cofrac-mymar-fpm.service cofrac-mymar-http.service \
    cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service cofrac-mymar-actions-fpm.service cofrac-mymar-actions-http.service; do
    systemctl is-active --quiet "$unit" || stop "service existant inactif : $unit";
done
RUN=$(mktemp -d "$STATE/mymar-roster-retirement-XXXXXXXX")
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
    if [ "$SUCCESS" != 1 ] && [ -s "$RUN/before.private.json" ] && [ -s "$RUN/planned.private.json" ] && [ ! -e "$RUN/summary.json" ]; then
        php8.4 ./retire.php --recover "$RUN" > "$RUN/recovery.log" 2>&1 \
            || printf 'Restauration a verifier dans le diagnostic prive.\n' >&2
    fi
    printf 'DIAGNOSTIC_DIR=%s\n' "$RUN"
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
timeout --signal=TERM --kill-after=3 120 php8.4 -d memory_limit=256M ./retire.php "$MODE" "$RUN"
SUCCESS=1
if [ "$MODE" = --apply ]; then
    printf 'Ancien lien retire, cache MAR verifie. Actualiser le planning du technicien.\n'
else
    printf 'Audit seul : aucune configuration modifiee, aucun appel MYMAR.\n'
fi
