#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" -eq 0 ] && [ "$(hostname -s)" = ns365601 ] || stop 'execution root uniquement sur ns365601.'
[ "$#" -eq 2 ] || stop 'Usage : INVENTORY_DIRECTORY VERIFICATION_DIRECTORY'
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
STATE=/var/lib/cofrac2-production
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(realpath "$STATE")" = "$STATE" ] \
    && [ "$(stat -c %u "$STATE")" = 0 ] && [ "$(stat -c %a "$STATE")" = 700 ] || stop 'preparation privee inattendue.'
FILES=(stage-document-reconciliation.sh reconcile-production-documents.sh reconcile-documents.php
    lib/CoffracDocumentArchive.php lib/CoffracDocumentReconciliation.php
    references/references.jsonl references/summary.json references/SHA256SUMS README.txt)
cd "$HERE"
[ -f SHA256SUMS ] && [ ! -L SHA256SUMS ] || stop 'paquet non scelle.'
[ "$(wc -l < SHA256SUMS)" -eq "${#FILES[@]}" ] || stop 'contenu du paquet inattendu.'
for file in "${FILES[@]}"; do
    [ -f "$file" ] && [ ! -L "$file" ] && [ "$(realpath "$file")" = "$HERE/$file" ] || stop 'fichier absent ou symbolique.'
    grep -Fxq -- "$(sha256sum -- "$file")" SHA256SUMS || stop 'empreinte absente ou contenu modifie.'
done
sha256sum -c SHA256SUMS
STAGED=$(mktemp -d "$STATE/document-references-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
install -d -o root -g root -m 0700 "$STAGED/lib" "$STAGED/references"
for file in "${FILES[@]}" SHA256SUMS; do
    install -o root -g root -m 0600 "$HERE/$file" "$STAGED/$file"
done
cd "$STAGED"
sha256sum -c SHA256SUMS
printf 'Paquet prive installe : %s\n' "$STAGED"
bash "$STAGED/reconcile-production-documents.sh" "$STAGED/references" "$1" "$2"
