#!/usr/bin/env bash
# Creates only new, disabled integration resources. Never starts a service or places a visit.
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] \
    && [ "$1" = --prepare-locked-booking-backend ] || stop 'sudo sur ns365601 avec --prepare-locked-booking-backend.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"; sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
for command in php8.4 timeout mysql install sha256sum flock; do command -v "$command" >/dev/null || stop "outil absent : $command"; done
exec 8>"$STATE/two-lots-release.lock"
flock -n 8 || stop 'un deploiement est deja en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"
flock -n 9 || stop 'une preparation MYMAR est deja en cours.'
RUN=$(mktemp -d "$STATE/mymar-booking-preparation-XXXXXXXX")
finish() {
    code=$?
    trap - EXIT
    if [ -f "$RUN/sql-account.private.json" ]; then
        if ! timeout --kill-after=3 30 php8.4 "$BASE/prepare-backend.php" --lock-only "$RUN" > "$RUN/safety-lock.log" 2>&1; then
            printf 'STOP : verrouillage du nouveau compte SQL non confirme ; consulter safety-lock.log.\n' >&2
            code=1
        fi
    fi
    if [ "$code" != 0 ]; then printf 'Diagnostic prive : %s\nAucun service ou placement active. Ne pas relancer aveuglement.\n' "$RUN" >&2; fi
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
install -d -m 0700 "$RUN/bundle"
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] \
        && [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'entree de paquet inattendue.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done < SHA256SUMS
install -o root -g root -m 0600 SHA256SUMS "$RUN/bundle/SHA256SUMS"
BASE="$RUN/bundle"
(cd "$BASE" && sha256sum -c SHA256SUMS)
printf 'Creation du compte technique MYMAR DESACTIVE, du journal dedie et du compte SQL VERROUILLE.\n'
printf 'Les RDV, dossiers, comptes existants, applications et services ne seront pas modifies.\n'
timeout --signal=TERM --kill-after=3 180 php8.4 -d memory_limit=128M "$BASE/prepare-backend.php" --prepare-disabled "$RUN"
timeout --kill-after=3 30 php8.4 "$BASE/prepare-backend.php" --lock-only "$RUN" > "$RUN/safety-lock.log" 2>&1
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-booking-preparation-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"; chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/summary.json" "$REPORT/summary.json"
cd "$REPORT"
sha256sum summary.json > SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS; chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Backend prepare mais ferme : aucun placement, aucun envoi, aucun service demarre ou recharge.\n'
