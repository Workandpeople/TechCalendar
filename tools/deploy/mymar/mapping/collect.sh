#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || stop 'sudo sur ns365601 uniquement.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"
sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
for command in php8.4 timeout nice mysql install sha256sum flock; do command -v "$command" >/dev/null || stop "outil absent : $command"; done
exec 8>"$STATE/two-lots-release.lock"
flock -n 8 || stop 'un deploiement est en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"
flock -n 9 || stop 'une preparation MYMAR est deja en cours.'
RUN=$(mktemp -d "$STATE/mymar-mapping-XXXXXXXX")
STAGE=copy
trap 'code=$?; printf "STAGE=%s EXIT_CODE=%s\nDiagnostic prive : %s\nAucun compte, service ou planning modifie.\n" "$STAGE" "$code" "$RUN" >&2; exit "$code"' ERR
install -d -m 0700 "$RUN/bundle"
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] \
        && [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'entree de paquet inattendue.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done < SHA256SUMS
install -o root -g root -m 0600 SHA256SUMS "$RUN/bundle/SHA256SUMS"
BASE="$RUN/bundle"
(cd "$BASE" && sha256sum -c SHA256SUMS)
STAGE=prepare
timeout --kill-after=3 30 nice -n 15 php8.4 -d memory_limit=128M "$BASE/prepare-mapping.php" prepare "$RUN"
STAGE=readonly_sql
timeout --signal=TERM --kill-after=3 20 nice -n 15 mysql \
    --defaults-file=/etc/mysql/debian.cnf --protocol=socket --socket=/var/run/mysqld/mysqld.sock \
    --connect-timeout=3 --skip-reconnect --default-character-set=utf8mb4 --batch --raw --skip-column-names \
    < "$RUN/validation.sql" > "$RUN/validation.partial" 2> "$RUN/sql-private.stderr"
mv "$RUN/validation.partial" "$RUN/validation.jsonl"
STAGE=validate
timeout --kill-after=3 15 nice -n 15 php8.4 -d memory_limit=128M "$BASE/prepare-mapping.php" finish "$RUN"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-mapping-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"
chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/mapping-summary.json" "$REPORT/mapping-summary.json"
cd "$REPORT"
sha256sum mapping-summary.json > SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS
chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Correspondances preparees hors application. Aucune fusion, reactivation, modification de droits ou de mot de passe.\n'
