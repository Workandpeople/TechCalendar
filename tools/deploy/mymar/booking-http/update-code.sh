#!/usr/bin/env bash
# Only replaces code of the already-installed SELECT-only loopback reader.
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 2 ] \
    && [ "$1" = --apply ] && [[ "$2" =~ ^[a-f0-9]{64}$ ]] || stop 'sudo sur ns365601 avec --apply SHA256_DU_MANIFEST.'
SOURCE=$(cd "$(dirname "$0")" && pwd -P)
STATE=/var/lib/cofrac2-production
[ "$(realpath "$STATE")" = "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
exec 8>"$STATE/two-lots-release.lock"; flock -n 8 || stop 'deploiement en cours.'
exec 9>"$STATE/mymar-readonly-audit.lock"; flock -n 9 || stop 'operation MYMAR en cours.'
RUN=$(mktemp -d "$STATE/mymar-reader-update-XXXXXXXX")
install -d -m 0700 "$RUN/bundle"
cp -a --no-preserve=ownership "$SOURCE/." "$RUN/bundle/"
[ -z "$(find "$RUN/bundle" ! -type d ! -type f -print -quit)" ] || stop 'paquet indirect refuse.'
find "$RUN/bundle" -type d -exec chmod 0700 {} +
find "$RUN/bundle" -type f -exec chmod 0600 {} +
cd "$RUN/bundle"
[ "$(sha256sum SHA256SUMS | cut -d' ' -f1)" = "$2" ] || stop 'empreinte du manifeste differente.'
sha256sum -c SHA256SUMS
php8.4 ./update-code.php "$RUN"
