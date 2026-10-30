#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 0 ] || exit 1
BASE=$(cd "$(dirname "$0")" && pwd -P); RUN=$(dirname "$BASE")
[[ "$RUN" =~ ^/var/lib/cofrac2-production/mymar-cache-pilot-[a-zA-Z0-9]+$ ]] && [ "$(stat -c %u:%a "$RUN")" = 0:700 ] || exit 1
cd "$BASE"; sha256sum -c SETUP-SHA256SUMS
exec 8>/var/lib/cofrac2-production/two-lots-release.lock; flock -n 8 || exit 1
exec 9>/var/lib/cofrac2-production/mymar-readonly-audit.lock; flock -n 9 || exit 1
STOPPED=1
systemctl disable --now cofrac2-mymar-observation.timer cofrac2-mymar-observation.service || STOPPED=0
php8.4 "$BASE/pilot-control.php" --disable "$RUN"
[ "$STOPPED" = 1 ] || exit 1
echo 'Pilote MYMAR masque et actualisation arretee. Aucun RDV ou document supprime ; API privee inchangee.'
