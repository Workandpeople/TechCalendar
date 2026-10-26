#!/usr/bin/env bash

# Read-only target inspection, without bootstrapping Laravel or running application commands.
set +x
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[[ "${EUID:-$(id -u)}" = 0 && "$(hostname -s)" = ns365601 && "$#" = 2 && "$1" = --plan && "$2" =~ ^[a-f0-9]{64}$ ]] \
    || stop 'Usage root sur ns365601 : --plan MANIFEST_SHA256'
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
STATE=/var/lib/cofrac2-production
[[ "$HERE" =~ ^/home/ubuntu/cofrac2-internal-users-plan-[A-Za-z0-9]{8}$ ]] || stop 'paquet prive sous /home/ubuntu attendu.'
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive V2 inattendu.'
for command in php8.4 sha256sum flock install gzip mktemp; do
    command -v "$command" >/dev/null || stop "outil manquant : $command"
done
[ -f "$HERE/SHA256SUMS" ] && [ ! -L "$HERE/SHA256SUMS" ] || stop 'manifeste absent.'
[ "$(sha256sum "$HERE/SHA256SUMS" | cut -d' ' -f1)" = "$2" ] || stop 'empreinte du paquet differente.'
FILES=(plan-techcalendar-internal-users.sh plan-techcalendar-internal-users.php lib/TechCalendarInternalUsersPlan.php fetch-internal-users-plan.sh references.json.gz SOURCE_SHA256 README.txt)
for file in "${FILES[@]}"; do
    [ -f "$HERE/$file" ] && [ ! -L "$HERE/$file" ] || stop 'fichier du paquet absent ou symbolique.'
done
[ -d "$HERE/lib" ] && [ ! -L "$HERE/lib" ] || stop 'bibliotheque symbolique refusee.'
(cd "$HERE" && sha256sum -c SHA256SUMS)
exec 9>"$STATE/runtime-operation.lock"
flock -n 9 || stop 'autre operation V2 en cours.'
RUN=$(mktemp -d "$STATE/internal-users-plan-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
install -d -m 0700 "$RUN/inputs" "$RUN/inputs/lib"
for file in "${FILES[@]}" SHA256SUMS; do
    install -o root -g root -m 0600 "$HERE/$file" "$RUN/inputs/$file"
done
[ "$(sha256sum "$RUN/inputs/SHA256SUMS" | cut -d' ' -f1)" = "$2" ] || stop 'manifeste change pendant la copie privee.'
(cd "$RUN/inputs" && sha256sum -c SHA256SUMS)
SOURCE_SHA=$(cat "$RUN/inputs/SOURCE_SHA256")
[[ "$SOURCE_SHA" =~ ^[a-f0-9]{64}$ ]] || stop 'empreinte source invalide.'
printf 'Lecture seule des comptes Cofrac2, comptes desactives inclus. Aucun service modifie.\n'
if ! php8.4 -d memory_limit=512M "$RUN/inputs/plan-techcalendar-internal-users.php" \
    --plan "$RUN/inputs/references.json.gz" "$SOURCE_SHA" "$RUN/report.json" 2>"$RUN/check.stderr"; then
    tail -n 5 "$RUN/check.stderr" >&2
    printf 'Diagnostic prive : %s\n' "$RUN" >&2
    exit 1
fi
gzip -c "$RUN/report.json" > "$RUN/report.json.gz"
gzip -t "$RUN/report.json.gz"
REPORT_SHA=$(sha256sum "$RUN/report.json.gz" | cut -d' ' -f1)
EXPORT=$(mktemp -d /home/ubuntu/cofrac2-internal-users-report-XXXXXXXX)
install -o ubuntu -g ubuntu -m 0600 "$RUN/report.json.gz" "$EXPORT/report.json.gz"
printf '%s  report.json.gz\n' "$REPORT_SHA" > "$EXPORT/SHA256SUMS"
chown ubuntu:ubuntu "$EXPORT/SHA256SUMS" "$EXPORT"
chmod 0600 "$EXPORT/SHA256SUMS"
chmod 0700 "$EXPORT"
printf 'REPORT_DIR=%s\nREPORT_SHA256=%s\n' "$EXPORT" "$REPORT_SHA"
printf 'Controle uniquement. Aucun compte cree ou modifie ; aucune fusion encore appliquee.\n'
