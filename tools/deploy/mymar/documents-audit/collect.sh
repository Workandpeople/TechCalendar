#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
STAGE=preflight
failure() {
    result=$?
    if [ "$result" != 0 ]; then
        printf 'AUDIT_FAILED_STAGE=%s EXIT_CODE=%s\n' "$STAGE" "$result" >&2
        if [ -n "${RUN:-}" ]; then printf 'Diagnostic prive : %s\n' "$RUN" >&2; fi
    fi
}
trap failure EXIT
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || stop 'sudo sur ns365601 uniquement, sans argument.'
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"
sha256sum -c SHA256SUMS
[ -d /var/lib/cofrac2-production ] && [ ! -L /var/lib/cofrac2-production ] \
    && [ "$(stat -c %u:%a /var/lib/cofrac2-production)" = 0:700 ] || stop 'repertoire prive inattendu.'
for command in php8.4 timeout nice mysql install sha256sum flock; do command -v "$command" >/dev/null || stop "outil manquant : $command"; done
exec 9>/var/lib/cofrac2-production/mymar-readonly-audit.lock
flock -n 9 || stop 'une collecte MYMAR est deja en cours.'
RUN=$(mktemp -d /var/lib/cofrac2-production/mymar-documents-audit-XXXXXXXX)
install -d -m 0700 "$RUN/bundle"
for file in MymarReadOnlyAudit.php MymarWorkflowAudit.php MymarDocumentAudit.php inspect.php collect.sh fetch-audit.sh README.txt SHA256SUMS; do
    [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'fichier absent ou indirect.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done
(cd "$RUN/bundle" && sha256sum -c SHA256SUMS)
BASE="$RUN/bundle"
printf 'Audit documentaire : code masque, schema et catalogue des statuts uniquement. Aucun document ni dossier lu.\n'
STAGE=source_prepare
printf 'AUDIT_STAGE=%s\n' "$STAGE"
timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M "$BASE/inspect.php" prepare "$RUN"
read_sql() {
    local stage="$1"
    STAGE="${stage}_sql"
    printf 'AUDIT_STAGE=%s\n' "$STAGE"
    if ! timeout --signal=TERM --kill-after=3 20 nice -n 15 mysql \
        --defaults-file=/etc/mysql/debian.cnf --protocol=socket --socket=/var/run/mysqld/mysqld.sock \
        --connect-timeout=3 --skip-reconnect --default-character-set=utf8mb4 --batch --raw --skip-column-names \
        < "$RUN/$stage.sql" > "$RUN/$stage.partial" 2> "$RUN/$stage.stderr.private"; then
        printf 'Diagnostic prive : %s\n' "$RUN"
        stop 'lecture bornee incomplete ; aucune activation.'
    fi
    mv "$RUN/$stage.partial" "$RUN/$stage.jsonl"
}
read_sql metadata
STAGE=metadata_validation
printf 'AUDIT_STAGE=%s\n' "$STAGE"
timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M "$BASE/inspect.php" metadata "$RUN"
read_sql status
STAGE=final_validation
printf 'AUDIT_STAGE=%s\n' "$STAGE"
timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M "$BASE/inspect.php" finish "$RUN"
STAGE=private_report_copy
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-documents-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"
chmod 0700 "$REPORT"
for file in source-facts.json.gz schema.json status-catalogue.json summary.json; do
    install -o ubuntu -g ubuntu -m 0600 "$RUN/$file" "$REPORT/$file"
done
cd "$REPORT"
sha256sum source-facts.json.gz schema.json status-catalogue.json summary.json > SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS
chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Aucun fichier client lu, aucun droit documentaire ouvert, aucun statut modifie. Applications et services inchanges.\n'
