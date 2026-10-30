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
RUN=$(mktemp -d /var/lib/cofrac2-production/mymar-calibration-XXXXXXXX)
install -d -m 0700 "$RUN/bundle"
for file in MymarReadOnlyAudit.php MymarTimeCalibration.php calibrate.php collect.sh fetch-audit.sh README.txt SHA256SUMS; do
    [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'fichier absent ou indirect.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done
(cd "$RUN/bundle" && sha256sum -c SHA256SUMS)
BASE="$RUN/bundle"
printf 'Calibration en lecture seule : champs de duree/date et seul dossier MYMAR 91.\n'
timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M "$BASE/calibrate.php" prepare "$RUN"
mysql_read() {
    timeout --signal=TERM --kill-after=3 20 nice -n 15 mysql \
        --defaults-file=/etc/mysql/debian.cnf --protocol=socket --socket=/var/run/mysqld/mysqld.sock \
        --connect-timeout=3 --skip-reconnect --default-character-set=utf8mb4 --batch --raw --skip-column-names < "$1"
}
step() { printf '%s\t%s\n' "$1" "$2" >> "$RUN/steps.tsv"; printf 'SQL_STAGE=%s EXIT_CODE=%s\n' "$1" "$2"; }
if [ -f "$RUN/metadata.sql" ] && [ -r /etc/mysql/debian.cnf ]; then
    if mysql_read "$RUN/metadata.sql" > "$RUN/metadata.jsonl" 2> "$RUN/metadata.stderr.private"; then
        step metadata_sql 0
        if timeout --kill-after=3 15 nice -n 15 php8.4 -d memory_limit=128M "$BASE/calibrate.php" metadata "$RUN" > "$RUN/metadata-validation.private.log" 2>&1; then
            step metadata_validation 0
            if mysql_read "$RUN/reference.sql" > "$RUN/reference.partial" 2> "$RUN/reference.stderr.private"; then
                step reference_sql 0
                mv "$RUN/reference.partial" "$RUN/reference.jsonl"
            else step reference_sql "$?"; fi
        else step metadata_validation "$?"; fi
    else step metadata_sql "$?"; fi
fi
timeout --kill-after=3 15 nice -n 15 php8.4 -d memory_limit=128M "$BASE/calibrate.php" finish "$RUN"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-calibration-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"
chmod 0700 "$REPORT"
for file in source-facts.json.gz summary.json reference.json; do
    if [ -f "$RUN/$file" ]; then install -o ubuntu -g ubuntu -m 0600 "$RUN/$file" "$REPORT/$file"; fi
done
cd "$REPORT"
sha256sum source-facts.json.gz summary.json > SHA256SUMS
[ ! -f reference.json ] || sha256sum reference.json >> SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS
chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Aucun beneficiaire, adresse, email, secret ou document exporte. Applications, bases et services inchanges.\n'
printf 'Aucun appel API, envoi, liaison de compte ou changement du planning.\n'
