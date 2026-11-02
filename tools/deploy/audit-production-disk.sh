#!/usr/bin/env bash

# Metadata only. No application bootstrap, file contents, purge or service action.
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
if [ "$EUID" != 0 ] || [ "$(hostname -s)" != ns365601 ] || [ "$#" != 1 ] || [ "$1" != --audit ]; then
    printf 'Usage sur ns365601 : sudo bash audit-production-disk.sh --audit\n' >&2
    exit 1
fi

STATE=/var/lib/cofrac2-production
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(realpath "$STATE")" = "$STATE" ] \
    && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || { echo 'STOP : dossier prive inattendu.' >&2; exit 1; }
id ubuntu >/dev/null
for tool in timeout nice ionice du find sort awk sha256sum flock; do
    command -v "$tool" >/dev/null || { printf 'STOP : outil absent : %s\n' "$tool" >&2; exit 1; }
done
exec 9>"$STATE/disk-audit.lock"
flock -n 9 || { echo 'STOP : un audit disque est deja en cours.' >&2; exit 1; }
RUN=$(mktemp -d "$STATE/disk-audit-XXXXXXXX")
START=$SECONDS
BUDGET=600
printf 'Audit disque prive : %s\nAucune suppression, purge, maintenance ou modification applicative.\n' "$RUN"
printf 'section\texit_code\tseconds\n' > "$RUN/sections.tsv"
{
    printf 'AUDIT DISQUE - METADONNEES UNIQUEMENT\n'
    date -u '+Date UTC : %Y-%m-%dT%H:%M:%SZ'
    printf 'Budget maximal : %s secondes. Une section interrompue reste partielle.\n' "$BUDGET"
    printf 'Tailles du : octets alloues, sans suivre les liens ni traverser un autre filesystem.\n'
    printf 'Les totaux de plusieurs sections se recouvrent : NE PAS les additionner.\n'
    printf 'Aucun contenu de document, log, .env ou dump SQL n est collecte.\n'
} > "$RUN/audit.txt"

probe() {
    local name=$1 limit=$2 remaining elapsed result
    shift 2
    remaining=$((BUDGET - SECONDS + START))
    if [ "$remaining" -le 0 ]; then
        printf '%s\tbudget_exhausted\t0\n' "$name" >> "$RUN/sections.tsv"
        return
    fi
    [ "$limit" -le "$remaining" ] || limit=$remaining
    printf 'SCAN=%s (limite %ss, faible priorite)\n' "$name" "$limit"
    printf '\n=== %s ===\n' "$name" >> "$RUN/audit.txt"
    elapsed=$SECONDS
    set +e
    timeout --kill-after=2 "$limit" nice -n 19 ionice -c 3 "$@" 9>&- 2>&1 \
        | awk 'NR <= 250 { print } END { if (NR > 250) print "[sortie limitee a 250 lignes]" }' >> "$RUN/audit.txt"
    result=${PIPESTATUS[0]}
    set -e
    printf '%s\t%s\t%s\n' "$name" "$result" "$((SECONDS - elapsed))" >> "$RUN/sections.tsv"
    printf 'RESULT=%s CODE=%s\n' "$name" "$result"
}

sizes() {
    local name=$1 depth=$2 path=$3 limit=$4
    if [ -d "$path" ] && [ ! -L "$path" ]; then
        probe "$name" "$limit" bash -o pipefail -c 'du -x -B1 --max-depth="$1" -- "$2" | sort -nr' bash "$depth" "$path"
    fi
}

probe CAPACITE 15 df -hT / /srv /home/ubuntu/www /var/www/cofrac2 /var/lib/mysql
probe INODES 15 df -ih / /srv /var/www/cofrac2
probe MONTAGES 15 findmnt -rn -o TARGET,SOURCE,FSTYPE
probe JOURNAL_SYSTEME 20 journalctl --disk-usage
if command -v lsof >/dev/null; then
    probe FICHIERS_SUPPRIMES_ENCORE_OUVERTS 40 bash -o pipefail -c \
        'lsof -nP +L1 | awk '\''NR == 1 || ($7 ~ /^[0-9]+$/ && $7 >= 1048576) { print }'\'''
fi
if command -v mysql >/dev/null && [ -r /etc/mysql/debian.cnf ]; then
    unset MYSQL_PWD
    MYSQL=(mysql --defaults-file=/etc/mysql/debian.cnf --protocol=socket --connect-timeout=5 --skip-reconnect --batch --raw)
    probe TAILLES_SQL_ESTIMEES 20 "${MYSQL[@]}" --execute="
SET SESSION max_execution_time=2000;
SET SESSION lock_wait_timeout=2;
START TRANSACTION READ ONLY;
SELECT TABLE_SCHEMA AS database_name, COUNT(*) AS tables_count,
       SUM(COALESCE(DATA_LENGTH,0)+COALESCE(INDEX_LENGTH,0)) AS estimated_bytes
FROM information_schema.TABLES WHERE TABLE_TYPE='BASE TABLE'
GROUP BY TABLE_SCHEMA ORDER BY estimated_bytes DESC;
SELECT TABLE_SCHEMA, TABLE_NAME, COALESCE(DATA_LENGTH,0)+COALESCE(INDEX_LENGTH,0) AS estimated_bytes
FROM information_schema.TABLES WHERE TABLE_TYPE='BASE TABLE'
ORDER BY estimated_bytes DESC LIMIT 30;
SHOW VARIABLES WHERE Variable_name IN ('datadir','log_bin','binlog_expire_logs_seconds','innodb_file_per_table');
ROLLBACK;"
    # Aggregate the binary-log list; never purge files or read their contents.
    probe VOLUME_BINLOGS_SQL 20 bash -o pipefail -c \
        '"$@" --skip-column-names --execute="SHOW BINARY LOGS" | awk '\''{ count++; bytes += $2 } END { printf "binlog_files=%d\nbinlog_bytes=%.0f\n", count, bytes }'\''' bash "${MYSQL[@]}"
fi

sizes RACINE 1 / 180
sizes SITES_HISTORIQUES 2 /home/ubuntu/www 90
sizes SRV_DONT_ARCHIVE_ET_RUNTIME_V2 2 /srv 60
sizes BIBLIOTHEQUES_DONT_MYSQL_DOCKER_ET_PREPARATIONS 1 /var/lib 60
sizes SAUVEGARDES_ET_DEPLOIEMENTS_V2 2 "$STATE" 45
sizes APPLICATIONS_VAR_WWW 2 /var/www 45
sizes DOSSIER_OPERATEUR_DONT_PAQUETS 1 /home/ubuntu 45
sizes LOGS 2 /var/log 30
sizes CACHES_SYSTEME 2 /var/cache 20
sizes SAUVEGARDES_SYSTEME 2 /var/backups 20
sizes SAUVEGARDES_BACKUP 2 /backup 20
sizes SAUVEGARDES_BACKUPS 2 /backups 20
sizes TEMPORAIRES 1 /tmp 20
sizes TEMPORAIRES_PERSISTANTS 1 /var/tmp 20
sizes OPT 2 /opt 20

probe GROS_FICHIERS_TECHNIQUES 60 bash -o pipefail -c '
find /var/lib/cofrac2-production /var/log /var/backups /var/tmp /home/ubuntu \
    -xdev -type f -size +512M -printf "%s\t%b\t%TY-%Tm-%Td\t%p\n" | sort -nr
'
if command -v docker >/dev/null && [ -S /var/run/docker.sock ]; then
    probe VOLUMES_DOCKER 25 docker system df
fi
if command -v snap >/dev/null; then
    probe REVISIONS_SNAP 15 snap list --all
fi
probe RACINES_APACHE_ACTIVES 15 bash -o pipefail -c \
    'grep -RHE "^[[:space:]]*(ServerName|ServerAlias|DocumentRoot|Alias)[[:space:]]" /etc/apache2/sites-enabled || [ "$?" = 1 ]'
probe CAPACITE_FINALE 10 df -hT /

{
    printf '\n=== LIMITES ET SUITE ===\n'
    printf 'Temps ecoule : %s secondes. Consulter sections.tsv : code 124/137 = interruption, autre code non nul = mesure indisponible/partielle.\n' "$((SECONDS - START))"
    printf 'Les tailles SQL sont estimees ; les fichiers MySQL peuvent etre plus grands.\n'
    printf 'Gros fichiers : taille logique, blocs alloues de 512 octets, date, chemin. Un fichier creux peut occuper moins.\n'
    printf 'Ne pas supprimer de document, binlog, ibdata/ibd, volume Docker ou sauvegarde sur la seule base de sa taille.\n'
    printf 'Les sauvegardes recentes et preuves de migration doivent rester disponibles.\n'
    printf 'Aucune duplication ni lecture des contenus des 232 Go de documents. Aucun service ou compte modifie.\n'
} >> "$RUN/audit.txt"
REPORT=$(mktemp -d /home/ubuntu/cofrac2-disk-report-XXXXXXXX)
for file in audit.txt sections.tsv; do
    install -m 0600 "$RUN/$file" "$REPORT/$file"
done
(cd "$REPORT" && sha256sum audit.txt sections.tsv > SHA256SUMS && chmod 0600 SHA256SUMS)
chown ubuntu:ubuntu "$REPORT/audit.txt" "$REPORT/sections.tsv" "$REPORT/SHA256SUMS" "$REPORT"
printf 'REPORT_DIR=%s\nAudit termine, aucune suppression. Les mesures partielles sont signalees dans sections.tsv.\n' "$REPORT"
