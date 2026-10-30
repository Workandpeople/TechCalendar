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
for command in php8.4 timeout nice install sha256sum flock; do command -v "$command" >/dev/null || stop "outil manquant : $command"; done
exec 9>/var/lib/cofrac2-production/mymar-readonly-audit.lock
flock -n 9 || stop 'une collecte MYMAR est deja en cours.'
RUN=$(mktemp -d /var/lib/cofrac2-production/mymar-cache-diagnostic-XXXXXXXX)
install -d -m 0700 "$RUN/bundle"
for file in MymarReadOnlyAudit.php diagnose.php collect.sh fetch-audit.sh README.txt SHA256SUMS; do
    [ -f "$BASE/$file" ] && [ ! -L "$BASE/$file" ] || stop 'fichier absent ou indirect.'
    install -o root -g root -m 0600 "$BASE/$file" "$RUN/bundle/$file"
done
(cd "$RUN/bundle" && sha256sum -c SHA256SUMS)
printf 'Diagnostic du format du cache MYMAR uniquement. Aucun SQL ni bootstrap.\n'
timeout --kill-after=3 20 nice -n 15 php8.4 -d memory_limit=128M "$RUN/bundle/diagnose.php" "$RUN"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-mymar-cache-report-XXXXXXXX)
chown ubuntu:ubuntu "$REPORT"
chmod 0700 "$REPORT"
install -o ubuntu -g ubuntu -m 0600 "$RUN/cache-diagnostic.json" "$REPORT/cache-diagnostic.json"
cd "$REPORT"
sha256sum cache-diagnostic.json > SHA256SUMS
chown ubuntu:ubuntu SHA256SUMS
chmod 0600 SHA256SUMS
printf 'REPORT_DIR=%s\n' "$REPORT"
printf 'Aucune valeur de configuration exportee. Applications, bases et services inchanges.\n'
