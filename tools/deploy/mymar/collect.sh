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
    && [ "$(stat -c %u:%a /var/lib/cofrac2-production)" = 0:700 ] || stop 'repertoire prive de preparation inattendu.'
for command in php8.4 timeout nice mysql install sha256sum flock; do command -v "$command" >/dev/null || stop "outil manquant : $command"; done
exec 9>/var/lib/cofrac2-production/mymar-readonly-audit.lock
flock -n 9 || stop 'une collecte MYMAR est deja en cours.'
RUN=$(mktemp -d /var/lib/cofrac2-production/mymar-readonly-XXXXXXXX)
install -d -m 0700 "$RUN/bundle"
for file in MymarReadOnlyAudit.php collect.php collect.sh fetch-audit.sh README.txt SHA256SUMS; do
    [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'fichier du paquet absent ou indirect.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done
(cd "$RUN/bundle" && sha256sum -c SHA256SUMS)
BASE="$RUN/bundle"
printf 'Audit cible en lecture seule, sans bootstrap Laravel ni appel API.\n'
timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M "$BASE/collect.php" prepare "$RUN"
mysql_read() {
    timeout --signal=TERM --kill-after=3 20 nice -n 15 mysql \
        --defaults-file=/etc/mysql/debian.cnf --protocol=socket --socket=/var/run/mysqld/mysqld.sock \
        --connect-timeout=3 --skip-reconnect --default-character-set=utf8mb4 --batch --raw --skip-column-names < "$1"
}
record_step() {
    printf '%s\t%s\n' "$1" "$2" >> "$RUN/sql-stages.tsv"
    printf 'SQL_STAGE=%s EXIT_CODE=%s\n' "$1" "$2"
}
if [ -f "$RUN/metadata.sql" ] && [ -r /etc/mysql/debian.cnf ]; then
    if mysql_read "$RUN/metadata.sql" > "$RUN/metadata.jsonl" 2> "$RUN/metadata-private.stderr"; then
        record_step metadata_sql 0
        if timeout --kill-after=3 15 nice -n 15 php8.4 -d memory_limit=128M "$BASE/collect.php" metadata "$RUN" > "$RUN/metadata-validation.log" 2>&1; then
            record_step metadata_validation 0
            if mysql_read "$RUN/data.sql" > "$RUN/database.partial" 2> "$RUN/database-private.stderr"; then
                record_step data_sql 0
                mv "$RUN/database.partial" "$RUN/database.jsonl"
                # Plain EXPLAIN only: no EXPLAIN ANALYZE and no execution of the proposed integration query.
                if mysql_read "$RUN/explain.sql" > "$RUN/explain.tsv" 2> "$RUN/explain-private.stderr"; then
                    record_step explain_sql 0
                else
                    record_step explain_sql "$?"
                fi
            else
                record_step data_sql "$?"
            fi
        else
            record_step metadata_validation "$?"
        fi
    else
        record_step metadata_sql "$?"
    fi
fi
timeout --kill-after=3 15 nice -n 15 php8.4 -d memory_limit=128M "$BASE/collect.php" finish "$RUN"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-readonly-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"
chmod 0700 "$REPORT"
for file in source-facts.json.gz summary.json database-audit.json.gz; do
    if [ -f "$RUN/$file" ]; then install -o ubuntu -g ubuntu -m 0600 "$RUN/$file" "$REPORT/$file"; fi
done
if [ -s "$RUN/explain.tsv" ] && [ ! -s "$RUN/explain-private.stderr" ]; then
    install -o ubuntu -g ubuntu -m 0600 "$RUN/explain.tsv" "$REPORT/explain.tsv"
fi
cd "$REPORT"
sha256sum source-facts.json.gz summary.json > SHA256SUMS
[ ! -f database-audit.json.gz ] || sha256sum database-audit.json.gz >> SHA256SUMS
[ ! -f explain.tsv ] || sha256sum explain.tsv >> SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS
chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Aucune application, base, configuration ou service modifie. Aucun compte lie automatiquement.\n'
printf 'Les requetes et erreurs SQL restent privees root sur le serveur. Aucun envoi ni route API appelee.\n'
