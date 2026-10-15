#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Restore untrusted SQL only in a disposable, offline MySQL, never in the app DB.
SOURCE=${1:?Usage: bash prepare-coffrac-snapshot.sh dump.sql.gz NEW_OUTPUT_DIRECTORY}
DEST=${2:?Output directory required}
test -f "$SOURCE"
test ! -e "$DEST" || { echo 'Output directory must not exist.' >&2; exit 1; }
docker image inspect mysql:8.4 >/dev/null
gzip -t "$SOURCE"
mkdir -m 700 "$DEST"
CONTAINER="techcalendar-coffrac-snapshot-$(date +%s)-$$"
cleanup() { docker rm -fv "$CONTAINER" >/dev/null 2>&1 || true; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker run -d --name "$CONTAINER" --network none --memory=2g --cpus=2 \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=coffrac_snapshot \
    -e MYSQL_USER=snapshot -e MYSQL_PASSWORD=offline-snapshot-only \
    mysql:8.4 --local-infile=0 >/dev/null
ready=false
for _ in $(seq 1 90); do
    if docker exec "$CONTAINER" mysqladmin ping -uroot --silent >/dev/null 2>&1; then ready=true; break; fi
    sleep 2
done
test "$ready" = true || { echo 'Isolated MySQL did not start.' >&2; exit 1; }
mysql_snapshot() {
    docker exec -i -e MYSQL_PWD=offline-snapshot-only "$CONTAINER" \
        mysql --binary-mode=1 --local-infile=0 --default-character-set=utf8mb4 \
        --batch --raw --skip-column-names -usnapshot coffrac_snapshot "$@"
}

echo 'Restoring the local dump in isolated MySQL...'
gzip -dc "$SOURCE" | mysql_snapshot

TABLES='clients dossier_comment dossier_document dossier_fiche_facturation dossier_fiche_rdv dossier_fiches dossier_historique dossier_letige dossiers entreprise fiche_document fiche_type lot_dossier mail_templates media planings rdv_extern sms_tempalte status user_type users'
for table in $TABLES; do
    # No auth tables; exclude credential columns even from business/user tables.
    expression=$(mysql_snapshot -e "SET SESSION group_concat_max_len=100000;
        SELECT GROUP_CONCAT(CONCAT(QUOTE(COLUMN_NAME), ',', CHAR(96), COLUMN_NAME, CHAR(96)) ORDER BY ORDINAL_POSITION SEPARATOR ',')
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'
        AND COLUMN_NAME NOT IN ('password','remember_token','api_token','access_token');" < /dev/null)
    test -n "$expression" && test "$expression" != NULL
    if [ "$table" = mail_templates ]; then
        # Some legacy utf32 templates contain invalid Unicode; preserve exact bytes.
        expression="'id',id,'subject_base64',TO_BASE64(CAST(subject AS BINARY)),'subject_charset',CHARSET(subject),'text_base64',TO_BASE64(CAST(text AS BINARY)),'text_charset',CHARSET(text),'created_at',created_at,'updated_at',updated_at"
    fi
    mysql_snapshot -e "SET SESSION time_zone='+00:00'; SELECT JSON_OBJECT($expression) FROM $table ORDER BY id;" < /dev/null > "$DEST/$table.jsonl"
    printf '%s: %s\n' "$table" "$(wc -l < "$DEST/$table.jsonl" | tr -d ' ')"
done

php "$(dirname "$0")/seal-coffrac-snapshot.php" "$SOURCE" "$DEST"
