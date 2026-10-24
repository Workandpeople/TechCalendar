#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" -eq 0 ] && [ "$(hostname -s)" = ns365601 ] || stop 'execution root uniquement sur ns365601.'
[ "$#" -eq 3 ] || stop 'Usage : INVENTORY_DIRECTORY VERIFICATION_DIRECTORY RECONCILIATION_DIRECTORY'
STATE=/var/lib/cofrac2-production
ARCHIVE=/srv/cofrac2-document-archive
SOURCE=/home/ubuntu/www/coffrac_preprod
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(realpath "$STATE")" = "$STATE" ] \
    && [ "$(stat -c %u "$STATE")" = 0 ] && [ "$(stat -c %a "$STATE")" = 700 ] || stop 'preparation privee inattendue.'
[ -f "$STATE/SCHEMA-READY.json" ] && [ ! -L "$STATE/SCHEMA-READY.json" ] || stop 'schema V2 non prepare.'
for input in "$1" "$2" "$3"; do
    case "$input" in "$STATE"/*) ;; *) stop 'entree hors preparation privee.' ;; esac
    [ -d "$input" ] && [ ! -L "$input" ] && [ "$(realpath "$input")" = "$input" ] || stop 'entree absente ou symbolique.'
done
case "$1" in "$STATE"/documents-inventory-*) ;; *) stop 'inventaire inattendu.' ;; esac
case "$2" in "$STATE"/documents-run-*) ;; *) stop 'verification inattendue.' ;; esac
case "$3" in "$STATE"/documents-reconciliation-*) ;; *) stop 'rapprochement inattendu.' ;; esac
FILES=(review-production-document-issues.sh review-document-issues.php lib/CoffracDocumentArchive.php lib/CoffracDocumentIssueReview.php README.txt)
cd "$HERE"
[ -f SHA256SUMS ] && [ ! -L SHA256SUMS ] || stop 'paquet non scelle.'
[ "$(wc -l < SHA256SUMS)" -eq "${#FILES[@]}" ] || stop 'contenu du paquet inattendu.'
for file in "${FILES[@]}"; do
    [ -f "$file" ] && [ ! -L "$file" ] && [ "$(realpath "$file")" = "$HERE/$file" ] || stop 'fichier absent ou symbolique.'
    grep -Fxq -- "$(sha256sum -- "$file")" SHA256SUMS || stop 'contenu du paquet modifie.'
done
sha256sum -c SHA256SUMS
STAGED=$(mktemp -d "$STATE/documents-review-tools-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
install -d -o root -g root -m 0700 "$STAGED/lib"
for file in "${FILES[@]}" SHA256SUMS; do
    install -o root -g root -m 0600 "$HERE/$file" "$STAGED/$file"
done
cd "$STAGED"
sha256sum -c SHA256SUMS
exec 9>"$STATE/documents-operation.lock"
flock -n 9 || stop 'une operation documentaire V2 est deja en cours.'
php8.4 -d memory_limit=512M -r 'require $argv[1]; $s=\Cofrac2\Deploy\CoffracDocumentArchive::validate($argv[2]); if ($s["source"] !== $argv[3]) exit(1);' \
    "$STAGED/lib/CoffracDocumentArchive.php" "$1" "$SOURCE" || stop 'source documentaire inattendue.'
OUTPUT=$(mktemp -d "$STATE/documents-issue-review-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
rmdir "$OUTPUT"
nice -n 10 ionice -c 2 -n 7 php8.4 -d memory_limit=512M "$STAGED/review-document-issues.php" \
    "$1" "$2" "$ARCHIVE" "$3" "$OUTPUT"
printf 'Rapport prive : %s/report.json\n' "$OUTPUT"
printf 'Le detail par reference reste prive dans issues-review.jsonl. Aucun acces ouvert.\n'
