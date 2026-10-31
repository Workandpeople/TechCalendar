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
[ -d /var/lib/cofrac2-production ] && [ ! -L /var/lib/cofrac2-production ] \
    && [ "$(stat -c %u:%a /var/lib/cofrac2-production)" = 0:700 ] || stop 'repertoire prive inattendu.'
for command in php8.4 timeout nice mysql install sha256sum flock; do command -v "$command" >/dev/null || stop "outil manquant : $command"; done
exec 9>/var/lib/cofrac2-production/mymar-readonly-audit.lock
flock -n 9 || stop 'une collecte MYMAR est deja en cours.'
RUN=$(mktemp -d /var/lib/cofrac2-production/mymar-workflow-XXXXXXXX)
install -d -m 0700 "$RUN/bundle"
for file in MymarReadOnlyAudit.php MymarWorkflowAudit.php inspect.php collect.sh fetch-workflow.sh README.txt SHA256SUMS; do
    [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'fichier absent ou indirect.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done
(cd "$RUN/bundle" && sha256sum -c SHA256SUMS)
BASE="$RUN/bundle"
printf 'Verification statique des workflows de placement/probleme MYMAR. Aucune route appelee.\n'
timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M "$BASE/inspect.php" prepare "$RUN"
if ! timeout --signal=TERM --kill-after=3 20 nice -n 15 mysql \
    --defaults-file=/etc/mysql/debian.cnf --protocol=socket --socket=/var/run/mysqld/mysqld.sock \
    --connect-timeout=3 --skip-reconnect --default-character-set=utf8mb4 --batch --raw --skip-column-names \
    < "$RUN/metadata.sql" > "$RUN/metadata.partial" 2> "$RUN/metadata.stderr.private"; then
    printf 'Diagnostic prive : %s\n' "$RUN"
    stop 'lecture des metadonnees incomplete ; aucune activation.'
fi
mv "$RUN/metadata.partial" "$RUN/metadata.jsonl"
timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M "$BASE/inspect.php" finish "$RUN"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-workflow-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"
chmod 0700 "$REPORT"
for file in source-facts.json.gz schema.json summary.json; do
    install -o ubuntu -g ubuntu -m 0600 "$RUN/$file" "$REPORT/$file"
done
cd "$REPORT"
sha256sum source-facts.json.gz schema.json summary.json > SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS
chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Applications, passerelle de lecture et services inchanges. Aucun placement, mail ou SMS.\n'
