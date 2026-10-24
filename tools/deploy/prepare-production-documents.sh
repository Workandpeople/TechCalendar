#!/usr/bin/env bash

# Independent private copy of every business document. No SQL, application bootstrap or service operation.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$(hostname -s)" != ns365601 ]; then
    stop 'execution root uniquement sur ns365601.'
fi
[ "$#" -ge 1 ] && [ "$#" -le 2 ] || stop 'Usage : --inventory-all-documents | --copy-all-documents INVENTORY | --verify-all-documents INVENTORY | --repair-copy-all-documents INVENTORY'
ACTION=$1
case "$ACTION:$#" in
    --inventory-all-documents:1|--copy-all-documents:2|--verify-all-documents:2|--repair-copy-all-documents:2) ;;
    *) stop 'arguments inattendus.' ;;
esac
STATE=/var/lib/cofrac2-production
SOURCE=/home/ubuntu/www/coffrac_preprod
ARCHIVE=/srv/cofrac2-document-archive
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
HELPER="$HERE/document-archive.php"
for executable in php8.4 rsync nice ionice flock realpath stat df awk mktemp; do
    command -v "$executable" >/dev/null || stop "outil absent : $executable"
done
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(realpath "$STATE")" = "$STATE" ] \
    && [ "$(stat -c %u "$STATE")" = 0 ] && [ "$(stat -c %a "$STATE")" = 700 ] \
    || stop 'dossier prive de preparation inattendu.'
[ -f "$STATE/SCHEMA-READY.json" ] && [ ! -L "$STATE/SCHEMA-READY.json" ] || stop 'schema V2 non prepare.'
[ -d "$SOURCE" ] && [ ! -L "$SOURCE" ] && [ "$(realpath "$SOURCE")" = "$SOURCE" ] || stop 'source Coffrac inattendue.'
[ -d /srv ] && [ ! -L /srv ] && [ "$(stat -c %u /srv)" = 0 ] || stop 'parent archive inattendu.'
exec 9>"$STATE/documents-operation.lock"
flock -n 9 || stop 'une operation documentaire V2 est deja en cours.'
copy_pid=
watch_pid=
cleanup() {
    if [ -n "$watch_pid" ]; then kill -TERM "$watch_pid" 2>/dev/null || true; wait "$watch_pid" 2>/dev/null || true; fi
    if [ -n "$copy_pid" ]; then kill -TERM "$copy_pid" 2>/dev/null || true; wait "$copy_pid" 2>/dev/null || true; fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "$ACTION" = --inventory-all-documents ]; then
    INVENTORY=$(mktemp -d "$STATE/documents-inventory-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
    # The helper requires an absent output and creates it itself in 0700.
    rmdir "$INVENTORY"
    printf 'Inventaire complet des six racines, sans filtre de statut et sans lecture SQL.\n'
    nice -n 10 ionice -c 2 -n 7 php8.4 -d memory_limit=512M "$HELPER" inventory "$SOURCE" "$INVENTORY"
    printf 'INVENTORY=%s\n' "$INVENTORY"
    printf 'Inventaire uniquement : aucun document copie, aucun service modifie.\n'
    exit 0
fi
INVENTORY=$2
case "$INVENTORY" in "$STATE"/documents-inventory-*) ;; *) stop 'dossier d inventaire inattendu.' ;; esac
[ -d "$INVENTORY" ] && [ ! -L "$INVENTORY" ] && [ "$(realpath "$INVENTORY")" = "$INVENTORY" ] || stop 'inventaire absent ou lien symbolique.'
# Refuse an inventory from another application even if its private checksums are internally valid.
php8.4 -d memory_limit=512M -r 'require $argv[1]; $s=\Cofrac2\Deploy\CoffracDocumentArchive::validate($argv[2]); if ($s["source"] !== $argv[3]) exit(1);' \
    "$HERE/lib/CoffracDocumentArchive.php" "$INVENTORY" "$SOURCE" || stop 'source d inventaire inattendue.'

RUN=$(mktemp -d "$STATE/documents-run-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
printf 'Diagnostic prive : %s\n' "$RUN"
if [ "$ACTION" = --copy-all-documents ] || [ "$ACTION" = --repair-copy-all-documents ]; then
    checksum=()
    if [ "$ACTION" = --repair-copy-all-documents ]; then
        checksum=(--checksum)
        printf 'Reparation par comparaison de contenus : une lecture complete supplementaire est necessaire.\n'
    fi
    nice -n 10 ionice -c 2 -n 7 php8.4 -d memory_limit=512M "$HELPER" prepare-copy "$INVENTORY" "$ARCHIVE" > "$RUN/copy-plan.json"
    cat "$RUN/copy-plan.json"
    printf 'Copie privee, limitee a 32 Mio/s, sans suppression ni hardlink. Reprenable en relancant le meme inventaire.\n'
    printf 'La reserve libre de 50 Gio est surveillee pendant le transfert.\n'
    nice -n 10 ionice -c 2 -n 7 rsync -dtp \
        --relative --from0 --files-from="$INVENTORY/files.list0" \
        --chmod='Du=rwx,Dgo=,Fu=rw,Fgo=' \
        --no-links --no-devices --no-specials --no-owner --no-group --no-acls --no-xattrs --no-hard-links \
        --no-whole-file --partial-dir=.rsync-partial --fsync \
        --bwlimit=32768 --info=progress2,name0 --stats "${checksum[@]}" \
        "$SOURCE/" "$ARCHIVE/files/" 2> "$RUN/rsync.stderr" 9>&- &
    copy_pid=$!
    (
        exec 9>&-
        while kill -0 "$copy_pid" 2>/dev/null; do
            if ! available=$(df -Pk "$ARCHIVE" | awk 'END {print $4}'); then available=invalid; fi
            if ! [[ "$available" =~ ^[0-9]+$ ]] || [ "$available" -lt 52428800 ]; then
                printf 'reserve libre franchie ou mesure impossible\n' > "$RUN/SPACE-STOPPED.txt" || true
                kill -TERM "$copy_pid" 2>/dev/null || true
                exit 1
            fi
            sleep 5
        done
    ) &
    watch_pid=$!
    status=0
    wait "$copy_pid" || status=$?
    copy_pid=
    kill -TERM "$watch_pid" 2>/dev/null || true
    wait "$watch_pid" 2>/dev/null || true
    watch_pid=
    [ "$status" -eq 0 ] && [ ! -e "$RUN/SPACE-STOPPED.txt" ] || stop "copie interrompue (code $status). Reprendre apres examen du diagnostic prive."
    # A successful transfer is not a content verification and never enables document access.
    printf '{"rsync_exit_code":0,"content_checksums_verified":false,"final_cutover_ready":false}\n' > "$RUN/COPY-FINISHED.json"
    printf 'Copie terminee. Lancer ensuite --verify-all-documents avec le meme inventaire.\n'
    printf 'Aucun acces applicatif donne aux fichiers, aucune modification de Coffrac original.\n'
else
    rmdir "$RUN"
    printf 'Verification SHA-256 de chaque fichier source ET copie ; lecture de plusieurs centaines de Go.\n'
    nice -n 10 ionice -c 2 -n 7 php8.4 -d memory_limit=512M "$HELPER" verify-copy "$INVENTORY" "$ARCHIVE" "$RUN"
    printf 'Contenus et independance de la copie verifies. Rapport prive : %s/report.json\n' "$RUN"
    printf 'Rapprochement DB et passage differentiel final encore requis. Aucun service ou .env modifie.\n'
fi
