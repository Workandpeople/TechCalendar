#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "$#" -eq 2 ] || stop 'Usage : bash prepare-dataset.sh SOURCE_COLLECTION NEW_DATASET_DIRECTORY'
[ "$(hostname -s)" != ns365601 ] || stop 'extraction locale uniquement, jamais sur Coffrac.'
HERE=$(cd -- "$(dirname -- "$0")" && pwd)
SOURCE=$(cd -- "$1" && pwd -P)
OUTPUT=$2
[ ! -e "$OUTPUT" ] && [ ! -L "$OUTPUT" ] || stop 'sortie deja presente.'
for executable in docker php gzip; do command -v "$executable" >/dev/null || stop "outil absent : $executable"; done
docker image inspect mysql:8.0.46 >/dev/null
REFERENCE_DATE=$(php "$HERE/../seal-production-audit.php" --reference-date "$SOURCE")
gzip -t "$SOURCE/coffrac.sql.gz"
mkdir -m 0700 -- "$OUTPUT"
OUTPUT=$(cd -- "$OUTPUT" && pwd -P)
CONTAINER="cofrac2-offline-extract-$(date +%s)-$$"
created=0
cleanup() { if [ "$created" = 1 ]; then docker rm -fv "$CONTAINER" >/dev/null 2>&1 || true; fi; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# Untrusted SQL has no network, published ports, host mounts or working FILE capability.
docker run -d --name "$CONTAINER" --network none --memory=2g --cpus=2 --pids-limit=128 \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=fiche_crm \
    mysql:8.0.46 --event-scheduler=OFF --local-infile=0 --secure-file-priv=NULL --skip-log-bin >/dev/null
created=1
ready=0
for attempt in $(seq 1 90); do
    if docker exec "$CONTAINER" mysql --protocol=tcp -h127.0.0.1 -uroot -NBe 'SELECT 1' fiche_crm >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[ "$ready" = 1 ] || stop 'MySQL jetable indisponible.'
mysql_offline() {
    docker exec -i "$CONTAINER" mysql --binary-mode=1 --local-infile=0 --default-character-set=utf8mb4 \
        --batch --raw --skip-column-names -uroot fiche_crm "$@" 2>> "$OUTPUT/extraction.stderr"
}
printf 'Extraction locale en MySQL jetable hors reseau. Diagnostics prives, jamais de hashes dans la sortie.\n'
gzip -dc "$SOURCE/coffrac.sql.gz" | mysql_offline > "$OUTPUT/restore.stdout"
[ "$(mysql_offline -e 'SELECT @@event_scheduler, @@local_infile, @@secure_file_priv;' < /dev/null)" = $'OFF\t0\tNULL' ] || stop 'securite SQL alteree.'
{ printf "SET @reference_date='%s';\n" "$REFERENCE_DATE"; cat "$HERE/../audit-coffrac-production.sql"; } \
    | mysql_offline > "$OUTPUT/audit.jsonl"
php "$HERE/../seal-production-audit.php" --seal "$SOURCE" "$OUTPUT/audit.jsonl" "$OUTPUT/audit-report.json"
TABLES='clients dossier_comment dossier_document dossier_fiche_facturation dossier_fiche_rdv dossier_fiches dossier_historique dossier_letige dossiers entreprise fiche_document fiche_type lot_dossier mail_templates media planings rdv_extern sms_tempalte status user_type users'
for table in $TABLES; do
    # Preserve legacy utf32 as exact bytes; exclude all credential-like columns.
    expression=$(mysql_offline -e "SET SESSION group_concat_max_len=100000;
        SELECT GROUP_CONCAT(IF(CHARACTER_SET_NAME='utf32',
            CONCAT(QUOTE(CONCAT(COLUMN_NAME,'_base64')),',TO_BASE64(CAST(',CHAR(96),REPLACE(COLUMN_NAME,CHAR(96),CONCAT(CHAR(96),CHAR(96))),CHAR(96),' AS BINARY)),',QUOTE(CONCAT(COLUMN_NAME,'_charset')),',',QUOTE(CHARACTER_SET_NAME)),
            CONCAT(QUOTE(COLUMN_NAME),',',CHAR(96),REPLACE(COLUMN_NAME,CHAR(96),CONCAT(CHAR(96),CHAR(96))),CHAR(96)))
            ORDER BY ORDINAL_POSITION SEPARATOR ',')
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'
            AND COLUMN_NAME NOT REGEXP '(password|token|secret|api_key)';" < /dev/null)
    [ -n "$expression" ] && [ "$expression" != NULL ] || stop "schema source absent : $table"
    mysql_offline -e "SET SESSION time_zone='+00:00'; SELECT JSON_OBJECT($expression) FROM $table ORDER BY id;" < /dev/null > "$OUTPUT/$table.jsonl"
    printf '%s : %s lignes\n' "$table" "$(wc -l < "$OUTPUT/$table.jsonl" | tr -d ' ')"
done
mysql_offline -e "SELECT JSON_OBJECT('id',id,'password_hash',password) FROM users ORDER BY id;" < /dev/null > "$OUTPUT/credentials.jsonl"
php "$HERE/seal-dataset.php" "$SOURCE" "$OUTPUT"
printf 'Collecte native privee prete pour repetition locale : %s\n' "$OUTPUT"
printf 'Ne pas publier ce dossier : donnees personnelles et hashes de comptes. Aucune base V2 modifiee.\n'
