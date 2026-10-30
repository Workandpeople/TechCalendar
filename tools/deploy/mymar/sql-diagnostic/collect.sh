#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || stop 'sudo sur ns365601 uniquement, sans argument.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"
sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'repertoire prive inattendu.'
exec 9>"$STATE/mymar-readonly-audit.lock"
flock -n 9 || stop 'une collecte MYMAR est en cours.'
RUN=$(mktemp -d "$STATE/mymar-sql-diagnostic-XXXXXXXX")
install -d -m 0700 "$RUN/bundle"
for file in SqlAuditDiagnostic.php diagnose.php collect.sh fetch-audit.sh TARGET_SOURCE_SHA256 README.txt SHA256SUMS; do
    [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'fichier absent ou indirect.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done
(cd "$RUN/bundle" && sha256sum -c SHA256SUMS)
HASH=$(cat "$RUN/bundle/TARGET_SOURCE_SHA256")
[[ "$HASH" =~ ^[a-f0-9]{64}$ ]] || stop 'empreinte de la collecte invalide.'
printf 'Relecture des diagnostics existants uniquement, sans nouvelle requete SQL.\n'
timeout --kill-after=3 20 nice -n 15 php8.4 -d memory_limit=128M "$RUN/bundle/diagnose.php" "$RUN" "$HASH"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-sql-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"
chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/sql-diagnostic.json" "$REPORT/sql-diagnostic.json"
cd "$REPORT"
sha256sum sql-diagnostic.json > SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS
chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Aucune donnee metier, erreur brute ou valeur de configuration exportee. Aucun service modifie.\n'
