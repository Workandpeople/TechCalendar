#!/usr/bin/env bash

set +x
set -Eeuo pipefail
umask 077
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
NAME=$(basename "$HERE")
[[ "$NAME" =~ ^cofrac2-internal-users-merge-[A-Za-z0-9]{8}$ ]] || { echo 'Paquet prive de fusion attendu.' >&2; exit 1; }
for command in scp ssh shasum awk; do
    command -v "$command" >/dev/null || { echo "Outil manquant : $command" >&2; exit 1; }
done
(cd "$HERE" && shasum -a 256 -c SHA256SUMS)
SHA=$(shasum -a 256 "$HERE/SHA256SUMS" | awk '{print $1}')
scp -rp -o ConnectTimeout=10 "$HERE" ubuntu@46.105.110.76:/home/ubuntu/
ssh -tt -o ConnectTimeout=10 ubuntu@46.105.110.76 \
    "sudo bash '/home/ubuntu/$NAME/merge-techcalendar-internal-users.sh' --apply '$SHA'"
