#!/usr/bin/env bash
# One-shot SQL rehearsal only; no installer, application command, account or service operation.
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
flock -n 8 || stop 'un deploiement est en cours ; reessayer apres sa fin.'
exec 9>"$STATE/mymar-readonly-audit.lock"
flock -n 9 || stop 'une lecture MYMAR est deja en cours.'
RUN=$(mktemp -d "$STATE/mymar-readonly-XXXXXXXX")
STAGE=copy
trap 'code=$?; printf "STAGE=%s EXIT_CODE=%s\nDiagnostic prive : %s\nAucun changement de planning, de compte ou de service.\n" "$STAGE" "$code" "$RUN" >&2; exit "$code"' ERR
install -d -m 0700 "$RUN/bundle"
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] \
        && [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'entree de paquet inattendue.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done < SHA256SUMS
install -o root -g root -m 0600 SHA256SUMS "$RUN/bundle/SHA256SUMS"
BASE="$RUN/bundle"
(cd "$BASE" && sha256sum -c SHA256SUMS)
php_step() {
    STAGE="${1%.php}:$2"
    timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M "$BASE/$1" "$2" "$RUN"
}
mysql_read() {
    STAGE="sql:$1"
    timeout --signal=TERM --kill-after=3 20 nice -n 15 mysql \
        --defaults-file=/etc/mysql/debian.cnf --protocol=socket --socket=/var/run/mysqld/mysqld.sock \
        --connect-timeout=3 --skip-reconnect --default-character-set=utf8mb4 --batch --raw --skip-column-names < "$RUN/$1.sql"
}
printf 'Observation ponctuelle de 7 jours, lecture seule ; aucun raccordement au planning.\n'
php_step observe-live.php preflight
php_step collect.php prepare
[ -f "$RUN/metadata.sql" ] || stop 'identite source non confirmee ; aucun SQL lance.'
mysql_read metadata > "$RUN/metadata.jsonl" 2> "$RUN/metadata-private.stderr"
php_step collect.php metadata > "$RUN/metadata-validation.log" 2>&1
mysql_read data > "$RUN/database.partial" 2> "$RUN/database-private.stderr"
mv "$RUN/database.partial" "$RUN/database.jsonl"
php_step collect.php finish > "$RUN/reference-finish.log" 2>&1
php_step observe-live.php prepare
mysql_read live-explain > "$RUN/live-explain.private.tsv" 2> "$RUN/live-explain-private.stderr"
mysql_read live-observation > "$RUN/live-observation.partial" 2> "$RUN/live-observation-private.stderr"
mv "$RUN/live-observation.partial" "$RUN/live-observation.jsonl"
php_step observe-live.php finish
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-live-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"
chmod 0700 "$REPORT"
for file in observation-summary.json technicians-review.json.gz occupations-observation.json.gz query-plan.json; do
    install -o ubuntu -g ubuntu -m 0600 "$RUN/$file" "$REPORT/$file"
done
cd "$REPORT"
sha256sum observation-summary.json technicians-review.json.gz occupations-observation.json.gz query-plan.json > SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS
chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Aucun compte SQL cree, aucun lien approuve, aucune application executee, aucun mail/SMS envoye.\n'
