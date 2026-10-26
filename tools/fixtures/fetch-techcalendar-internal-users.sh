#!/usr/bin/env bash

# Run on the Mac with the packaged, self-contained read-only collector.
set +x
set -Eeuo pipefail
umask 077
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
REMOTE=root@10.100.0.124
COLLECTOR="$HERE/collect-techcalendar-internal-users.sh"
for command in ssh scp shasum gzip mktemp awk; do
    command -v "$command" >/dev/null || { echo "Outil manquant : $command" >&2; exit 1; }
done
[ -f "$COLLECTOR" ] && [ ! -L "$COLLECTOR" ] && [ -f "$HERE/SHA256SUMS" ] \
    || { echo 'Utiliser le paquet de collecte prive complet.' >&2; exit 1; }
(cd "$HERE" && shasum -a 256 -c SHA256SUMS)
DEST=$(mktemp -d "$HOME/Downloads/techcalendar-internal-users-XXXXXXXX")
printf 'Collecte actualisee du CT TechCalendar, sans modification SQL ou applicative.\n'
if ! ssh -T -o ConnectTimeout=10 "$REMOTE" 'bash -s' < "$COLLECTOR" | tee "$DEST/collection.log"; then
    printf 'Collecte echouee. Aucun import effectue. Diagnostic local : %s\n' "$DEST" >&2
    exit 1
fi
DIRECTORY=$(awk -F= '/^EXPORT_DIR=/{print $2}' "$DEST/collection.log")
SHA=$(awk -F= '/^SHA256=/{print $2}' "$DEST/collection.log")
[[ "$DIRECTORY" =~ ^/tmp/techcalendar-references-[a-f0-9]{16}$ ]] && [[ "$SHA" =~ ^[a-f0-9]{64}$ ]] \
    || { echo 'Chemin ou empreinte de collecte inattendu. Ne pas utiliser cet export.' >&2; exit 1; }
scp -o ConnectTimeout=10 "$REMOTE:$DIRECTORY/references.json.gz" "$REMOTE:$DIRECTORY/SHA256SUMS" "$DEST/"
chmod 0600 "$DEST"/*
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
[ "$(shasum -a 256 "$DEST/references.json.gz" | awk '{print $1}')" = "$SHA" ]
gzip -t "$DEST/references.json.gz"
printf 'LOCAL_DIR=%s\nSHA256=%s\n' "$DEST" "$SHA"
printf 'Collecte privee telechargee et verifiee. Aucun mot de passe collecte, aucun import ni envoi effectue.\n'
