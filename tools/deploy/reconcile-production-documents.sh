#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" -eq 0 ] && [ "$(hostname -s)" = ns365601 ] || stop 'execution root uniquement sur ns365601.'
[ "$#" -eq 3 ] || stop 'Usage : REFERENCES_DIRECTORY INVENTORY_DIRECTORY VERIFICATION_DIRECTORY'
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
case "$2" in "$STATE"/documents-inventory-*) ;; *) stop 'inventaire inattendu.' ;; esac
case "$3" in "$STATE"/documents-run-*) ;; *) stop 'verification inattendue.' ;; esac
exec 9>"$STATE/documents-operation.lock"
flock -n 9 || stop 'une operation documentaire V2 est deja en cours.'
php8.4 -d memory_limit=512M -r 'require $argv[1]; $s=\Cofrac2\Deploy\CoffracDocumentArchive::validate($argv[2]); if ($s["source"] !== $argv[3]) exit(1);' \
    "$HERE/lib/CoffracDocumentArchive.php" "$2" "$SOURCE" || stop 'source documentaire inattendue.'
RUN=$(mktemp -d "$STATE/documents-reconciliation-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
rmdir "$RUN"
printf 'Rapprochement sans SQL et sans relecture du contenu des 232 Go.\n'
nice -n 10 ionice -c 2 -n 7 php8.4 -d memory_limit=512M "$HERE/reconcile-documents.php" \
    "$1" "$2" "$3" "$ARCHIVE" "$RUN"
printf 'Rapport prive : %s/report.json\n' "$RUN"
printf 'Les lignes de revue restent privees dans issues.jsonl. Aucun fichier supprime ni droit ouvert.\n'
