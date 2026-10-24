#!/usr/bin/env bash

# Local-only inspection: restore the dump in disposable offline MySQL, never in V2.
set -Eeuo pipefail
umask 077
export LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "$#" -eq 2 ] || stop 'Usage : bash audit-production-coffrac.sh SOURCE_COLLECTION NEW_OUTPUT_DIRECTORY'
[ "$(hostname -s)" != ns365601 ] || stop 'outil local refuse sur le serveur de production.'
for executable in docker php gzip; do command -v "$executable" >/dev/null || stop "outil manquant : $executable"; done
HERE=$(cd -- "$(dirname -- "$0")" && pwd)
SOURCE=$(cd -- "$1" && pwd -P)
OUTPUT=$2
[ ! -e "$OUTPUT" ] && [ ! -L "$OUTPUT" ] || stop 'le dossier de sortie existe deja.'
docker image inspect mysql:8.0.46 >/dev/null
REFERENCE_DATE=$(php "$HERE/seal-production-audit.php" --reference-date "$SOURCE")
[[ "$REFERENCE_DATE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || stop 'date de reference invalide.'
gzip -t "$SOURCE/coffrac.sql.gz"
mkdir -m 0700 -- "$OUTPUT"
OUTPUT=$(cd -- "$OUTPUT" && pwd -P)
CONTAINER="cofrac2-offline-audit-$(date +%s)-$$"
created=0
success=0
cleanup() {
    if [ "$created" = 1 ]; then docker rm -fv "$CONTAINER" >/dev/null 2>&1 || true; fi
    if [ "$success" != 1 ]; then
        printf 'Audit incomplet ; ne pas utiliser les fichiers partiels. Diagnostic prive : %s\n' "$OUTPUT" >&2
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker run -d --name "$CONTAINER" --network none --memory=2g --cpus=2 --pids-limit=128 \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=fiche_crm \
    mysql:8.0.46 --event-scheduler=OFF --local-infile=0 --secure-file-priv=NULL --skip-log-bin >/dev/null
created=1
ready=0
for attempt in $(seq 1 90); do
    # The bootstrap server accepts socket connections before MYSQL_DATABASE exists.
    # TCP becomes available only when the final server is ready.
    if docker exec "$CONTAINER" mysql --protocol=tcp -h127.0.0.1 -uroot \
        -NBe 'SELECT 1' fiche_crm >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[ "$ready" = 1 ] || stop 'MySQL jetable indisponible.'
mysql_offline() {
    docker exec -i "$CONTAINER" mysql --protocol=socket --binary-mode=1 --local-infile=0 \
        --default-character-set=utf8mb4 --batch --raw --skip-column-names -uroot fiche_crm "$@"
}
printf 'Restauration en MySQL 8.0.46 jetable : aucun reseau, port publie ou fichier hote monte.\n'
gzip -dc "$SOURCE/coffrac.sql.gz" | mysql_offline > "$OUTPUT/restore.stdout" 2> "$OUTPUT/restore.stderr"
[ "$(mysql_offline -e 'SELECT @@event_scheduler, @@local_infile, @@secure_file_priv;' < /dev/null)" = $'OFF\t0\tNULL' ] \
    || stop 'options de securite SQL modifiees dans la copie.'
{
    printf "SET @reference_date='%s';\n" "$REFERENCE_DATE"
    cat "$HERE/audit-coffrac-production.sql"
} | mysql_offline > "$OUTPUT/audit.jsonl.partial" 2> "$OUTPUT/audit.stderr"
php "$HERE/seal-production-audit.php" --seal "$SOURCE" "$OUTPUT/audit.jsonl.partial" "$OUTPUT/report.json.partial"
mv -- "$OUTPUT/audit.jsonl.partial" "$OUTPUT/audit.jsonl"
mv -- "$OUTPUT/report.json.partial" "$OUTPUT/report.json"
success=1
printf 'Rapport agrege prive : %s/report.json\n' "$OUTPUT"
printf 'Hashes, jetons et coordonnees personnelles non inclus dans ce rapport. Aucun import V2.\n'
