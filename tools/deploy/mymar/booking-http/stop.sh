#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || exit 1
BASE=$(cd "$(dirname "$0")" && pwd -P)
RUN=$(dirname "$BASE")
[[ "$RUN" =~ ^/var/lib/cofrac2-production/mymar-booking-http-[a-zA-Z0-9]+$ ]] && [ "$(stat -c %u:%a "$RUN")" = 0:700 ] || exit 1
cd "$BASE"; sha256sum -c SHA256SUMS
exec 8>/var/lib/cofrac2-production/two-lots-release.lock; flock -n 8 || exit 1
exec 9>/var/lib/cofrac2-production/mymar-readonly-audit.lock; flock -n 9 || exit 1
STOPPED=1
systemctl disable --now cofrac-mymar-booking-http.service cofrac-mymar-booking-fpm.service || STOPPED=0
timeout --kill-after=3 30 php8.4 "$BASE/control.php" --disable "$RUN"
[ "$STOPPED" = 1 ] || { echo 'STOP : lecteur SQL verrouille, arret des deux nouveaux services a verifier.' >&2; exit 1; }
echo 'Lecteur MAR arrete et verrouille. Site et API d occupations inchanges.'
