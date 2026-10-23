#!/usr/bin/env bash

# Read-only source collection. Never restore SQL or bootstrap either application.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin

if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$#" -ne 1 ] \
    || [ "${1:-}" != '--export-current-coffrac' ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Usage sur ns365601 : sudo bash collect-production-coffrac.sh --export-current-coffrac\n' >&2
    exit 1
fi

STATE=/var/lib/cofrac2-production
SOURCE=fiche_crm
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
for executable in mysql mysqldump nice ionice gzip sha256sum flock stat df awk; do
    command -v "$executable" >/dev/null || stop "outil manquant : $executable"
done
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u "$STATE")" = 0 ] \
    && [ "$(stat -c %a "$STATE")" = 700 ] || stop 'dossier prive de preparation inattendu.'
[ -f "$STATE/SCHEMA-READY.json" ] || stop 'schema V2 non valide.'
[ -r /etc/mysql/debian.cnf ] || stop 'configuration de maintenance MySQL indisponible.'
exec 9>"$STATE/source-export.lock"
flock -n 9 || stop 'un export Coffrac est deja en cours.'
unset MYSQL_PWD
MYSQL=(mysql --defaults-file=/etc/mysql/debian.cnf --protocol=socket --connect-timeout=5 --batch --raw --skip-column-names)
scalar() { "${MYSQL[@]}" --execute="$1"; }

[ "$(scalar 'SELECT CURRENT_USER(), @@port;')" = $'debian-sys-maint@localhost\t3306' ] \
    || stop 'compte ou serveur SQL inattendu.'
required=$(scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SOURCE' AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME IN ('users','clients','dossiers','dossier_fiches','dossier_document','dossier_fiche_rdv','status','fiche_type');")
[ "$required" = 8 ] || stop 'base source ou tables Coffrac attendues absentes.'
non_transactional=$(scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SOURCE' AND TABLE_TYPE='BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB');")
[ "$non_transactional" = 0 ] || stop 'tables non InnoDB : export sans verrouillage non valide, examiner avant de continuer.'
[ "$(df -Pk "$STATE" | awk 'END {print $4}')" -ge 2097152 ] || stop 'moins de 2 Gio libres, examiner avant export.'

DIRECTORY=$(mktemp -d "$STATE/coffrac-source-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
success=0
cleanup() {
    if [ "$success" != 1 ]; then
        printf 'Export incomplet : ne pas utiliser les fichiers. Diagnostic prive conserve : %s\n' "$DIRECTORY" >&2
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

printf 'Collecte privee : %s\n' "$DIRECTORY"
printf 'Source : %s, tables InnoDB verifiees. Aucun verrouillage explicite de tables.\n' "$SOURCE"
printf 'Ne pas modifier la structure SQL de Coffrac pendant cet export.\n'
scalar 'SELECT @@hostname, @@version, @@port, CURRENT_USER();' > "$DIRECTORY/server.tsv"
{
    printf 'table_name\ttable_type\tengine\trows_estimated\tbytes_estimated\n'
    scalar "SELECT TABLE_NAME, TABLE_TYPE, COALESCE(ENGINE,''), COALESCE(TABLE_ROWS,0), COALESCE(DATA_LENGTH,0)+COALESCE(INDEX_LENGTH,0) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SOURCE' ORDER BY TABLE_NAME;"
} > "$DIRECTORY/tables.tsv"
{
    printf 'table_name\tcolumn_name\tposition\tcolumn_type\tnullable\tcolumn_key\tcharset\tcollation\n'
    scalar "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COALESCE(CHARACTER_SET_NAME,''), COALESCE(COLLATION_NAME,'') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$SOURCE' ORDER BY TABLE_NAME, ORDINAL_POSITION;"
} > "$DIRECTORY/columns.tsv"

printf 'Export SQL transactionnel en cours ; le terminal reste occupe jusqu a la verification...\n'
nice -n 10 ionice -c 2 -n 7 mysqldump \
    --defaults-file=/etc/mysql/debian.cnf \
    --protocol=socket \
    --single-transaction --quick --no-tablespaces \
    --set-gtid-purged=OFF --column-statistics=0 \
    --default-character-set=utf8mb4 --hex-blob \
    --routines --events --triggers --skip-add-locks --skip-lock-tables \
    "$SOURCE" 2> "$DIRECTORY/mysqldump.stderr" \
    | nice -n 10 gzip -1 > "$DIRECTORY/coffrac.sql.gz.partial"
gzip -t "$DIRECTORY/coffrac.sql.gz.partial"
mv -- "$DIRECTORY/coffrac.sql.gz.partial" "$DIRECTORY/coffrac.sql.gz"
printf 'source_database=%s\ncollected_at_utc=%s\ncollection_type=online_snapshot\n' \
    "$SOURCE" "$(date -u +%FT%TZ)" > "$DIRECTORY/collection.txt"
cd "$DIRECTORY"
sha256sum coffrac.sql.gz server.tsv tables.tsv columns.tsv collection.txt mysqldump.stderr > SHA256SUMS
sha256sum -c SHA256SUMS
success=1
printf 'Export termine : %s\n' "$DIRECTORY"
printf 'Taille du dump compresse : %s octets.\n' "$(stat -c %s coffrac.sql.gz)"
printf 'Dossiers, comptes et historique dans le dump prive. Aucun import ni changement de service.\n'
printf 'Aucun document copie. Ne pas publier le dump ou les fichiers prives.\n'
