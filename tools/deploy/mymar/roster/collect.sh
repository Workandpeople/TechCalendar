#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
[ "$EUID" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || exit 1
BASE=$(cd "$(dirname "$0")" && pwd -P)
cd "$BASE"; sha256sum -c SHA256SUMS
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || exit 1
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || { echo 'STOP : deploiement en cours.' >&2; exit 1; }
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || { echo 'STOP : audit MYMAR en cours.' >&2; exit 1; }
RUN=$(mktemp -d "$STATE/mymar-roster-XXXXXXXX")
install -d -m 0700 "$RUN/bundle"
while read -r sha file; do
    [[ "$sha" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] \
        && [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || exit 1
    install -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done < SHA256SUMS
install -m 0600 SHA256SUMS "$RUN/bundle/SHA256SUMS"
(cd "$RUN/bundle" && sha256sum -c SHA256SUMS)
printf 'Rapprochement global des techniciens, SQL borne READ ONLY. Aucune activation.\n'
timeout --kill-after=3 45 nice -n 15 php8.4 -d memory_limit=128M -d zend.exception_ignore_args=1 \
    "$RUN/bundle/collect.php" "$RUN" 2> "$RUN/diagnostic-private.stderr" || {
    tail -n 1 "$RUN/diagnostic-private.stderr"; printf 'Diagnostic prive : %s\n' "$RUN"; exit 1;
}
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-roster-report-XXXXXXXX)
install -m 0600 "$RUN/report.json" "$REPORT/report.json"
printf 'PREPARATION_DIR=%s\n' "$RUN" > "$REPORT/preparation.txt"
(cd "$REPORT" && sha256sum report.json preparation.txt > SHA256SUMS && chmod 0600 preparation.txt SHA256SUMS)
chown ubuntu:ubuntu "$REPORT/report.json" "$REPORT/preparation.txt" "$REPORT/SHA256SUMS" "$REPORT"
printf 'REPORT_DIR=%s\nAucun code, compte, mot de passe, habilitation, planning ou service modifie. MYMAR inchange.\n' "$REPORT"
